<?php
/**
 * Filename: audits.php
 * Description: Entity audit browser with filters, score display, country aggregates, and CSV export
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

session_start();
if (!isset($_SESSION['admin_user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../templates/layout.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cost-tracker.php';

// ---------------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------------
$flashMessage = '';
$flashType    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'reaudit':
                $propId = (int) ($_POST['property_id'] ?? 0);
                if ($propId > 0) {
                    Database::insert('scrape_queue', [
                        'property_id' => $propId,
                        'queue_type'  => 'audit',
                        'status'      => 'pending',
                        'priority'    => 3,
                    ]);
                    $flashMessage = 'Property added to audit queue.';
                    $flashType    = 'success';
                }
                break;
        }
    } catch (\Throwable $e) {
        $flashMessage = 'Action failed: ' . $e->getMessage();
        $flashType    = 'error';
    }
}

// ---------------------------------------------------------------------------
// Handle CSV Export
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        // Build same filters as the main query
        $where  = [];
        $params = [];

        $scoreMin      = $_GET['score_min'] ?? '';
        $scoreMax      = $_GET['score_max'] ?? '';
        $filterCountry = $_GET['country'] ?? '';
        $filterType    = $_GET['type'] ?? '';

        if ($scoreMin !== '') {
            $where[]  = "pa.`total_score` >= ?";
            $params[] = (int) $scoreMin;
        }
        if ($scoreMax !== '') {
            $where[]  = "pa.`total_score` <= ?";
            $params[] = (int) $scoreMax;
        }
        if ($filterCountry !== '') {
            $where[]  = "p.`country_id` = ?";
            $params[] = (int) $filterCountry;
        }
        if ($filterType !== '') {
            $where[]  = "p.`property_type` = ?";
            $params[] = $filterType;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $exportRows = Database::fetchAll(
            "SELECT p.`name`, c.`name` AS country_name, p.`property_type`,
                    pa.`total_score`, pa.`schema_score`, pa.`entity_authority_score`,
                    pa.`ai_discoverability_score`, pa.`technical_score`, pa.`booking_authority_score`,
                    pa.`audited_at`, p.`website`
             FROM `property_audit` pa
             JOIN `properties` p ON p.`id` = pa.`property_id`
             LEFT JOIN `countries` c ON c.`id` = p.`country_id`
             {$whereSql}
             ORDER BY pa.`total_score` DESC",
            $params
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-export-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Property Name', 'Country', 'Type', 'Total Score', 'Schema', 'Entity Authority', 'AI Discoverability', 'Technical', 'Booking Authority', 'Audited At', 'Website']);

        foreach ($exportRows as $row) {
            fputcsv($output, [
                $row['name'],
                $row['country_name'] ?? '',
                $row['property_type'] ?? '',
                $row['total_score'],
                $row['schema_score'],
                $row['entity_authority_score'],
                $row['ai_discoverability_score'],
                $row['technical_score'],
                $row['booking_authority_score'],
                $row['audited_at'],
                $row['website'] ?? '',
            ]);
        }

        fclose($output);
        exit;
    } catch (\Throwable $e) {
        // If export fails, fall through to normal page load
        $flashMessage = 'CSV export failed: ' . $e->getMessage();
        $flashType    = 'error';
    }
}

// ---------------------------------------------------------------------------
// Filter and Sorting
// ---------------------------------------------------------------------------
$scoreMin      = $_GET['score_min'] ?? '';
$scoreMax      = $_GET['score_max'] ?? '';
$filterCountry = $_GET['country'] ?? '';
$filterType    = $_GET['type'] ?? '';
$sortBy        = $_GET['sort'] ?? 'score_desc';
$page          = max(1, (int) ($_GET['page'] ?? 1));
$perPage       = 50;
$offset        = ($page - 1) * $perPage;

// Build WHERE clauses
$where  = [];
$params = [];

if ($scoreMin !== '') {
    $where[]  = "pa.`total_score` >= ?";
    $params[] = (int) $scoreMin;
}
if ($scoreMax !== '') {
    $where[]  = "pa.`total_score` <= ?";
    $params[] = (int) $scoreMax;
}
if ($filterCountry !== '') {
    $where[]  = "p.`country_id` = ?";
    $params[] = (int) $filterCountry;
}
if ($filterType !== '') {
    $where[]  = "p.`property_type` = ?";
    $params[] = $filterType;
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Sort
$orderBy = match($sortBy) {
    'score_asc'  => 'pa.`total_score` ASC',
    'date_asc'   => 'pa.`audited_at` ASC',
    'date_desc'  => 'pa.`audited_at` DESC',
    default      => 'pa.`total_score` DESC',
};

// Count total
try {
    $countRow   = Database::fetch("SELECT COUNT(*) AS cnt FROM `property_audit` pa JOIN `properties` p ON p.`id` = pa.`property_id` {$whereSql}", $params);
    $totalCount = (int) ($countRow['cnt'] ?? 0);
    $totalPages = max(1, (int) ceil($totalCount / $perPage));

    // Fetch audits (latest per property)
    $allParams = array_merge($params, [$perPage, $offset]);
    $audits = Database::fetchAll(
        "SELECT pa.*, p.`name` AS property_name, p.`property_type`, c.`name` AS country_name, p.`id` AS prop_id
         FROM `property_audit` pa
         JOIN `properties` p ON p.`id` = pa.`property_id`
         LEFT JOIN `countries` c ON c.`id` = p.`country_id`
         {$whereSql}
         ORDER BY {$orderBy}
         LIMIT ? OFFSET ?",
        $allParams
    );

    // Country aggregate stats
    $countryAggregates = Database::fetchAll(
        "SELECT c.`name` AS country_name, c.`id` AS country_id,
                COUNT(pa.`id`) AS prop_count,
                ROUND(AVG(pa.`total_score`), 1) AS avg_score,
                MAX(pa.`total_score`) AS top_score,
                MIN(pa.`total_score`) AS bottom_score
         FROM `property_audit` pa
         JOIN `properties` p ON p.`id` = pa.`property_id`
         JOIN `countries` c ON c.`id` = p.`country_id`
         GROUP BY c.`id`
         ORDER BY avg_score DESC"
    );

    // Countries for filter
    $countriesList = Database::fetchAll("SELECT `id`, `name` FROM `countries` ORDER BY `name`");

} catch (\Throwable $e) {
    $totalCount = 0;
    $totalPages = 1;
    $audits = [];
    $countryAggregates = [];
    $countriesList = [];
    $flashMessage = 'Error loading audit data: ' . $e->getMessage();
    $flashType = 'error';
}

// Build query string for pagination/export
$queryParams = $_GET;
unset($queryParams['page'], $queryParams['export']);
$queryString = http_build_query($queryParams);

// Helper function for score color
function auditScoreColor(int $score): string {
    if ($score >= 81) return 'var(--color-success)';
    if ($score >= 61) return '#3A7D44';
    if ($score >= 31) return 'var(--color-warning)';
    return 'var(--color-danger)';
}

function auditScoreBg(int $score): string {
    if ($score >= 81) return '#D4EDDA';
    if ($score >= 61) return '#E8F5EE';
    if ($score >= 31) return '#FFF3CD';
    return '#F8D7DA';
}

// ---------------------------------------------------------------------------
// Render page
// ---------------------------------------------------------------------------
renderAdminHeader('Entity Audits');
?>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Filter Bar -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.25rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <form method="get" action="audits.php">
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
            <input type="number" name="score_min" value="<?= htmlspecialchars($scoreMin, ENT_QUOTES, 'UTF-8') ?>" placeholder="Min Score" class="form-control" style="max-width:100px;padding:0.4rem 0.6rem;font-size:0.8rem;" min="0" max="100">
            <input type="number" name="score_max" value="<?= htmlspecialchars($scoreMax, ENT_QUOTES, 'UTF-8') ?>" placeholder="Max Score" class="form-control" style="max-width:100px;padding:0.4rem 0.6rem;font-size:0.8rem;" min="0" max="100">
            <select name="country" class="form-control" style="max-width:180px;padding:0.4rem 0.6rem;font-size:0.8rem;">
                <option value="">All Countries</option>
                <?php foreach ($countriesList as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $filterCountry == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select name="type" class="form-control" style="max-width:150px;padding:0.4rem 0.6rem;font-size:0.8rem;">
                <option value="">All Types</option>
                <?php foreach (['lodge','camp','tented_camp','bush_camp','villa','guesthouse','hotel','resort','guide','tour_operator','dmc'] as $t): ?>
                    <option value="<?= $t ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sort" class="form-control" style="max-width:160px;padding:0.4rem 0.6rem;font-size:0.8rem;">
                <option value="score_desc" <?= $sortBy === 'score_desc' ? 'selected' : '' ?>>Score (High to Low)</option>
                <option value="score_asc" <?= $sortBy === 'score_asc' ? 'selected' : '' ?>>Score (Low to High)</option>
                <option value="date_desc" <?= $sortBy === 'date_desc' ? 'selected' : '' ?>>Date (Newest)</option>
                <option value="date_asc" <?= $sortBy === 'date_asc' ? 'selected' : '' ?>>Date (Oldest)</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="audits.php" class="btn btn-secondary btn-sm">Clear</a>
            <a href="audits.php?<?= htmlspecialchars($queryString . '&export=csv', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm" style="background:var(--color-info);color:#fff;border-color:var(--color-info);margin-left:auto;">Export CSV</a>
        </div>
    </form>
</div>

<!-- Results count -->
<div style="margin-bottom:1rem;font-size:0.85rem;color:var(--color-text-light);">
    <?= number_format($totalCount) ?> audit records found (page <?= $page ?> of <?= $totalPages ?>)
</div>

<!-- Audits Table -->
<div class="table-responsive" style="margin-bottom:1.5rem;">
    <table class="table">
        <thead>
            <tr>
                <th>Property Name</th>
                <th>Country</th>
                <th>Total Score</th>
                <th>Schema</th>
                <th>Entity</th>
                <th>AI Discover</th>
                <th>Technical</th>
                <th>Booking</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($audits)): ?>
                <tr><td colspan="10" style="text-align:center;color:var(--color-text-muted);padding:2rem;">No audit records found.</td></tr>
            <?php else: ?>
                <?php foreach ($audits as $audit):
                    $total   = (int) $audit['total_score'];
                    $schema  = (int) $audit['schema_score'];
                    $entity  = (int) $audit['entity_authority_score'];
                    $aiDisc  = (int) $audit['ai_discoverability_score'];
                    $tech    = (int) $audit['technical_score'];
                    $booking = (int) $audit['booking_authority_score'];
                ?>
                    <tr>
                        <td>
                            <a href="properties.php?id=<?= (int) $audit['prop_id'] ?>" style="font-weight:500;font-size:0.85rem;">
                                <?= htmlspecialchars($audit['property_name'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </td>
                        <td style="font-size:0.85rem;"><?= htmlspecialchars($audit['country_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-weight:700;font-size:0.85rem;background:<?= auditScoreBg($total) ?>;color:<?= auditScoreColor($total) ?>;"><?= $total ?></span>
                        </td>
                        <td style="color:<?= auditScoreColor($schema) ?>;font-weight:500;font-size:0.85rem;"><?= $schema ?>/30</td>
                        <td style="color:<?= auditScoreColor($entity) ?>;font-weight:500;font-size:0.85rem;"><?= $entity ?>/25</td>
                        <td style="color:<?= auditScoreColor($aiDisc) ?>;font-weight:500;font-size:0.85rem;"><?= $aiDisc ?>/20</td>
                        <td style="color:<?= auditScoreColor($tech) ?>;font-weight:500;font-size:0.85rem;"><?= $tech ?>/15</td>
                        <td style="color:<?= auditScoreColor($booking) ?>;font-weight:500;font-size:0.85rem;"><?= $booking ?>/10</td>
                        <td style="font-size:0.8rem;color:var(--color-text-muted);"><?= htmlspecialchars($audit['audited_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="white-space:nowrap;">
                            <a href="/public/audit-report.php?id=<?= (int) $audit['prop_id'] ?>" target="_blank" class="btn btn-sm" style="padding:2px 6px;font-size:0.7rem;background:var(--color-info);color:#fff;border-color:var(--color-info);" title="View Full Audit">View</a>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="reaudit">
                                <input type="hidden" name="property_id" value="<?= (int) $audit['prop_id'] ?>">
                                <button type="submit" class="btn btn-sm" style="padding:2px 6px;font-size:0.7rem;" title="Re-audit">Re-audit</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination" style="margin-bottom:2rem;">
    <?php if ($page > 1): ?>
        <a href="audits.php?<?= htmlspecialchars($queryString . '&page=' . ($page - 1), ENT_QUOTES, 'UTF-8') ?>">&laquo; Prev</a>
    <?php else: ?>
        <span class="disabled">&laquo; Prev</span>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 3);
    $end   = min($totalPages, $page + 3);
    if ($start > 1): ?>
        <a href="audits.php?<?= htmlspecialchars($queryString . '&page=1', ENT_QUOTES, 'UTF-8') ?>">1</a>
        <?php if ($start > 2): ?><span class="ellipsis">...</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="active"><?= $i ?></span>
        <?php else: ?>
            <a href="audits.php?<?= htmlspecialchars($queryString . '&page=' . $i, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="ellipsis">...</span><?php endif; ?>
        <a href="audits.php?<?= htmlspecialchars($queryString . '&page=' . $totalPages, ENT_QUOTES, 'UTF-8') ?>"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
        <a href="audits.php?<?= htmlspecialchars($queryString . '&page=' . ($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next &raquo;</a>
    <?php else: ?>
        <span class="disabled">Next &raquo;</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Country Aggregate Stats -->
<?php if (!empty($countryAggregates)): ?>
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;">Country Aggregate Stats</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Country</th>
                    <th>Avg Score</th>
                    <th>Property Count</th>
                    <th>Top Score</th>
                    <th>Bottom Score</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($countryAggregates as $ca):
                    $avgScore = (float) $ca['avg_score'];
                    $topScore = (int) $ca['top_score'];
                    $botScore = (int) $ca['bottom_score'];
                ?>
                    <tr>
                        <td style="font-weight:500;"><?= htmlspecialchars($ca['country_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-weight:600;font-size:0.85rem;background:<?= auditScoreBg((int) round($avgScore)) ?>;color:<?= auditScoreColor((int) round($avgScore)) ?>;">
                                <?= number_format($avgScore, 1) ?>
                            </span>
                        </td>
                        <td style="font-size:0.85rem;"><?= number_format((int) $ca['prop_count']) ?></td>
                        <td style="color:<?= auditScoreColor($topScore) ?>;font-weight:500;font-size:0.85rem;"><?= $topScore ?></td>
                        <td style="color:<?= auditScoreColor($botScore) ?>;font-weight:500;font-size:0.85rem;"><?= $botScore ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
renderAdminFooter();
