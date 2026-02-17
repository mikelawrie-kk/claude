<?php
/**
 * Filename: index.php
 * Description: Admin dashboard with stats, charts, discovery queue status, and quick actions
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
// Handle POST actions (Quick Actions)
// ---------------------------------------------------------------------------
$flashMessage = '';
$flashType    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'pause_discovery':
                // Check if setting exists
                $existing = Database::fetch("SELECT `id` FROM `settings` WHERE `setting_key` = ?", ['discovery_paused']);
                if ($existing) {
                    Database::update('settings', ['setting_value' => '1'], 'setting_key = ?', ['discovery_paused']);
                } else {
                    Database::insert('settings', [
                        'setting_key'   => 'discovery_paused',
                        'setting_value' => '1',
                        'setting_type'  => 'bool',
                        'description'   => 'Whether discovery is paused',
                    ]);
                }
                $flashMessage = 'Discovery has been paused.';
                $flashType    = 'success';
                Logger::info('admin', 'Discovery paused by admin');
                break;

            case 'resume_discovery':
                $existing = Database::fetch("SELECT `id` FROM `settings` WHERE `setting_key` = ?", ['discovery_paused']);
                if ($existing) {
                    Database::update('settings', ['setting_value' => '0'], 'setting_key = ?', ['discovery_paused']);
                } else {
                    Database::insert('settings', [
                        'setting_key'   => 'discovery_paused',
                        'setting_value' => '0',
                        'setting_type'  => 'bool',
                        'description'   => 'Whether discovery is paused',
                    ]);
                }
                $flashMessage = 'Discovery has been resumed.';
                $flashType    = 'success';
                Logger::info('admin', 'Discovery resumed by admin');
                break;

            case 'force_run_country':
                $countryId = (int) ($_POST['country_id'] ?? 0);
                if ($countryId > 0) {
                    // Get default search terms from settings
                    $termsSetting = Database::fetch(
                        "SELECT `setting_value` FROM `settings` WHERE `setting_key` = ?",
                        ['discovery_search_terms']
                    );
                    $terms = [];
                    if ($termsSetting && !empty($termsSetting['setting_value'])) {
                        $terms = array_filter(array_map('trim', explode("\n", $termsSetting['setting_value'])));
                    }
                    if (empty($terms)) {
                        $terms = ['safari lodge', 'game lodge', 'bush camp', 'tented camp', 'luxury safari'];
                    }
                    $inserted = 0;
                    foreach ($terms as $term) {
                        // Check if already exists and is pending/in_progress
                        $exists = Database::fetch(
                            "SELECT `id` FROM `discovery_queue` WHERE `country_id` = ? AND `search_term` = ? AND `status` IN ('pending','in_progress')",
                            [$countryId, $term]
                        );
                        if (!$exists) {
                            Database::insert('discovery_queue', [
                                'country_id'  => $countryId,
                                'search_term' => $term,
                                'status'      => 'pending',
                            ]);
                            $inserted++;
                        }
                    }
                    $flashMessage = "Added {$inserted} discovery queue items for selected country.";
                    $flashType    = 'success';
                    Logger::info('admin', 'Force run country discovery', ['metadata' => ['country_id' => $countryId, 'items' => $inserted]]);
                } else {
                    $flashMessage = 'Please select a country.';
                    $flashType    = 'error';
                }
                break;

            case 'reset_failed':
                $affected = Database::update(
                    'discovery_queue',
                    ['status' => 'pending', 'last_run' => null],
                    "status = 'failed'"
                );
                $flashMessage = "Reset {$affected} failed discovery items to pending.";
                $flashType    = 'success';
                Logger::info('admin', 'Reset failed discovery items', ['metadata' => ['count' => $affected]]);
                break;
        }
    } catch (\Throwable $e) {
        $flashMessage = 'Action failed: ' . $e->getMessage();
        $flashType    = 'error';
        Logger::error('admin', 'Dashboard action failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Gather dashboard data
// ---------------------------------------------------------------------------
try {
    // Total properties
    $totalProperties = Database::count('properties');

    // Properties by status
    $statusCounts = Database::fetchAll(
        "SELECT `status`, COUNT(*) AS cnt FROM `properties` GROUP BY `status`"
    );
    $statuses = [];
    foreach ($statusCounts as $row) {
        $statuses[$row['status']] = (int) $row['cnt'];
    }
    $discovered = $statuses['discovered'] ?? 0;
    $scraped    = $statuses['scraped'] ?? 0;
    $enriched   = $statuses['enriched'] ?? 0;
    $audited    = $statuses['audited'] ?? 0;
    $published  = $statuses['published'] ?? 0;

    // Cost tracker data
    $todayCost       = CostTracker::getTodayCost();
    $weekCost        = CostTracker::getWeekCost();
    $monthCost       = CostTracker::getMonthCost();
    $dailyBudget     = CostTracker::getDailyBudget();
    $budgetRemaining = CostTracker::getBudgetRemaining();

    // Properties by country (top 10)
    $countryStats = Database::fetchAll(
        "SELECT c.`name`, COUNT(p.`id`) AS cnt
         FROM `properties` p
         JOIN `countries` c ON c.`id` = p.`country_id`
         GROUP BY p.`country_id`
         ORDER BY cnt DESC
         LIMIT 10"
    );
    $maxCountryCount = 0;
    foreach ($countryStats as $cs) {
        if ((int) $cs['cnt'] > $maxCountryCount) {
            $maxCountryCount = (int) $cs['cnt'];
        }
    }

    // Discovery queue status
    $dqPending    = Database::count('discovery_queue', "status = 'pending'");
    $dqInProgress = Database::count('discovery_queue', "status = 'in_progress'");
    $dqComplete   = Database::count('discovery_queue', "status = 'complete'");
    $dqFailed     = Database::count('discovery_queue', "status = 'failed'");
    $dqTotal      = $dqPending + $dqInProgress + $dqComplete + $dqFailed;
    $dqProgress   = $dqTotal > 0 ? round(($dqComplete / $dqTotal) * 100, 1) : 0;

    // Recent discoveries (last 20 properties)
    $recentProperties = Database::fetchAll(
        "SELECT p.`id`, p.`name`, c.`name` AS country_name, ps.`source_name`, p.`created_at`
         FROM `properties` p
         LEFT JOIN `countries` c ON c.`id` = p.`country_id`
         LEFT JOIN `property_sources` ps ON ps.`property_id` = p.`id`
         ORDER BY p.`created_at` DESC
         LIMIT 20"
    );

    // Countries for Force Run dropdown
    $countries = Database::fetchAll("SELECT `id`, `name`, `iso_code` FROM `countries` WHERE `is_active` = 1 ORDER BY `name`");

    // Discovery paused status
    $pausedRow = Database::fetch("SELECT `setting_value` FROM `settings` WHERE `setting_key` = ?", ['discovery_paused']);
    $isPaused  = $pausedRow && $pausedRow['setting_value'] === '1';

    // Budget gauge percentage
    $budgetPercent = $dailyBudget > 0 ? min(100, round(($todayCost / $dailyBudget) * 100, 1)) : 0;

} catch (\Throwable $e) {
    $totalProperties = 0;
    $discovered = $scraped = $enriched = $audited = $published = 0;
    $todayCost = $weekCost = $monthCost = $dailyBudget = $budgetRemaining = 0;
    $countryStats = [];
    $maxCountryCount = 0;
    $dqPending = $dqInProgress = $dqComplete = $dqFailed = $dqTotal = 0;
    $dqProgress = 0;
    $recentProperties = [];
    $countries = [];
    $isPaused = false;
    $budgetPercent = 0;
    $flashMessage = 'Error loading dashboard data: ' . $e->getMessage();
    $flashType = 'error';
}

// ---------------------------------------------------------------------------
// Render page
// ---------------------------------------------------------------------------
renderAdminHeader('Dashboard');
?>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>">
        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<!-- Stats Cards Row -->
<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number"><?= number_format($totalProperties) ?></div>
            <div class="stat-card-label">Total Properties</div>
        </div>
        <div class="stat-card-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number"><?= number_format($published) ?></div>
            <div class="stat-card-label">Published</div>
            <div style="margin-top:6px;font-size:0.75rem;color:var(--color-text-muted);">
                D:<?= $discovered ?> | S:<?= $scraped ?> | E:<?= $enriched ?> | A:<?= $audited ?>
            </div>
        </div>
        <div class="stat-card-icon" style="background-color:var(--color-success-bg);color:var(--color-success);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number">$<?= number_format($todayCost, 2) ?></div>
            <div class="stat-card-label">Today's API Cost</div>
        </div>
        <div class="stat-card-icon" style="background-color:var(--color-warning-bg);color:var(--color-warning);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number" style="color:<?= $budgetRemaining < 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>">$<?= number_format($budgetRemaining, 2) ?></div>
            <div class="stat-card-label">Budget Remaining</div>
            <div style="margin-top:6px;font-size:0.75rem;color:var(--color-text-muted);">of $<?= number_format($dailyBudget, 2) ?> daily</div>
        </div>
        <div class="stat-card-icon" style="background-color:<?= $budgetRemaining < 0 ? 'var(--color-danger-bg)' : 'var(--color-success-bg)' ?>;color:<?= $budgetRemaining < 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
    </div>
</div>

<!-- Two-column layout for chart + queue -->
<div style="display:grid;grid-template-columns:1fr;gap:1.5rem;margin-bottom:1.5rem;">

    <!-- Properties by Country Chart -->
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);">
        <h3 style="font-size:1rem;margin-bottom:1.25rem;">Properties by Country (Top 10)</h3>
        <?php if (empty($countryStats)): ?>
            <p style="color:var(--color-text-muted);font-size:0.875rem;">No property data yet.</p>
        <?php else: ?>
            <?php foreach ($countryStats as $cs):
                $barWidth = $maxCountryCount > 0 ? round(((int) $cs['cnt'] / $maxCountryCount) * 100) : 0;
            ?>
                <div style="display:flex;align-items:center;margin-bottom:0.6rem;">
                    <div style="min-width:110px;font-size:0.8rem;font-weight:500;color:var(--color-dark);"><?= htmlspecialchars($cs['name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div style="flex:1;background:var(--color-border-light);border-radius:4px;height:20px;margin:0 0.75rem;overflow:hidden;">
                        <div style="width:<?= $barWidth ?>%;height:100%;background:var(--color-primary);border-radius:4px;transition:width 0.5s ease;"></div>
                    </div>
                    <div style="min-width:40px;text-align:right;font-size:0.8rem;font-weight:600;color:var(--color-dark);"><?= number_format((int) $cs['cnt']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Discovery Queue Status -->
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);">
        <h3 style="font-size:1rem;margin-bottom:1.25rem;">Discovery Queue Status</h3>
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1rem;">
            <span class="badge badge-info">Pending: <?= number_format($dqPending) ?></span>
            <span class="badge badge-warning">In Progress: <?= number_format($dqInProgress) ?></span>
            <span class="badge badge-success">Complete: <?= number_format($dqComplete) ?></span>
            <span class="badge badge-danger">Failed: <?= number_format($dqFailed) ?></span>
        </div>
        <div style="margin-bottom:0.5rem;font-size:0.8rem;color:var(--color-text-muted);">Overall Completion: <?= $dqProgress ?>%</div>
        <div style="width:100%;background:var(--color-border-light);border-radius:6px;height:12px;overflow:hidden;">
            <div style="width:<?= $dqProgress ?>%;height:100%;background:var(--color-success);border-radius:6px;transition:width 0.5s ease;"></div>
        </div>
        <div style="margin-top:0.75rem;font-size:0.8rem;color:var(--color-text-muted);">
            Total: <?= number_format($dqTotal) ?> items
            <?php if ($isPaused): ?>
                <span class="badge badge-danger" style="margin-left:0.5rem;">PAUSED</span>
            <?php else: ?>
                <span class="badge badge-success" style="margin-left:0.5rem;">ACTIVE</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- API Cost Tracker + Quick Actions -->
<div style="display:grid;grid-template-columns:1fr;gap:1.5rem;margin-bottom:1.5rem;">

    <!-- API Cost Tracker -->
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);">
        <h3 style="font-size:1rem;margin-bottom:1.25rem;">API Cost Tracker</h3>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem;">
            <div style="text-align:center;">
                <div style="font-size:1.25rem;font-weight:700;color:var(--color-dark);">$<?= number_format($todayCost, 2) ?></div>
                <div style="font-size:0.75rem;color:var(--color-text-muted);">Today</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:1.25rem;font-weight:700;color:var(--color-dark);">$<?= number_format($weekCost, 2) ?></div>
                <div style="font-size:0.75rem;color:var(--color-text-muted);">This Week</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:1.25rem;font-weight:700;color:var(--color-dark);">$<?= number_format($monthCost, 2) ?></div>
                <div style="font-size:0.75rem;color:var(--color-text-muted);">This Month</div>
            </div>
        </div>
        <div style="font-size:0.8rem;color:var(--color-text-muted);margin-bottom:0.4rem;">Daily Budget Gauge (<?= $budgetPercent ?>%)</div>
        <div style="width:100%;background:var(--color-border-light);border-radius:6px;height:14px;overflow:hidden;">
            <div style="width:<?= min(100, $budgetPercent) ?>%;height:100%;background:<?= $budgetPercent > 90 ? 'var(--color-danger)' : ($budgetPercent > 70 ? 'var(--color-warning)' : 'var(--color-success)') ?>;border-radius:6px;transition:width 0.5s ease;"></div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);">
        <h3 style="font-size:1rem;margin-bottom:1.25rem;">Quick Actions</h3>

        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:1rem;">
            <?php if ($isPaused): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="resume_discovery">
                    <button type="submit" class="btn btn-primary btn-sm">Resume Discovery</button>
                </form>
            <?php else: ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="pause_discovery">
                    <button type="submit" class="btn btn-secondary btn-sm">Pause Discovery</button>
                </form>
            <?php endif; ?>

            <form method="post" style="display:inline;" onsubmit="return confirm('Reset all failed discovery items to pending?');">
                <input type="hidden" name="action" value="reset_failed">
                <button type="submit" class="btn btn-danger btn-sm">Reset Failed Items</button>
            </form>
        </div>

        <form method="post" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="action" value="force_run_country">
            <select name="country_id" class="form-control" style="max-width:220px;padding:0.4rem 0.6rem;font-size:0.85rem;" required>
                <option value="">-- Select Country --</option>
                <?php foreach ($countries as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($c['iso_code'], ENT_QUOTES, 'UTF-8') ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Force Run Country</button>
        </form>
    </div>
</div>

<!-- Recent Discoveries -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;">Recent Discoveries</h3>
    <?php if (empty($recentProperties)): ?>
        <p style="color:var(--color-text-muted);font-size:0.875rem;">No properties discovered yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Property</th>
                        <th>Country</th>
                        <th>Source</th>
                        <th>Discovered</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentProperties as $rp): ?>
                        <tr>
                            <td>
                                <a href="properties.php?id=<?= (int) $rp['id'] ?>" style="font-weight:500;">
                                    <?= htmlspecialchars($rp['name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($rp['country_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($rp['source_name']): ?>
                                    <span class="badge badge-primary"><?= htmlspecialchars($rp['source_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span class="badge">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.8rem;color:var(--color-text-muted);"><?= htmlspecialchars($rp['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
    @media (min-width: 768px) {
        .stat-cards { grid-template-columns: repeat(2, 1fr); }
    }
    @media (min-width: 1024px) {
        .stat-cards { grid-template-columns: repeat(4, 1fr); }
    }
</style>

<?php
renderAdminFooter();
