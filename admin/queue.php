<?php
/**
 * Filename: queue.php
 * Description: Queue management with tabs for Discovery, Scrape, Enrich, and Audit queues
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
    $action   = $_POST['action'] ?? '';
    $queueTab = $_POST['queue_tab'] ?? 'discovery';

    try {
        switch ($action) {
            case 'retry':
                $itemId    = (int) ($_POST['item_id'] ?? 0);
                $queueType = $_POST['queue_type'] ?? '';
                if ($itemId > 0) {
                    if ($queueType === 'discovery') {
                        Database::update('discovery_queue', ['status' => 'pending', 'last_run' => null], 'id = ?', [$itemId]);
                    } else {
                        Database::update('scrape_queue', ['status' => 'pending', 'last_attempt' => null, 'error_message' => null], 'id = ?', [$itemId]);
                    }
                    $flashMessage = 'Item reset to pending.';
                    $flashType    = 'success';
                }
                break;

            case 'boost':
                $itemId    = (int) ($_POST['item_id'] ?? 0);
                $queueType = $_POST['queue_type'] ?? '';
                if ($itemId > 0 && $queueType !== 'discovery') {
                    Database::query("UPDATE `scrape_queue` SET `priority` = GREATEST(1, `priority` - 2) WHERE `id` = ?", [$itemId]);
                    $flashMessage = 'Priority boosted.';
                    $flashType    = 'success';
                }
                break;

            case 'cancel':
                $itemId    = (int) ($_POST['item_id'] ?? 0);
                $queueType = $_POST['queue_type'] ?? '';
                if ($itemId > 0) {
                    if ($queueType === 'discovery') {
                        Database::delete('discovery_queue', 'id = ?', [$itemId]);
                    } else {
                        Database::delete('scrape_queue', 'id = ?', [$itemId]);
                    }
                    $flashMessage = 'Item cancelled and removed.';
                    $flashType    = 'success';
                }
                break;

            case 'retry_all_failed':
                $queueType = $_POST['queue_type'] ?? '';
                if ($queueType === 'discovery') {
                    $affected = Database::update('discovery_queue', ['status' => 'pending', 'last_run' => null], "status = 'failed'");
                } else {
                    $affected = Database::update('scrape_queue', ['status' => 'pending', 'last_attempt' => null, 'error_message' => null], "status = 'failed' AND queue_type = ?", [$queueType]);
                }
                $flashMessage = "Reset {$affected} failed items to pending.";
                $flashType    = 'success';
                Logger::info('admin', 'Retry all failed queue items', ['metadata' => ['queue_type' => $queueType, 'count' => $affected]]);
                break;

            case 'pause_all':
                $queueType = $_POST['queue_type'] ?? '';
                if ($queueType === 'discovery') {
                    $affected = Database::update('discovery_queue', ['status' => 'pending'], "status = 'in_progress'");
                } else {
                    $affected = Database::update('scrape_queue', ['status' => 'paused'], "status IN ('pending','in_progress') AND queue_type = ?", [$queueType]);
                }
                $flashMessage = "Paused {$affected} items.";
                $flashType    = 'success';
                break;

            case 'resume_all':
                $queueType = $_POST['queue_type'] ?? '';
                if ($queueType !== 'discovery') {
                    $affected = Database::update('scrape_queue', ['status' => 'pending'], "status = 'paused' AND queue_type = ?", [$queueType]);
                    $flashMessage = "Resumed {$affected} items.";
                    $flashType    = 'success';
                }
                break;
        }
    } catch (\Throwable $e) {
        $flashMessage = 'Action failed: ' . $e->getMessage();
        $flashType    = 'error';
        Logger::error('admin', 'Queue action failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Determine active tab
// ---------------------------------------------------------------------------
$activeTab = $_GET['tab'] ?? 'discovery';
if (!in_array($activeTab, ['discovery', 'scrape', 'enrich', 'audit'], true)) {
    $activeTab = 'discovery';
}

// ---------------------------------------------------------------------------
// Gather data based on active tab
// ---------------------------------------------------------------------------
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

try {
    if ($activeTab === 'discovery') {
        // Discovery queue stats
        $stats = [
            'pending'     => Database::count('discovery_queue', "status = 'pending'"),
            'in_progress' => Database::count('discovery_queue', "status = 'in_progress'"),
            'complete'    => Database::count('discovery_queue', "status = 'complete'"),
            'failed'      => Database::count('discovery_queue', "status = 'failed'"),
        ];
        $totalItems = array_sum($stats);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $completionPct = $totalItems > 0 ? round(($stats['complete'] / $totalItems) * 100, 1) : 0;

        $items = Database::fetchAll(
            "SELECT dq.*, c.`name` AS country_name, c.`iso_code`
             FROM `discovery_queue` dq
             LEFT JOIN `countries` c ON c.`id` = dq.`country_id`
             ORDER BY FIELD(dq.`status`, 'in_progress', 'pending', 'failed', 'complete'), dq.`id` DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );
    } else {
        // Scrape queue (scrape / enrich / audit)
        $queueTypeMap = [
            'scrape'  => 'scrape',
            'enrich'  => 'enrich',
            'audit'   => 'audit',
        ];
        $queueType = $queueTypeMap[$activeTab] ?? 'scrape';

        $stats = [
            'pending'     => Database::count('scrape_queue', "status = 'pending' AND queue_type = ?", [$queueType]),
            'in_progress' => Database::count('scrape_queue', "status = 'in_progress' AND queue_type = ?", [$queueType]),
            'complete'    => Database::count('scrape_queue', "status = 'complete' AND queue_type = ?", [$queueType]),
            'failed'      => Database::count('scrape_queue', "status = 'failed' AND queue_type = ?", [$queueType]),
        ];
        $pausedCount = Database::count('scrape_queue', "status = 'paused' AND queue_type = ?", [$queueType]);
        $totalItems = array_sum($stats) + $pausedCount;
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $completionPct = $totalItems > 0 ? round(($stats['complete'] / $totalItems) * 100, 1) : 0;

        $items = Database::fetchAll(
            "SELECT sq.*, p.`name` AS property_name
             FROM `scrape_queue` sq
             LEFT JOIN `properties` p ON p.`id` = sq.`property_id`
             WHERE sq.`queue_type` = ?
             ORDER BY FIELD(sq.`status`, 'in_progress', 'pending', 'paused', 'failed', 'complete'), sq.`priority` ASC, sq.`id` DESC
             LIMIT ? OFFSET ?",
            [$queueType, $perPage, $offset]
        );
    }
} catch (\Throwable $e) {
    $stats = ['pending' => 0, 'in_progress' => 0, 'complete' => 0, 'failed' => 0];
    $totalItems = 0;
    $totalPages = 1;
    $completionPct = 0;
    $items = [];
    $pausedCount = 0;
    $flashMessage = 'Error loading queue data: ' . $e->getMessage();
    $flashType = 'error';
}

// ---------------------------------------------------------------------------
// Render page
// ---------------------------------------------------------------------------
renderAdminHeader('Queue Management');
?>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:0;margin-bottom:1.5rem;border-bottom:2px solid var(--color-border-light);">
    <?php
    $tabs = [
        'discovery' => 'Discovery',
        'scrape'    => 'Scrape',
        'enrich'    => 'Enrich',
        'audit'     => 'Audit',
    ];
    foreach ($tabs as $key => $label):
        $isActive = ($activeTab === $key);
    ?>
        <a href="queue.php?tab=<?= $key ?>" style="padding:0.75rem 1.25rem;font-size:0.875rem;font-weight:<?= $isActive ? '600' : '400' ?>;color:<?= $isActive ? 'var(--color-primary)' : 'var(--color-text-light)' ?>;text-decoration:none;border-bottom:2px solid <?= $isActive ? 'var(--color-primary)' : 'transparent' ?>;margin-bottom:-2px;transition:all 0.2s;"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<!-- Stats Bar -->
<div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.25rem;">
    <span class="badge badge-info" style="font-size:0.8rem;padding:4px 12px;">Pending: <?= number_format($stats['pending']) ?></span>
    <span class="badge badge-warning" style="font-size:0.8rem;padding:4px 12px;">In Progress: <?= number_format($stats['in_progress']) ?></span>
    <span class="badge badge-success" style="font-size:0.8rem;padding:4px 12px;">Complete: <?= number_format($stats['complete']) ?></span>
    <span class="badge badge-danger" style="font-size:0.8rem;padding:4px 12px;">Failed: <?= number_format($stats['failed']) ?></span>
    <?php if (isset($pausedCount) && $pausedCount > 0): ?>
        <span class="badge" style="font-size:0.8rem;padding:4px 12px;">Paused: <?= number_format($pausedCount) ?></span>
    <?php endif; ?>
</div>

<!-- Progress Bar -->
<div style="margin-bottom:1.25rem;">
    <div style="font-size:0.8rem;color:var(--color-text-muted);margin-bottom:0.4rem;">Completion: <?= $completionPct ?>% (<?= number_format($totalItems) ?> total items)</div>
    <div style="width:100%;background:var(--color-border-light);border-radius:6px;height:10px;overflow:hidden;">
        <div style="width:<?= $completionPct ?>%;height:100%;background:var(--color-success);border-radius:6px;transition:width 0.5s ease;"></div>
    </div>
</div>

<!-- Bulk Actions -->
<div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;">
    <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="retry_all_failed">
        <input type="hidden" name="queue_type" value="<?= htmlspecialchars($activeTab === 'discovery' ? 'discovery' : $activeTab, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="queue_tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Retry all failed items?');">Retry All Failed</button>
    </form>
    <?php if ($activeTab !== 'discovery'): ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="pause_all">
            <input type="hidden" name="queue_type" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="queue_tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Pause All</button>
        </form>
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="resume_all">
            <input type="hidden" name="queue_type" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="queue_tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Resume All</button>
        </form>
    <?php endif; ?>
</div>

<!-- Queue Items Table -->
<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <?php if ($activeTab === 'discovery'): ?>
                    <th>Country</th>
                    <th>Search Term</th>
                <?php else: ?>
                    <th>Property</th>
                <?php endif; ?>
                <th>Status</th>
                <?php if ($activeTab !== 'discovery'): ?>
                    <th>Priority</th>
                    <th>Attempts</th>
                <?php endif; ?>
                <?php if ($activeTab === 'discovery'): ?>
                    <th>Results</th>
                    <th>API Cost</th>
                    <th>Next Page</th>
                <?php endif; ?>
                <th>Last Attempt</th>
                <th>Error</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="<?= $activeTab === 'discovery' ? 9 : 8 ?>" style="text-align:center;color:var(--color-text-muted);padding:2rem;">No queue items found.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= (int) $item['id'] ?></td>

                        <?php if ($activeTab === 'discovery'): ?>
                            <td>
                                <span style="font-weight:500;"><?= htmlspecialchars($item['country_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                                <span style="font-size:0.75rem;color:var(--color-text-muted);"> (<?= htmlspecialchars($item['iso_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</span>
                            </td>
                            <td style="font-size:0.85rem;"><?= htmlspecialchars($item['search_term'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php else: ?>
                            <td>
                                <?php if ($item['property_name']): ?>
                                    <a href="properties.php?id=<?= (int) $item['property_id'] ?>" style="font-weight:500;font-size:0.85rem;"><?= htmlspecialchars($item['property_name'], ENT_QUOTES, 'UTF-8') ?></a>
                                <?php else: ?>
                                    <span style="color:var(--color-text-muted);">Property #<?= (int) $item['property_id'] ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>

                        <td>
                            <?php
                            $statusClass = match($item['status']) {
                                'pending'     => 'badge-info',
                                'in_progress' => 'badge-warning',
                                'complete'    => 'badge-success',
                                'failed'      => 'badge-danger',
                                'paused'      => '',
                                default       => '',
                            };
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        </td>

                        <?php if ($activeTab !== 'discovery'): ?>
                            <td style="font-size:0.85rem;"><?= (int) $item['priority'] ?></td>
                            <td style="font-size:0.85rem;"><?= (int) $item['attempts'] ?>/<?= (int) $item['max_attempts'] ?></td>
                        <?php endif; ?>

                        <?php if ($activeTab === 'discovery'): ?>
                            <td style="font-size:0.85rem;"><?= (int) ($item['results_count'] ?? 0) ?></td>
                            <td style="font-size:0.85rem;">$<?= number_format((float) ($item['api_cost'] ?? 0), 4) ?></td>
                            <td style="font-size:0.75rem;">
                                <?php if (!empty($item['next_page_token'])): ?>
                                    <span class="badge badge-primary" title="Has next page token">Yes</span>
                                <?php else: ?>
                                    <span style="color:var(--color-text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>

                        <td style="font-size:0.8rem;color:var(--color-text-muted);">
                            <?php
                            $lastAttempt = $activeTab === 'discovery'
                                ? ($item['last_run'] ?? '')
                                : ($item['last_attempt'] ?? '');
                            echo htmlspecialchars($lastAttempt ?: '-', ENT_QUOTES, 'UTF-8');
                            ?>
                        </td>
                        <td style="font-size:0.75rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--color-danger);" title="<?= htmlspecialchars($item['error_message'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($item['error_message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php $qType = $activeTab === 'discovery' ? 'discovery' : $activeTab; ?>
                            <!-- Retry -->
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="queue_type" value="<?= htmlspecialchars($qType, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="queue_tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-sm" style="padding:2px 6px;font-size:0.7rem;" title="Retry">Retry</button>
                            </form>
                            <?php if ($activeTab !== 'discovery'): ?>
                                <!-- Boost Priority -->
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="boost">
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="queue_type" value="<?= htmlspecialchars($qType, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="queue_tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn btn-sm" style="padding:2px 6px;font-size:0.7rem;background:var(--color-primary);color:#fff;border-color:var(--color-primary);" title="Boost Priority">Boost</button>
                                </form>
                            <?php endif; ?>
                            <!-- Cancel -->
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="queue_type" value="<?= htmlspecialchars($qType, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="queue_tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 6px;font-size:0.7rem;" title="Cancel" onclick="return confirm('Remove this item from the queue?');">Cancel</button>
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
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="queue.php?tab=<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>&page=<?= $page - 1 ?>">&laquo; Prev</a>
    <?php else: ?>
        <span class="disabled">&laquo; Prev</span>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 3);
    $end   = min($totalPages, $page + 3);
    if ($start > 1): ?>
        <a href="queue.php?tab=<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>&page=1">1</a>
        <?php if ($start > 2): ?><span class="ellipsis">...</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="active"><?= $i ?></span>
        <?php else: ?>
            <a href="queue.php?tab=<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>&page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="ellipsis">...</span><?php endif; ?>
        <a href="queue.php?tab=<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
        <a href="queue.php?tab=<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>&page=<?= $page + 1 ?>">Next &raquo;</a>
    <?php else: ?>
        <span class="disabled">Next &raquo;</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
renderAdminFooter();
