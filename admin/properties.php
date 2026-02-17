<?php
/**
 * Filename: properties.php
 * Description: Property management with search, filters, edit modal, bulk actions, and detail view
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
            case 'update_property':
                $propId = (int) ($_POST['property_id'] ?? 0);
                if ($propId > 0) {
                    $data = [
                        'name'              => trim($_POST['prop_name'] ?? ''),
                        'property_type'     => $_POST['prop_type'] ?? 'lodge',
                        'status'            => $_POST['prop_status'] ?? 'discovered',
                        'description'       => trim($_POST['prop_description'] ?? ''),
                        'short_description' => trim($_POST['prop_short_description'] ?? ''),
                        'price_tier'        => $_POST['prop_price_tier'] ?: null,
                        'room_count'        => $_POST['prop_room_count'] !== '' ? (int) $_POST['prop_room_count'] : null,
                        'star_rating'       => $_POST['prop_star_rating'] !== '' ? (float) $_POST['prop_star_rating'] : null,
                        'website'           => trim($_POST['prop_website'] ?? ''),
                        'phone'             => trim($_POST['prop_phone'] ?? ''),
                        'email'             => trim($_POST['prop_email'] ?? ''),
                        'formatted_address' => trim($_POST['prop_address'] ?? ''),
                        'country_id'        => $_POST['prop_country_id'] !== '' ? (int) $_POST['prop_country_id'] : null,
                    ];
                    Database::update('properties', $data, 'id = ?', [$propId]);
                    $flashMessage = 'Property updated successfully.';
                    $flashType    = 'success';
                    Logger::info('admin', 'Property updated', ['metadata' => ['property_id' => $propId]]);
                }
                break;

            case 'rescrape':
                $propId = (int) ($_POST['property_id'] ?? 0);
                if ($propId > 0) {
                    Database::insert('scrape_queue', [
                        'property_id' => $propId,
                        'queue_type'  => 'scrape',
                        'status'      => 'pending',
                        'priority'    => 3,
                    ]);
                    $flashMessage = 'Property added to scrape queue.';
                    $flashType    = 'success';
                }
                break;

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

            case 'publish':
                $propId = (int) ($_POST['property_id'] ?? 0);
                if ($propId > 0) {
                    $existing = Database::fetch("SELECT `id` FROM `listings` WHERE `property_id` = ?", [$propId]);
                    if ($existing) {
                        Database::update('listings', ['is_published' => 1, 'published_at' => date('Y-m-d H:i:s')], 'property_id = ?', [$propId]);
                    } else {
                        Database::insert('listings', [
                            'property_id'  => $propId,
                            'is_published' => 1,
                            'published_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                    Database::update('properties', ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')], 'id = ?', [$propId]);
                    $flashMessage = 'Property published.';
                    $flashType    = 'success';
                }
                break;

            case 'unpublish':
                $propId = (int) ($_POST['property_id'] ?? 0);
                if ($propId > 0) {
                    $existing = Database::fetch("SELECT `id` FROM `listings` WHERE `property_id` = ?", [$propId]);
                    if ($existing) {
                        Database::update('listings', ['is_published' => 0], 'property_id = ?', [$propId]);
                    }
                    Database::update('properties', ['status' => 'unpublished'], 'id = ?', [$propId]);
                    $flashMessage = 'Property unpublished.';
                    $flashType    = 'success';
                }
                break;

            case 'delete':
                $propId = (int) ($_POST['property_id'] ?? 0);
                if ($propId > 0) {
                    Database::delete('properties', 'id = ?', [$propId]);
                    $flashMessage = 'Property deleted.';
                    $flashType    = 'success';
                    Logger::info('admin', 'Property deleted', ['metadata' => ['property_id' => $propId]]);
                }
                break;

            case 'bulk':
                $bulkAction = $_POST['bulk_action'] ?? '';
                $ids = $_POST['selected_ids'] ?? [];
                if (!empty($ids) && is_array($ids)) {
                    $ids = array_map('intval', $ids);
                    $count = 0;
                    foreach ($ids as $pid) {
                        if ($pid <= 0) continue;
                        switch ($bulkAction) {
                            case 'rescrape':
                                Database::insert('scrape_queue', [
                                    'property_id' => $pid,
                                    'queue_type'  => 'scrape',
                                    'status'      => 'pending',
                                    'priority'    => 5,
                                ]);
                                $count++;
                                break;
                            case 'reaudit':
                                Database::insert('scrape_queue', [
                                    'property_id' => $pid,
                                    'queue_type'  => 'audit',
                                    'status'      => 'pending',
                                    'priority'    => 5,
                                ]);
                                $count++;
                                break;
                            case 'publish':
                                $existing = Database::fetch("SELECT `id` FROM `listings` WHERE `property_id` = ?", [$pid]);
                                if ($existing) {
                                    Database::update('listings', ['is_published' => 1, 'published_at' => date('Y-m-d H:i:s')], 'property_id = ?', [$pid]);
                                } else {
                                    Database::insert('listings', ['property_id' => $pid, 'is_published' => 1, 'published_at' => date('Y-m-d H:i:s')]);
                                }
                                Database::update('properties', ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')], 'id = ?', [$pid]);
                                $count++;
                                break;
                            case 'unpublish':
                                $existing = Database::fetch("SELECT `id` FROM `listings` WHERE `property_id` = ?", [$pid]);
                                if ($existing) {
                                    Database::update('listings', ['is_published' => 0], 'property_id = ?', [$pid]);
                                }
                                Database::update('properties', ['status' => 'unpublished'], 'id = ?', [$pid]);
                                $count++;
                                break;
                            case 'delete':
                                Database::delete('properties', 'id = ?', [$pid]);
                                $count++;
                                break;
                        }
                    }
                    $flashMessage = "Bulk action '{$bulkAction}' applied to {$count} properties.";
                    $flashType    = 'success';
                    Logger::info('admin', 'Bulk property action', ['metadata' => ['action' => $bulkAction, 'count' => $count]]);
                } else {
                    $flashMessage = 'No properties selected.';
                    $flashType    = 'error';
                }
                break;
        }
    } catch (\Throwable $e) {
        $flashMessage = 'Action failed: ' . $e->getMessage();
        $flashType    = 'error';
        Logger::error('admin', 'Property action failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Check for detail view
// ---------------------------------------------------------------------------
$detailView  = false;
$detailProp  = null;
$detailId    = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($detailId > 0) {
    $detailProp = Database::fetch(
        "SELECT p.*, c.`name` AS country_name, c.`iso_code`
         FROM `properties` p
         LEFT JOIN `countries` c ON c.`id` = p.`country_id`
         WHERE p.`id` = ?",
        [$detailId]
    );
    if ($detailProp) {
        $detailView = true;
    }
}

// ---------------------------------------------------------------------------
// Load reference data
// ---------------------------------------------------------------------------
$countriesList = Database::fetchAll("SELECT `id`, `name`, `iso_code` FROM `countries` ORDER BY `name`");

// ---------------------------------------------------------------------------
// Property Detail View
// ---------------------------------------------------------------------------
if ($detailView) {
    // Load related data
    $scrapeHistory    = Database::fetchAll("SELECT * FROM `property_scrapes` WHERE `property_id` = ? ORDER BY `scraped_at` DESC LIMIT 20", [$detailId]);
    $auditHistory     = Database::fetchAll("SELECT * FROM `property_audit` WHERE `property_id` = ? ORDER BY `audited_at` DESC LIMIT 10", [$detailId]);
    $enrichmentData   = Database::fetch("SELECT * FROM `property_enrichment` WHERE `property_id` = ? ORDER BY `enriched_at` DESC LIMIT 1", [$detailId]);
    $sources          = Database::fetchAll("SELECT * FROM `property_sources` WHERE `property_id` = ?", [$detailId]);
    $contacts         = Database::fetchAll("SELECT * FROM `property_contacts` WHERE `property_id` = ?", [$detailId]);
    $images           = Database::fetchAll("SELECT * FROM `property_images` WHERE `property_id` = ? ORDER BY `sort_order`", [$detailId]);

    renderAdminHeader('Property: ' . $detailProp['name']);
    ?>

    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div style="margin-bottom:1rem;">
        <a href="properties.php" class="btn btn-secondary btn-sm">&larr; Back to Properties</a>
        <a href="/public/property.php?id=<?= $detailId ?>" target="_blank" class="btn btn-sm" style="margin-left:0.5rem;background:var(--color-info);color:#fff;border-color:var(--color-info);">View Public Page</a>
    </div>

    <!-- Property Overview -->
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
        <div style="display:grid;grid-template-columns:1fr;gap:1rem;">
            <div>
                <h2 style="font-size:1.25rem;margin-bottom:0.5rem;"><?= htmlspecialchars($detailProp['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;">
                    <span class="badge badge-primary"><?= htmlspecialchars($detailProp['property_type'] ?? 'lodge', ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="badge <?= $detailProp['status'] === 'published' ? 'badge-success' : ($detailProp['status'] === 'discovered' ? 'badge-info' : 'badge-warning') ?>"><?= htmlspecialchars($detailProp['status'] ?? 'unknown', ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($detailProp['ai_readiness_score']): ?>
                        <span class="badge" style="background:<?= $detailProp['ai_readiness_score'] >= 61 ? 'var(--color-success-bg)' : ($detailProp['ai_readiness_score'] >= 31 ? 'var(--color-warning-bg)' : 'var(--color-danger-bg)') ?>;color:<?= $detailProp['ai_readiness_score'] >= 61 ? 'var(--color-success)' : ($detailProp['ai_readiness_score'] >= 31 ? 'var(--color-warning)' : 'var(--color-danger)') ?>">Score: <?= (int) $detailProp['ai_readiness_score'] ?>/100</span>
                    <?php endif; ?>
                </div>

                <table style="font-size:0.85rem;width:100%;">
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);width:140px;">Country</td><td><?= htmlspecialchars($detailProp['country_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Address</td><td><?= htmlspecialchars($detailProp['formatted_address'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Website</td><td><?php if ($detailProp['website']): ?><a href="<?= htmlspecialchars($detailProp['website'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($detailProp['website'], ENT_QUOTES, 'UTF-8') ?></a><?php else: ?>N/A<?php endif; ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Phone</td><td><?= htmlspecialchars($detailProp['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Email</td><td><?= htmlspecialchars($detailProp['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Google Rating</td><td><?= $detailProp['google_rating'] ? htmlspecialchars($detailProp['google_rating'], ENT_QUOTES, 'UTF-8') . ' (' . (int) $detailProp['google_review_count'] . ' reviews)' : 'N/A' ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Price Tier</td><td><?= htmlspecialchars($detailProp['price_tier'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Rooms</td><td><?= $detailProp['room_count'] !== null ? (int) $detailProp['room_count'] : 'N/A' ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Place ID</td><td style="font-size:0.75rem;color:var(--color-text-muted);"><?= htmlspecialchars($detailProp['place_id'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Last Scraped</td><td><?= htmlspecialchars($detailProp['last_scraped_at'] ?? 'Never', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Last Audited</td><td><?= htmlspecialchars($detailProp['last_audited_at'] ?? 'Never', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Created</td><td><?= htmlspecialchars($detailProp['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
                </table>
            </div>
        </div>

        <?php if ($detailProp['description']): ?>
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--color-border-light);">
                <h4 style="font-size:0.9rem;margin-bottom:0.5rem;">Description</h4>
                <p style="font-size:0.85rem;color:var(--color-text-light);"><?= nl2br(htmlspecialchars($detailProp['description'], ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sources -->
    <?php if (!empty($sources)): ?>
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
        <h3 style="font-size:1rem;margin-bottom:1rem;">Sources</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Source</th><th>URL</th><th>Discovered</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($sources as $src): ?>
                    <tr>
                        <td><span class="badge badge-primary"><?= htmlspecialchars($src['source_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td style="font-size:0.8rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php if ($src['source_url']): ?><a href="<?= htmlspecialchars($src['source_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($src['source_url'], ENT_QUOTES, 'UTF-8') ?></a><?php else: ?>N/A<?php endif; ?>
                        </td>
                        <td style="font-size:0.8rem;"><?= htmlspecialchars($src['discovered_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Contacts -->
    <?php if (!empty($contacts)): ?>
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
        <h3 style="font-size:1rem;margin-bottom:1rem;">Contacts</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Primary</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $ct): ?>
                    <tr>
                        <td><?= htmlspecialchars($ct['contact_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($ct['contact_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($ct['contact_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($ct['contact_role'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $ct['is_primary'] ? 'Yes' : 'No' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Enrichment Data -->
    <?php if ($enrichmentData): ?>
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
        <h3 style="font-size:1rem;margin-bottom:1rem;">Enrichment Data</h3>
        <table style="font-size:0.85rem;width:100%;">
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);width:160px;">CMS Platform</td><td><?= htmlspecialchars($enrichmentData['cms_platform'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Booking Engine</td><td><?= htmlspecialchars($enrichmentData['booking_engine'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">HTTPS</td><td><?= $enrichmentData['is_https'] ? 'Yes' : 'No' ?></td></tr>
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Mobile Responsive</td><td><?= $enrichmentData['is_mobile_responsive'] ? 'Yes' : 'No' ?></td></tr>
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">LLM.txt</td><td><?= $enrichmentData['has_llm_txt'] ? 'Yes' : 'No' ?></td></tr>
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">FAQ Content</td><td><?= $enrichmentData['has_faq_content'] ? 'Yes' : 'No' ?></td></tr>
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Blog</td><td><?= $enrichmentData['has_blog'] ? 'Yes' : 'No' ?></td></tr>
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Word Count</td><td><?= number_format((int) $enrichmentData['total_word_count']) ?></td></tr>
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Page Speed</td><td><?= $enrichmentData['page_speed_score'] !== null ? (int) $enrichmentData['page_speed_score'] . '/100' : 'N/A' ?></td></tr>
            <tr><td style="padding:0.3rem 1rem 0.3rem 0;font-weight:500;color:var(--color-text-light);">Enriched At</td><td><?= htmlspecialchars($enrichmentData['enriched_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
        </table>
    </div>
    <?php endif; ?>

    <!-- Scrape History -->
    <?php if (!empty($scrapeHistory)): ?>
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
        <h3 style="font-size:1rem;margin-bottom:1rem;">Scrape History</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>ID</th><th>URL</th><th>Page Type</th><th>Status</th><th>Error</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($scrapeHistory as $sh): ?>
                    <tr>
                        <td><?= (int) $sh['id'] ?></td>
                        <td style="font-size:0.75rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($sh['url'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($sh['page_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= $sh['status'] === 'complete' ? 'badge-success' : ($sh['status'] === 'failed' ? 'badge-danger' : 'badge-warning') ?>"><?= htmlspecialchars($sh['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td style="font-size:0.75rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($sh['error_message'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="font-size:0.8rem;"><?= htmlspecialchars($sh['scraped_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Audit History -->
    <?php if (!empty($auditHistory)): ?>
    <div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
        <h3 style="font-size:1rem;margin-bottom:1rem;">Audit History</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>ID</th><th>Total</th><th>Schema</th><th>Entity</th><th>AI Discover</th><th>Technical</th><th>Booking</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($auditHistory as $ah): ?>
                    <tr>
                        <td><?= (int) $ah['id'] ?></td>
                        <td><strong><?= (int) $ah['total_score'] ?></strong></td>
                        <td><?= (int) $ah['schema_score'] ?></td>
                        <td><?= (int) $ah['entity_authority_score'] ?></td>
                        <td><?= (int) $ah['ai_discoverability_score'] ?></td>
                        <td><?= (int) $ah['technical_score'] ?></td>
                        <td><?= (int) $ah['booking_authority_score'] ?></td>
                        <td style="font-size:0.8rem;"><?= htmlspecialchars($ah['audited_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php
    renderAdminFooter();
    exit;
}

// ---------------------------------------------------------------------------
// List View: Search, Filter, Pagination
// ---------------------------------------------------------------------------
$search     = trim($_GET['search'] ?? '');
$filterCountry = $_GET['country'] ?? '';
$filterStatus  = $_GET['status'] ?? '';
$filterType    = $_GET['type'] ?? '';
$scoreMin      = $_GET['score_min'] ?? '';
$scoreMax      = $_GET['score_max'] ?? '';
$page          = max(1, (int) ($_GET['page'] ?? 1));
$perPage       = 50;
$offset        = ($page - 1) * $perPage;

// Build query
$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(p.`name` LIKE ? OR p.`formatted_address` LIKE ? OR p.`website` LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filterCountry !== '') {
    $where[]  = "p.`country_id` = ?";
    $params[] = (int) $filterCountry;
}
if ($filterStatus !== '') {
    $where[]  = "p.`status` = ?";
    $params[] = $filterStatus;
}
if ($filterType !== '') {
    $where[]  = "p.`property_type` = ?";
    $params[] = $filterType;
}
if ($scoreMin !== '') {
    $where[]  = "p.`ai_readiness_score` >= ?";
    $params[] = (int) $scoreMin;
}
if ($scoreMax !== '') {
    $where[]  = "p.`ai_readiness_score` <= ?";
    $params[] = (int) $scoreMax;
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$countRow    = Database::fetch("SELECT COUNT(*) AS cnt FROM `properties` p {$whereSql}", $params);
$totalCount  = (int) ($countRow['cnt'] ?? 0);
$totalPages  = max(1, (int) ceil($totalCount / $perPage));

// Fetch properties
$allParams = array_merge($params, [$perPage, $offset]);
$properties = Database::fetchAll(
    "SELECT p.*, c.`name` AS country_name
     FROM `properties` p
     LEFT JOIN `countries` c ON c.`id` = p.`country_id`
     {$whereSql}
     ORDER BY p.`id` DESC
     LIMIT ? OFFSET ?",
    $allParams
);

// Build query string for pagination
$queryParams = $_GET;
unset($queryParams['page']);
$queryString = http_build_query($queryParams);

renderAdminHeader('Properties');
?>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Search and Filters -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.25rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <form method="get" action="properties.php">
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem;">
            <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search properties..." class="form-control" style="flex:1;min-width:200px;padding:0.5rem 0.75rem;font-size:0.85rem;">
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <a href="properties.php" class="btn btn-secondary btn-sm">Clear</a>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <select name="country" class="form-control" style="max-width:180px;padding:0.4rem 0.6rem;font-size:0.8rem;">
                <option value="">All Countries</option>
                <?php foreach ($countriesList as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $filterCountry == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control" style="max-width:140px;padding:0.4rem 0.6rem;font-size:0.8rem;">
                <option value="">All Statuses</option>
                <?php foreach (['discovered','scraped','enriched','audited','published','unpublished'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="type" class="form-control" style="max-width:150px;padding:0.4rem 0.6rem;font-size:0.8rem;">
                <option value="">All Types</option>
                <?php foreach (['lodge','camp','tented_camp','bush_camp','villa','guesthouse','hotel','resort','guide','tour_operator','dmc'] as $t): ?>
                    <option value="<?= $t ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="score_min" value="<?= htmlspecialchars($scoreMin, ENT_QUOTES, 'UTF-8') ?>" placeholder="Min Score" class="form-control" style="max-width:100px;padding:0.4rem 0.6rem;font-size:0.8rem;" min="0" max="100">
            <input type="number" name="score_max" value="<?= htmlspecialchars($scoreMax, ENT_QUOTES, 'UTF-8') ?>" placeholder="Max Score" class="form-control" style="max-width:100px;padding:0.4rem 0.6rem;font-size:0.8rem;" min="0" max="100">
        </div>
    </form>
</div>

<!-- Results count -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
    <span style="font-size:0.85rem;color:var(--color-text-light);">Showing <?= number_format($totalCount) ?> properties (page <?= $page ?> of <?= $totalPages ?>)</span>
</div>

<!-- Bulk actions and table -->
<form method="post" action="properties.php?<?= htmlspecialchars($queryString . '&page=' . $page, ENT_QUOTES, 'UTF-8') ?>" id="bulkForm">
    <input type="hidden" name="action" value="bulk">

    <!-- Bulk action bar -->
    <div style="display:flex;gap:0.5rem;align-items:center;margin-bottom:0.75rem;flex-wrap:wrap;">
        <select name="bulk_action" class="form-control" style="max-width:180px;padding:0.4rem 0.6rem;font-size:0.8rem;">
            <option value="">Bulk Actions</option>
            <option value="rescrape">Re-scrape Selected</option>
            <option value="reaudit">Re-audit Selected</option>
            <option value="publish">Publish Selected</option>
            <option value="unpublish">Unpublish Selected</option>
            <option value="delete">Delete Selected</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirmBulk()">Apply</button>
        <label style="font-size:0.8rem;color:var(--color-text-muted);margin-left:0.5rem;cursor:pointer;">
            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"> Select All
        </label>
    </div>

    <!-- Properties Table -->
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Country</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>AI Score</th>
                    <th>Rating</th>
                    <th>Reviews</th>
                    <th>Website</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($properties)): ?>
                    <tr><td colspan="11" style="text-align:center;color:var(--color-text-muted);padding:2rem;">No properties found.</td></tr>
                <?php else: ?>
                    <?php foreach ($properties as $prop): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_ids[]" value="<?= (int) $prop['id'] ?>" class="prop-checkbox"></td>
                            <td><?= (int) $prop['id'] ?></td>
                            <td>
                                <a href="properties.php?id=<?= (int) $prop['id'] ?>" style="font-weight:500;font-size:0.85rem;">
                                    <?= htmlspecialchars($prop['name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                            <td style="font-size:0.8rem;"><?= htmlspecialchars($prop['country_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge badge-primary" style="font-size:0.7rem;"><?= htmlspecialchars(str_replace('_', ' ', $prop['property_type'] ?? 'lodge'), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><span class="badge <?= $prop['status'] === 'published' ? 'badge-success' : ($prop['status'] === 'discovered' ? 'badge-info' : ($prop['status'] === 'unpublished' ? 'badge-danger' : 'badge-warning')) ?>"><?= htmlspecialchars($prop['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <?php
                                $score = (int) $prop['ai_readiness_score'];
                                $scoreColor = $score >= 61 ? 'var(--color-success)' : ($score >= 31 ? 'var(--color-warning)' : 'var(--color-danger)');
                                ?>
                                <span style="font-weight:600;color:<?= $scoreColor ?>"><?= $score ?></span>
                            </td>
                            <td style="font-size:0.85rem;"><?= $prop['google_rating'] ? number_format((float) $prop['google_rating'], 1) : '-' ?></td>
                            <td style="font-size:0.85rem;"><?= (int) $prop['google_review_count'] ?></td>
                            <td style="font-size:0.75rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?php if ($prop['website']): ?>
                                    <a href="<?= htmlspecialchars($prop['website'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($prop['website'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(parse_url($prop['website'], PHP_URL_HOST) ?: $prop['website'], ENT_QUOTES, 'UTF-8') ?></a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <a href="/public/property.php?id=<?= (int) $prop['id'] ?>" target="_blank" class="btn btn-sm" style="padding:2px 6px;font-size:0.7rem;background:var(--color-info);color:#fff;border-color:var(--color-info);" title="View Public">View</a>
                                <button type="button" class="btn btn-sm" style="padding:2px 6px;font-size:0.7rem;" onclick="openEditModal(<?= (int) $prop['id'] ?>)" title="Edit">Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="properties.php?<?= htmlspecialchars($queryString . '&page=' . ($page - 1), ENT_QUOTES, 'UTF-8') ?>">&laquo; Prev</a>
    <?php else: ?>
        <span class="disabled">&laquo; Prev</span>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 3);
    $end   = min($totalPages, $page + 3);
    if ($start > 1): ?>
        <a href="properties.php?<?= htmlspecialchars($queryString . '&page=1', ENT_QUOTES, 'UTF-8') ?>">1</a>
        <?php if ($start > 2): ?><span class="ellipsis">...</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="active"><?= $i ?></span>
        <?php else: ?>
            <a href="properties.php?<?= htmlspecialchars($queryString . '&page=' . $i, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="ellipsis">...</span><?php endif; ?>
        <a href="properties.php?<?= htmlspecialchars($queryString . '&page=' . $totalPages, ENT_QUOTES, 'UTF-8') ?>"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
        <a href="properties.php?<?= htmlspecialchars($queryString . '&page=' . ($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next &raquo;</a>
    <?php else: ?>
        <span class="disabled">Next &raquo;</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Edit Modal -->
<div class="modal-backdrop" id="editModalBackdrop" onclick="closeEditModal()"></div>
<div class="modal" id="editModal">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header">
            <h3>Edit Property</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" action="properties.php?<?= htmlspecialchars($queryString . '&page=' . $page, ENT_QUOTES, 'UTF-8') ?>" id="editForm">
                <input type="hidden" name="action" value="update_property">
                <input type="hidden" name="property_id" id="edit_property_id">

                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Name</label>
                        <input type="text" name="prop_name" id="edit_name" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Type</label>
                        <select name="prop_type" id="edit_type" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;">
                            <?php foreach (['lodge','camp','tented_camp','bush_camp','villa','guesthouse','hotel','resort','guide','tour_operator','dmc'] as $t): ?>
                                <option value="<?= $t ?>"><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Status</label>
                        <select name="prop_status" id="edit_status" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;">
                            <?php foreach (['discovered','scraped','enriched','audited','published','unpublished'] as $s): ?>
                                <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Country</label>
                        <select name="prop_country_id" id="edit_country_id" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;">
                            <option value="">-- Select --</option>
                            <?php foreach ($countriesList as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label class="form-label">Short Description</label>
                    <input type="text" name="prop_short_description" id="edit_short_description" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;" maxlength="500">
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label class="form-label">Description</label>
                    <textarea name="prop_description" id="edit_description" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;min-height:80px;"></textarea>
                </div>

                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Price Tier</label>
                        <select name="prop_price_tier" id="edit_price_tier" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;">
                            <option value="">-- None --</option>
                            <option value="budget">Budget</option>
                            <option value="mid">Mid</option>
                            <option value="luxury">Luxury</option>
                            <option value="ultra_luxury">Ultra Luxury</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Rooms</label>
                        <input type="number" name="prop_room_count" id="edit_room_count" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;" min="0">
                    </div>
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Star Rating</label>
                        <input type="number" name="prop_star_rating" id="edit_star_rating" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;" min="0" max="5" step="0.5">
                    </div>
                </div>

                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Website</label>
                        <input type="url" name="prop_website" id="edit_website" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;">
                    </div>
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Email</label>
                        <input type="email" name="prop_email" id="edit_email" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;">
                    </div>
                </div>

                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Phone</label>
                        <input type="text" name="prop_phone" id="edit_phone" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;">
                    </div>
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label class="form-label">Address</label>
                        <input type="text" name="prop_address" id="edit_address" class="form-control" style="font-size:0.85rem;padding:0.5rem 0.7rem;">
                    </div>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1rem;">
                    <div style="display:flex;gap:0.5rem;">
                        <button type="button" class="btn btn-sm" style="font-size:0.8rem;" onclick="submitQuickAction('rescrape')">Re-scrape</button>
                        <button type="button" class="btn btn-sm" style="font-size:0.8rem;" onclick="submitQuickAction('reaudit')">Re-audit</button>
                        <button type="button" class="btn btn-sm" style="font-size:0.8rem;background:var(--color-success);color:#fff;border-color:var(--color-success);" onclick="submitQuickAction('publish')">Publish</button>
                        <button type="button" class="btn btn-sm" style="font-size:0.8rem;" onclick="submitQuickAction('unpublish')">Unpublish</button>
                        <button type="button" class="btn btn-danger btn-sm" style="font-size:0.8rem;" onclick="submitQuickAction('delete')">Delete</button>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Property data for the edit modal (loaded inline to avoid AJAX)
var propertyData = <?= json_encode(
    array_map(function($p) {
        return [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'property_type' => $p['property_type'],
            'status' => $p['status'],
            'description' => $p['description'] ?? '',
            'short_description' => $p['short_description'] ?? '',
            'price_tier' => $p['price_tier'] ?? '',
            'room_count' => $p['room_count'] ?? '',
            'star_rating' => $p['star_rating'] ?? '',
            'website' => $p['website'] ?? '',
            'phone' => $p['phone'] ?? '',
            'email' => $p['email'] ?? '',
            'formatted_address' => $p['formatted_address'] ?? '',
            'country_id' => $p['country_id'] ?? '',
        ];
    }, $properties),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

function openEditModal(propId) {
    var data = null;
    for (var i = 0; i < propertyData.length; i++) {
        if (propertyData[i].id === propId) {
            data = propertyData[i];
            break;
        }
    }
    if (!data) return;

    document.getElementById('edit_property_id').value = data.id;
    document.getElementById('edit_name').value = data.name;
    document.getElementById('edit_type').value = data.property_type;
    document.getElementById('edit_status').value = data.status;
    document.getElementById('edit_description').value = data.description;
    document.getElementById('edit_short_description').value = data.short_description;
    document.getElementById('edit_price_tier').value = data.price_tier;
    document.getElementById('edit_room_count').value = data.room_count;
    document.getElementById('edit_star_rating').value = data.star_rating;
    document.getElementById('edit_website').value = data.website;
    document.getElementById('edit_phone').value = data.phone;
    document.getElementById('edit_email').value = data.email;
    document.getElementById('edit_address').value = data.formatted_address;
    document.getElementById('edit_country_id').value = data.country_id;

    document.getElementById('editModalBackdrop').classList.add('open');
    document.getElementById('editModal').classList.add('open');
}

function closeEditModal() {
    document.getElementById('editModalBackdrop').classList.remove('open');
    document.getElementById('editModal').classList.remove('open');
}

function submitQuickAction(action) {
    var propId = document.getElementById('edit_property_id').value;
    if (!propId) return;

    if (action === 'delete' && !confirm('Are you sure you want to delete this property? This cannot be undone.')) {
        return;
    }

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'properties.php?<?= htmlspecialchars($queryString . '&page=' . $page, ENT_QUOTES, 'UTF-8') ?>';

    var inputAction = document.createElement('input');
    inputAction.type = 'hidden';
    inputAction.name = 'action';
    inputAction.value = action;
    form.appendChild(inputAction);

    var inputId = document.createElement('input');
    inputId.type = 'hidden';
    inputId.name = 'property_id';
    inputId.value = propId;
    form.appendChild(inputId);

    document.body.appendChild(form);
    form.submit();
}

function toggleSelectAll(checkbox) {
    var checkboxes = document.querySelectorAll('.prop-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = checkbox.checked;
    }
}

function confirmBulk() {
    var action = document.querySelector('select[name="bulk_action"]').value;
    if (!action) {
        alert('Please select a bulk action.');
        return false;
    }
    var checked = document.querySelectorAll('.prop-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one property.');
        return false;
    }
    if (action === 'delete') {
        return confirm('Are you sure you want to delete ' + checked.length + ' properties? This cannot be undone.');
    }
    return confirm('Apply "' + action + '" to ' + checked.length + ' properties?');
}
</script>

<?php
renderAdminFooter();
