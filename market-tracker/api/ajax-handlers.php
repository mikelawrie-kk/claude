<?php
/**
 * Filename: ajax-handlers.php
 * Description: AJAX request handlers for dashboard operations
 * Version: 1.0.0
 * Created: 2026-02-16 10:00:00 SAST
 * Modified: 2026-02-16 10:00:00 SAST
 */

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/dataforseo.php';
require_once __DIR__ . '/fetch-prices.php';

// Check authentication
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF token validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

$action = $_GET['action'] ?? '';
$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'get_dashboard_data':
            getDashboardData($db);
            break;

        case 'get_demand_calendar':
            getDemandCalendar($db);
            break;

        case 'get_competitors':
            getCompetitors($db);
            break;

        case 'add_competitor':
            addCompetitor($db);
            break;

        case 'remove_competitor':
            removeCompetitor($db);
            break;

        case 'toggle_competitor':
            toggleCompetitor($db);
            break;

        case 'get_price_matrix':
            getPriceMatrix($db);
            break;

        case 'get_trends_data':
            getTrendsData($db);
            break;

        case 'run_manual_audit':
            runManualAudit($db);
            break;

        case 'test_api':
            testApi();
            break;

        case 'update_settings':
            updateSettings($db);
            break;

        case 'get_settings':
            getSettings($db);
            break;

        case 'export_csv':
            exportCsv($db);
            break;

        case 'get_cron_logs':
            getCronLogs($db);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Get dashboard summary data
 */
function getDashboardData($db) {
    // Get our property position
    $stmt = $db->query("
        SELECT p.name, ps.position, ps.rating, ps.reviews_count, ps.scraped_at
        FROM position_snapshots ps
        JOIN properties p ON ps.property_id = p.id
        WHERE p.is_ours = 1
        ORDER BY ps.scraped_at DESC
        LIMIT 1
    ");
    $our_position = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get latest demand score
    $stmt = $db->query("
        SELECT * FROM demand_scores
        ORDER BY calculated_at DESC
        LIMIT 1
    ");
    $latest_demand = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get high demand dates count (next 30 days)
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM demand_scores
        WHERE check_date >= date('now')
        AND check_date <= date('now', '+30 days')
        AND demand_score >= 7
    ");
    $high_demand_count = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get price comparison
    $stmt = $db->query("
        SELECT
            AVG(CASE WHEN p.is_ours = 0 THEN ps.price_zar END) as avg_competitor_price,
            AVG(CASE WHEN p.is_ours = 1 THEN ps.price_zar END) as our_avg_price
        FROM price_snapshots ps
        JOIN properties p ON ps.property_id = p.id
        WHERE ps.scraped_at > datetime('now', '-7 days')
    ");
    $price_comparison = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get recent cron status
    $stmt = $db->query("
        SELECT * FROM cron_log
        ORDER BY started_at DESC
        LIMIT 1
    ");
    $last_cron = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'our_position' => $our_position,
            'latest_demand' => $latest_demand,
            'high_demand_count' => $high_demand_count['count'] ?? 0,
            'price_comparison' => $price_comparison,
            'last_cron' => $last_cron
        ]
    ]);
}

/**
 * Get demand calendar data (6 months)
 */
function getDemandCalendar($db) {
    $stmt = $db->query("
        SELECT check_date, demand_score, demand_signal, avg_market_price, our_price
        FROM demand_scores
        WHERE check_date >= date('now')
        AND check_date <= date('now', '+6 months')
        ORDER BY check_date
    ");

    $calendar_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $calendar_data
    ]);
}

/**
 * Get competitors list
 */
function getCompetitors($db) {
    $stmt = $db->query("
        SELECT id, name, hotel_identifier, is_ours, active, created_at
        FROM properties
        ORDER BY is_ours DESC, name
    ");

    $competitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $competitors
    ]);
}

/**
 * Add new competitor
 */
