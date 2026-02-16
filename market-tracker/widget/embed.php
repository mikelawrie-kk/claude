<?php
/**
 * Filename: embed.php
 * Description: Public API endpoint for price comparison widget. Returns JSON with cached price data.
 * Version: 1.0.0
 * Created: 2026-02-16 12:00:00 SAST
 * Modified: 2026-02-16 12:00:00 SAST
 */

require_once __DIR__ . '/../config.php';

// CORS headers for allowed domains
$allowed_domains = explode(',', ALLOWED_WIDGET_DOMAINS);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

foreach ($allowed_domains as $domain) {
    $domain = trim($domain);
    if (strpos($origin, $domain) !== false) {
        header("Access-Control-Allow-Origin: $origin");
        break;
    }
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Rate limiting - 60 requests per minute per IP
$rate_limit_file = __DIR__ . '/../data/rate_limit_' . md5($_SERVER['REMOTE_ADDR']) . '.txt';
$current_time = time();

if (file_exists($rate_limit_file)) {
    $data = json_decode(file_get_contents($rate_limit_file), true);
    $requests = array_filter($data['requests'] ?? [], function($timestamp) use ($current_time) {
        return $timestamp > ($current_time - 60);
    });

    if (count($requests) >= 60) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Rate limit exceeded. Please try again later.'
        ]);
        exit;
    }

    $requests[] = $current_time;
    file_put_contents($rate_limit_file, json_encode(['requests' => $requests]));
} else {
    file_put_contents($rate_limit_file, json_encode(['requests' => [$current_time]]));
}

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get check-in date from query string or use +7 days
    $check_in = $_GET['check_in'] ?? date('Y-m-d', strtotime('+7 days'));
    $check_out = $_GET['check_out'] ?? date('Y-m-d', strtotime($check_in . ' +1 day'));

    // Validate dates
    if (!strtotime($check_in) || !strtotime($check_out)) {
        throw new Exception('Invalid date format');
    }

    // Get our property's direct price
    $stmt = $db->prepare("
        SELECT ps.price_zar, ps.scraped_at, p.name
        FROM price_snapshots ps
        JOIN properties p ON ps.property_id = p.id
        WHERE p.is_ours = 1
        AND ps.check_in = ?
        AND ps.ota_source = 'direct'
        ORDER BY ps.scraped_at DESC
        LIMIT 1
    ");
    $stmt->execute([$check_in]);
    $our_price_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$our_price_data) {
        // No direct price found, return empty
        echo json_encode([
            'success' => true,
            'show_widget' => false,
            'message' => 'No pricing data available for this date'
        ]);
        exit;
    }

    $our_price = $our_price_data['price_zar'];
    $property_name = $our_price_data['name'];
    $data_date = $our_price_data['scraped_at'];

    // Get OTA prices for the same dates
    $stmt = $db->prepare("
        SELECT ps.ota_source, ps.price_zar, ps.link
        FROM price_snapshots ps
        JOIN properties p ON ps.property_id = p.id
        WHERE p.is_ours = 1
        AND ps.check_in = ?
        AND ps.ota_source != 'direct'
        AND ps.scraped_at > datetime('now', '-24 hours')
        ORDER BY ps.price_zar ASC
    ");
    $stmt->execute([$check_in]);
    $ota_prices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Find the best competitor price
    $best_ota = null;
    $max_savings = 0;

    foreach ($ota_prices as $ota) {
        $savings = $ota['price_zar'] - $our_price;
        if ($savings > $max_savings) {
            $max_savings = $savings;
            $best_ota = $ota;
        }
    }

    // Only show widget if we're cheaper
    if (!$best_ota || $max_savings <= 0) {
        echo json_encode([
            'success' => true,
            'show_widget' => false,
            'message' => 'Direct price is not the lowest available'
        ]);
        exit;
    }

    // Return widget data
    echo json_encode([
        'success' => true,
        'show_widget' => true,
        'property_name' => $property_name,
        'check_in' => $check_in,
        'check_out' => $check_out,
        'direct_price' => [
            'amount' => $our_price,
            'currency' => 'ZAR',
            'formatted' => 'R' . number_format($our_price, 0)
        ],
        'ota_price' => [
            'source' => $best_ota['ota_source'],
            'amount' => $best_ota['price_zar'],
            'currency' => 'ZAR',
            'formatted' => 'R' . number_format($best_ota['price_zar'], 0),
            'link' => $best_ota['link']
        ],
        'savings' => [
            'amount' => $max_savings,
            'formatted' => 'R' . number_format($max_savings, 0),
            'percentage' => round(($max_savings / $best_ota['price_zar']) * 100, 1)
        ],
        'data_date' => date('Y-m-d', strtotime($data_date)),
        'disclaimer' => 'Prices as of ' . date('j M Y', strtotime($data_date)) . '. OTA prices may vary.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load pricing data'
    ]);
}
