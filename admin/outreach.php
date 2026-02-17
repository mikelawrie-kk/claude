<?php
/**
 * Filename: outreach.php
 * Description: Outreach tracking with email template editor, queue management, and bulk send
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
            case 'save_template':
                $subject  = trim($_POST['template_subject'] ?? '');
                $body     = trim($_POST['template_body'] ?? '');

                // Save subject
                $existingSubj = Database::fetch("SELECT `id` FROM `settings` WHERE `setting_key` = ?", ['outreach_template_subject']);
                if ($existingSubj) {
                    Database::update('settings', ['setting_value' => $subject], 'setting_key = ?', ['outreach_template_subject']);
                } else {
                    Database::insert('settings', [
                        'setting_key'   => 'outreach_template_subject',
                        'setting_value' => $subject,
                        'setting_type'  => 'string',
                        'description'   => 'Outreach email subject template',
                    ]);
                }

                // Save body
                $existingBody = Database::fetch("SELECT `id` FROM `settings` WHERE `setting_key` = ?", ['outreach_template_body']);
                if ($existingBody) {
                    Database::update('settings', ['setting_value' => $body], 'setting_key = ?', ['outreach_template_body']);
                } else {
                    Database::insert('settings', [
                        'setting_key'   => 'outreach_template_body',
                        'setting_value' => $body,
                        'setting_type'  => 'string',
                        'description'   => 'Outreach email body template',
                    ]);
                }

                $flashMessage = 'Email template saved successfully.';
                $flashType    = 'success';
                Logger::info('admin', 'Outreach template updated');
                break;

            case 'send_now':
                $itemId = (int) ($_POST['item_id'] ?? 0);
                if ($itemId > 0) {
                    // Mark as sent (actual sending would be done by the outreach cron)
                    Database::update('outreach_queue', [
                        'status'  => 'sent',
                        'sent_at' => date('Y-m-d H:i:s'),
                    ], 'id = ?', [$itemId]);
                    $flashMessage = 'Item marked as sent. The outreach cron will process the actual email delivery.';
                    $flashType    = 'success';
                    Logger::info('admin', 'Outreach item sent', ['metadata' => ['outreach_id' => $itemId]]);
                }
                break;

            case 'resend':
                $itemId = (int) ($_POST['item_id'] ?? 0);
                if ($itemId > 0) {
                    Database::update('outreach_queue', [
                        'status'  => 'pending',
                        'sent_at' => null,
                    ], 'id = ?', [$itemId]);
                    $flashMessage = 'Item reset for re-sending.';
                    $flashType    = 'success';
                }
                break;

            case 'remove':
                $itemId = (int) ($_POST['item_id'] ?? 0);
                if ($itemId > 0) {
                    Database::delete('outreach_queue', 'id = ?', [$itemId]);
                    $flashMessage = 'Item removed from outreach queue.';
                    $flashType    = 'success';
                }
                break;

            case 'bulk_send':
                // Get daily limit
                $limitRow = Database::fetch("SELECT `setting_value` FROM `settings` WHERE `setting_key` = ?", ['max_outreach_per_day']);
                $dailyLimit = $limitRow ? (int) $limitRow['setting_value'] : 50;

                // Count how many sent today
                $sentTodayRow = Database::fetch(
                    "SELECT COUNT(*) AS cnt FROM `outreach_queue` WHERE `status` = 'sent' AND DATE(`sent_at`) = CURDATE()"
                );
                $sentToday = (int) ($sentTodayRow['cnt'] ?? 0);
                $remaining = max(0, $dailyLimit - $sentToday);
                $batchSize = min(50, $remaining);

                if ($batchSize <= 0) {
                    $flashMessage = "Daily outreach limit reached ({$dailyLimit} emails). Try again tomorrow.";
                    $flashType    = 'error';
                } else {
                    // Get next batch of pending items
                    $pendingItems = Database::fetchAll(
                        "SELECT `id` FROM `outreach_queue` WHERE `status` = 'pending' ORDER BY `id` ASC LIMIT ?",
                        [$batchSize]
                    );

                    $count = 0;
                    foreach ($pendingItems as $pi) {
                        Database::update('outreach_queue', [
                            'status'  => 'sent',
                            'sent_at' => date('Y-m-d H:i:s'),
                        ], 'id = ?', [$pi['id']]);
                        $count++;
                    }

                    $flashMessage = "Queued {$count} emails for sending. The outreach cron will process delivery.";
                    $flashType    = 'success';
                    Logger::info('admin', 'Bulk outreach send', ['metadata' => ['count' => $count]]);
                }
                break;

            case 'add_to_queue':
                $selectedIds = $_POST['add_property_ids'] ?? [];
                if (!empty($selectedIds) && is_array($selectedIds)) {
                    $count = 0;
                    foreach ($selectedIds as $propId) {
                        $propId = (int) $propId;
                        if ($propId <= 0) continue;

                        // Get the property's primary contact email
                        $contact = Database::fetch(
                            "SELECT `contact_email`, `contact_name`
                             FROM `property_contacts`
                             WHERE `property_id` = ? AND `contact_email` IS NOT NULL AND `contact_email` != ''
                             ORDER BY `is_primary` DESC
                             LIMIT 1",
                            [$propId]
                        );

                        // Also check property.email
                        if (!$contact) {
                            $prop = Database::fetch("SELECT `email`, `name` FROM `properties` WHERE `id` = ? AND `email` IS NOT NULL AND `email` != ''", [$propId]);
                            if ($prop) {
                                $contact = [
                                    'contact_email' => $prop['email'],
                                    'contact_name'  => $prop['name'],
                                ];
                            }
                        }

                        if ($contact && !empty($contact['contact_email'])) {
                            // Check if not already in queue
                            $exists = Database::fetch(
                                "SELECT `id` FROM `outreach_queue` WHERE `property_id` = ?",
                                [$propId]
                            );
                            if (!$exists) {
                                Database::insert('outreach_queue', [
                                    'property_id'   => $propId,
                                    'contact_email'  => $contact['contact_email'],
                                    'contact_name'   => $contact['contact_name'] ?? '',
                                    'status'         => 'pending',
                                ]);
                                $count++;
                            }
                        }
                    }
                    $flashMessage = "Added {$count} properties to outreach queue.";
                    $flashType    = 'success';
                } else {
                    $flashMessage = 'No properties selected.';
                    $flashType    = 'error';
                }
                break;
        }
    } catch (\Throwable $e) {
        $flashMessage = 'Action failed: ' . $e->getMessage();
        $flashType    = 'error';
        Logger::error('admin', 'Outreach action failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Gather outreach data
// ---------------------------------------------------------------------------
try {
    // Stats
    $totalInQueue = Database::count('outreach_queue');
    $sentCount    = Database::count('outreach_queue', "status = 'sent'");
    $openedCount  = Database::count('outreach_queue', "status = 'opened'");
    $clickedCount = Database::count('outreach_queue', "status = 'clicked'");
    $claimedCount = Database::count('outreach_queue', "status = 'claimed'");
    $bouncedCount = Database::count('outreach_queue', "status = 'bounced'");

    // Email template
    $templateSubjectRow = Database::fetch("SELECT `setting_value` FROM `settings` WHERE `setting_key` = ?", ['outreach_template_subject']);
    $templateBodyRow    = Database::fetch("SELECT `setting_value` FROM `settings` WHERE `setting_key` = ?", ['outreach_template_body']);

    $templateSubject = $templateSubjectRow['setting_value'] ?? 'Your {property_name} AI Readiness Score: {score}/100';
    $templateBody    = $templateBodyRow['setting_value'] ?? "Hi {contact_name},\n\nWe've just completed an AI readiness audit of {property_name} and you scored {score}/100 ({score_interpretation}).\n\nHere are our top findings:\n1. {finding_1}\n2. {finding_2}\n3. {finding_3}\n\nView your full audit report: {listing_url}\n\nWant to improve your score and get found by AI travel planners? Claim your free listing:\n{claim_url}\n\nBest regards,\nSafari Traveller Team";

    // Outreach queue (paginated)
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 50;
    $offset  = ($page - 1) * $perPage;

    $totalPages = max(1, (int) ceil($totalInQueue / $perPage));

    $queueItems = Database::fetchAll(
        "SELECT oq.*, p.`name` AS property_name
         FROM `outreach_queue` oq
         LEFT JOIN `properties` p ON p.`id` = oq.`property_id`
         ORDER BY FIELD(oq.`status`, 'pending', 'sent', 'opened', 'clicked', 'claimed', 'bounced', 'unsubscribed'), oq.`id` DESC
         LIMIT ? OFFSET ?",
        [$perPage, $offset]
    );

    // Properties available to add (have email but not in outreach queue yet)
    $availableProperties = Database::fetchAll(
        "SELECT p.`id`, p.`name`, p.`email`, c.`name` AS country_name
         FROM `properties` p
         LEFT JOIN `countries` c ON c.`id` = p.`country_id`
         WHERE p.`email` IS NOT NULL AND p.`email` != ''
         AND p.`id` NOT IN (SELECT `property_id` FROM `outreach_queue`)
         ORDER BY p.`name`
         LIMIT 200"
    );

    // Also check property_contacts for additional candidates
    $availableFromContacts = Database::fetchAll(
        "SELECT DISTINCT p.`id`, p.`name`, pc.`contact_email` AS email, c.`name` AS country_name
         FROM `properties` p
         JOIN `property_contacts` pc ON pc.`property_id` = p.`id`
         LEFT JOIN `countries` c ON c.`id` = p.`country_id`
         WHERE pc.`contact_email` IS NOT NULL AND pc.`contact_email` != ''
         AND (p.`email` IS NULL OR p.`email` = '')
         AND p.`id` NOT IN (SELECT `property_id` FROM `outreach_queue`)
         ORDER BY p.`name`
         LIMIT 200"
    );

    // Merge and deduplicate
    $availableMap = [];
    foreach ($availableProperties as $ap) {
        $availableMap[$ap['id']] = $ap;
    }
    foreach ($availableFromContacts as $ac) {
        if (!isset($availableMap[$ac['id']])) {
            $availableMap[$ac['id']] = $ac;
        }
    }
    $allAvailable = array_values($availableMap);

} catch (\Throwable $e) {
    $totalInQueue = $sentCount = $openedCount = $clickedCount = $claimedCount = $bouncedCount = 0;
    $templateSubject = '';
    $templateBody = '';
    $queueItems = [];
    $allAvailable = [];
    $totalPages = 1;
    $flashMessage = 'Error loading outreach data: ' . $e->getMessage();
    $flashType = 'error';
}

// ---------------------------------------------------------------------------
// Render page
// ---------------------------------------------------------------------------
renderAdminHeader('Outreach');
?>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="stat-cards" style="grid-template-columns:repeat(2,1fr);margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number"><?= number_format($totalInQueue) ?></div>
            <div class="stat-card-label">Total in Queue</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number"><?= number_format($sentCount) ?></div>
            <div class="stat-card-label">Sent</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number"><?= number_format($openedCount) ?></div>
            <div class="stat-card-label">Opened</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number"><?= number_format($clickedCount) ?></div>
            <div class="stat-card-label">Clicked</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number" style="color:var(--color-success);"><?= number_format($claimedCount) ?></div>
            <div class="stat-card-label">Claimed</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-info">
            <div class="stat-card-number" style="color:var(--color-danger);"><?= number_format($bouncedCount) ?></div>
            <div class="stat-card-label">Bounced</div>
        </div>
    </div>
</div>

<!-- Email Template Editor -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;">Email Template Editor</h3>

    <form method="post">
        <input type="hidden" name="action" value="save_template">

        <div class="form-group" style="margin-bottom:1rem;">
            <label class="form-label">Subject Line</label>
            <input type="text" name="template_subject" value="<?= htmlspecialchars($templateSubject, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;">
        </div>

        <div class="form-group" style="margin-bottom:1rem;">
            <label class="form-label">Email Body</label>
            <textarea name="template_body" class="form-control" style="font-size:0.85rem;min-height:200px;font-family:'Courier New',monospace;"><?= htmlspecialchars($templateBody, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div style="font-size:0.8rem;color:var(--color-text-muted);">
                <strong>Available Variables:</strong><br>
                <code>{property_name}</code> <code>{contact_name}</code> <code>{score}</code> <code>{score_interpretation}</code><br>
                <code>{listing_url}</code> <code>{claim_url}</code> <code>{finding_1}</code> <code>{finding_2}</code> <code>{finding_3}</code>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save Template</button>
        </div>
    </form>
</div>

<!-- Bulk Send + Add to Queue -->
<div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.25rem;">
    <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="bulk_send">
        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Send to next batch of pending outreach items?');">Bulk Send (Next Batch)</button>
    </form>
</div>

<!-- Outreach Queue Table -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;">Outreach Queue</h3>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Property Name</th>
                    <th>Contact Email</th>
                    <th>Status</th>
                    <th>Sent Date</th>
                    <th>Opened</th>
                    <th>Clicked</th>
                    <th>Claimed</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($queueItems)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--color-text-muted);padding:2rem;">No outreach items yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($queueItems as $qi):
                        $statusClass = match($qi['status']) {
                            'pending'      => 'badge-info',
                            'sent'         => 'badge-primary',
                            'opened'       => 'badge-warning',
                            'clicked'      => 'badge-warning',
                            'claimed'      => 'badge-success',
                            'bounced'      => 'badge-danger',
                            'unsubscribed' => 'badge-danger',
                            default        => '',
                        };
                    ?>
                        <tr>
                            <td>
                                <a href="properties.php?id=<?= (int) $qi['property_id'] ?>" style="font-weight:500;font-size:0.85rem;">
                                    <?= htmlspecialchars($qi['property_name'] ?? 'Property #' . $qi['property_id'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                            <td style="font-size:0.85rem;"><?= htmlspecialchars($qi['contact_email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($qi['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td style="font-size:0.8rem;color:var(--color-text-muted);"><?= htmlspecialchars($qi['sent_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="font-size:0.8rem;">
                                <?php if ($qi['opened_at']): ?>
                                    <span style="color:var(--color-success);" title="<?= htmlspecialchars($qi['opened_at'], ENT_QUOTES, 'UTF-8') ?>">Yes</span>
                                <?php else: ?>
                                    <span style="color:var(--color-text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.8rem;">
                                <?php if ($qi['clicked_at']): ?>
                                    <span style="color:var(--color-success);" title="<?= htmlspecialchars($qi['clicked_at'], ENT_QUOTES, 'UTF-8') ?>">Yes</span>
                                <?php else: ?>
                                    <span style="color:var(--color-text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.8rem;">
                                <?php if ($qi['claimed_at']): ?>
                                    <span style="color:var(--color-success);" title="<?= htmlspecialchars($qi['claimed_at'], ENT_QUOTES, 'UTF-8') ?>">Yes</span>
                                <?php else: ?>
                                    <span style="color:var(--color-text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <?php if ($qi['status'] === 'pending'): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="send_now">
                                        <input type="hidden" name="item_id" value="<?= (int) $qi['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="padding:2px 6px;font-size:0.7rem;background:var(--color-success);color:#fff;border-color:var(--color-success);">Send</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="resend">
                                        <input type="hidden" name="item_id" value="<?= (int) $qi['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="padding:2px 6px;font-size:0.7rem;">Resend</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="item_id" value="<?= (int) $qi['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 6px;font-size:0.7rem;" onclick="return confirm('Remove from outreach queue?');">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination" style="margin-bottom:2rem;">
    <?php if ($page > 1): ?>
        <a href="outreach.php?page=<?= $page - 1 ?>">&laquo; Prev</a>
    <?php else: ?>
        <span class="disabled">&laquo; Prev</span>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
        <?php if ($i === $page): ?>
            <span class="active"><?= $i ?></span>
        <?php else: ?>
            <a href="outreach.php?page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
        <a href="outreach.php?page=<?= $page + 1 ?>">Next &raquo;</a>
    <?php else: ?>
        <span class="disabled">Next &raquo;</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Add to Queue Section -->
<?php if (!empty($allAvailable)): ?>
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;">Add to Outreach Queue</h3>
    <p style="font-size:0.85rem;color:var(--color-text-muted);margin-bottom:1rem;">Properties with email addresses not yet in the outreach queue (showing up to 200):</p>

    <form method="post">
        <input type="hidden" name="action" value="add_to_queue">

        <div style="margin-bottom:0.75rem;">
            <label style="font-size:0.8rem;color:var(--color-text-muted);cursor:pointer;">
                <input type="checkbox" id="addSelectAll" onchange="toggleAddSelectAll(this)"> Select All
            </label>
        </div>

        <div style="max-height:300px;overflow-y:auto;border:1px solid var(--color-border-light);border-radius:var(--radius-md);padding:0.5rem;">
            <?php foreach ($allAvailable as $ap): ?>
                <label style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0.5rem;font-size:0.85rem;cursor:pointer;border-bottom:1px solid var(--color-border-light);">
                    <input type="checkbox" name="add_property_ids[]" value="<?= (int) $ap['id'] ?>" class="add-checkbox">
                    <span style="font-weight:500;"><?= htmlspecialchars($ap['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span style="color:var(--color-text-muted);font-size:0.75rem;margin-left:auto;"><?= htmlspecialchars($ap['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($ap['country_name'])): ?>
                        <span class="badge" style="font-size:0.65rem;"><?= htmlspecialchars($ap['country_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:0.75rem;">
            <button type="submit" class="btn btn-primary btn-sm">Add Selected to Queue</button>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
function toggleAddSelectAll(checkbox) {
    var checkboxes = document.querySelectorAll('.add-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = checkbox.checked;
    }
}
</script>

<style>
    @media (min-width: 768px) {
        .stat-cards { grid-template-columns: repeat(3, 1fr) !important; }
    }
    @media (min-width: 1024px) {
        .stat-cards { grid-template-columns: repeat(6, 1fr) !important; }
    }
</style>

<?php
renderAdminFooter();