function addCompetitor($db) {
    $name = trim($_POST['name'] ?? '');

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Property name is required']);
        return;
    }

    // Check if already exists
    $stmt = $db->prepare("SELECT id FROM properties WHERE name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Property already exists']);
        return;
    }

    // Add property
    $stmt = $db->prepare("INSERT INTO properties (name, is_ours, active) VALUES (?, 0, 1)");
    $stmt->execute([$name]);

    // Try to find hotel_identifier
    $api = new DataForSEO(DATAFORSEO_USERNAME, DATAFORSEO_PASSWORD);
    $settings = getSettingsArray($db);

    $result = $api->hotelSearch(
        $settings['search_keyword'],
        $settings['location_name'],
        date('Y-m-d', strtotime('+7 days')),
        date('Y-m-d', strtotime('+8 days')),
        (int)$settings['default_adults'],
        $settings['currency'],
        $settings['language_name']
    );

    if ($result['success']) {
        foreach ($result['hotels'] as $hotel) {
            if (stripos($hotel['name'], $name) !== false) {
                $stmt = $db->prepare("UPDATE properties SET hotel_identifier = ? WHERE id = ?");
                $stmt->execute([$hotel['hotel_identifier'], $db->lastInsertId()]);
                break;
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Competitor added successfully']);
}

/**
 * Remove competitor
 */
function removeCompetitor($db) {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid property ID']);
        return;
    }

    // Don't allow removing "our" property
    $stmt = $db->prepare("SELECT is_ours FROM properties WHERE id = ?");
    $stmt->execute([$id]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($property && $property['is_ours'] == 1) {
        echo json_encode(['success' => false, 'error' => 'Cannot remove your own property']);
        return;
    }

    // Delete property and related data
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM price_snapshots WHERE property_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM position_snapshots WHERE property_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM properties WHERE id = ?")->execute([$id]);
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Competitor removed successfully']);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Toggle competitor active status
 */
function toggleCompetitor($db) {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid property ID']);
        return;
    }

    $stmt = $db->prepare("UPDATE properties SET active = 1 - active WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Competitor status updated']);
}

/**
 * Get price matrix data
 */
function getPriceMatrix($db) {
    $check_in = $_GET['check_in'] ?? date('Y-m-d', strtotime('+7 days'));

    $stmt = $db->prepare("
        SELECT
            p.name as property_name,
            p.is_ours,
            ps.ota_source,
            ps.price_zar,
            ps.link
        FROM properties p
        LEFT JOIN price_snapshots ps ON p.id = ps.property_id
            AND ps.check_in = ?
            AND ps.scraped_at > datetime('now', '-24 hours')
        WHERE p.active = 1
        ORDER BY p.is_ours DESC, p.name, ps.ota_source
    ");
    $stmt->execute([$check_in]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize by property and OTA
    $matrix = [];
    foreach ($results as $row) {
        $property = $row['property_name'];
        if (!isset($matrix[$property])) {
            $matrix[$property] = [
                'is_ours' => $row['is_ours'],
                'prices' => []
            ];
        }

        if ($row['ota_source']) {
            $matrix[$property]['prices'][$row['ota_source']] = [
                'price' => $row['price_zar'],
                'link' => $row['link']
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $matrix,
        'check_in' => $check_in
    ]);
}

/**
 * Get trends data for charts
 */
function getTrendsData($db) {
    $type = $_GET['type'] ?? 'position';
    $property_id = (int)($_GET['property_id'] ?? 0);

    if ($type === 'position') {
        $stmt = $db->prepare("
            SELECT DATE(scraped_at) as date, AVG(position) as avg_position
            FROM position_snapshots
            WHERE property_id = ?
            AND scraped_at > datetime('now', '-30 days')
            GROUP BY DATE(scraped_at)
            ORDER BY date
        ");
        $stmt->execute([$property_id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'price') {
        $stmt = $db->query("
            SELECT
                DATE(ps.scraped_at) as date,
                AVG(CASE WHEN p.is_ours = 0 THEN ps.price_zar END) as avg_market,
                AVG(CASE WHEN p.is_ours = 1 THEN ps.price_zar END) as our_price
            FROM price_snapshots ps
            JOIN properties p ON ps.property_id = p.id
            WHERE ps.scraped_at > datetime('now', '-30 days')
            GROUP BY DATE(ps.scraped_at)
            ORDER BY date
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'demand') {
        $stmt = $db->query("
            SELECT check_date as date, demand_score
            FROM demand_scores
            WHERE check_date >= date('now')
            AND check_date <= date('now', '+60 days')
            ORDER BY check_date
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $data = [];
    }

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
}

/**
 * Run manual audit
 */
function runManualAudit($db) {
    $check_in = $_POST['check_in'] ?? date('Y-m-d', strtotime('+7 days'));
    $check_out = $_POST['check_out'] ?? date('Y-m-d', strtotime('+8 days'));

    $fetcher = new PriceFetcher();
    $result = $fetcher->run([[
        'check_in' => $check_in,
        'check_out' => $check_out
    ]]);

    echo json_encode($result);
}

/**
 * Test API connection
 */
function testApi() {
    $api = new DataForSEO(DATAFORSEO_USERNAME, DATAFORSEO_PASSWORD);
    $result = $api->testConnection();

    echo json_encode($result);
}

/**
 * Update settings
 */
function updateSettings($db) {
    $allowed_keys = ['check_dates', 'months_ahead', 'search_keyword', 'location_name', 'default_adults'];

    $updates = [];
    foreach ($allowed_keys as $key) {
        if (isset($_POST[$key])) {
            $updates[$key] = $_POST[$key];
        }
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No settings to update']);
        return;
    }

    $stmt = $db->prepare("UPDATE settings SET value = ? WHERE key = ?");

    foreach ($updates as $key => $value) {
        $stmt->execute([$value, $key]);
    }

    echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
}

/**
 * Get settings
 */
function getSettings($db) {
    $settings = getSettingsArray($db);

    echo json_encode([
        'success' => true,
        'data' => $settings
    ]);
}

/**
 * Export data to CSV
 */
function exportCsv($db) {
    $type = $_GET['type'] ?? 'prices';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="market-tracker-' . $type . '-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    if ($type === 'prices') {
        fputcsv($output, ['Date', 'Property', 'Check-in', 'OTA Source', 'Price ZAR', 'Room Type']);

        $stmt = $db->query("
            SELECT ps.scraped_at, p.name, ps.check_in, ps.ota_source, ps.price_zar, ps.room_type
            FROM price_snapshots ps
            JOIN properties p ON ps.property_id = p.id
            ORDER BY ps.scraped_at DESC, p.name
            LIMIT 10000
        ");

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            fputcsv($output, $row);
        }
    } elseif ($type === 'demand') {
        fputcsv($output, ['Check Date', 'Demand Score', 'Signal', 'Avg Market Price', 'Our Price', 'Price Change %']);

        $stmt = $db->query("
            SELECT check_date, demand_score, demand_signal, avg_market_price, our_price, price_change_pct
            FROM demand_scores
            ORDER BY check_date
        ");

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            fputcsv($output, $row);
        }
    }

    fclose($output);
    exit;
}

/**
 * Get cron logs
 */
function getCronLogs($db) {
    $stmt = $db->query("
        SELECT * FROM cron_log
        ORDER BY started_at DESC
        LIMIT 50
    ");

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $logs
    ]);
}

/**
 * Helper: Get settings as associative array
 */
function getSettingsArray($db) {
    $stmt = $db->query("SELECT key, value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}
