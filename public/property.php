<?php
/**
 * Filename: property.php
 * Description: Individual property listing page with AI readiness score, details, and enquiry form
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../templates/layout.php';

// ---------------------------------------------------------------------------
// Lookup property by id or slug
// ---------------------------------------------------------------------------

$propertyId   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$propertySlug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

$property = false;

try {
    if ($propertyId > 0) {
        $property = Database::fetch(
            "SELECT p.*, c.name AS country_name, c.iso_code AS country_iso,
                    rp.name AS reserve_name, rp.slug AS reserve_slug
             FROM properties p
             LEFT JOIN countries c ON p.country_id = c.id
             LEFT JOIN reserves_parks rp ON p.reserve_park_id = rp.id
             WHERE p.id = ?",
            [$propertyId]
        );
    } elseif ($propertySlug !== '') {
        $property = Database::fetch(
            "SELECT p.*, c.name AS country_name, c.iso_code AS country_iso,
                    rp.name AS reserve_name, rp.slug AS reserve_slug
             FROM properties p
             LEFT JOIN countries c ON p.country_id = c.id
             LEFT JOIN reserves_parks rp ON p.reserve_park_id = rp.id
             WHERE p.slug = ?",
            [$propertySlug]
        );
    }
} catch (Throwable $e) {
    error_log('Property lookup error: ' . $e->getMessage());
    $property = false;
}

// ---------------------------------------------------------------------------
// 404 if not found
// ---------------------------------------------------------------------------

if (!$property) {
    http_response_code(404);
    renderHeader('Property Not Found - Safari Traveller');
    ?>
    <section class="section">
        <div class="section-container error-page">
            <h1>Property Not Found</h1>
            <p>The property you are looking for does not exist or has been removed.</p>
            <a href="/public/" class="btn btn-primary">Return to Homepage</a>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}

// ---------------------------------------------------------------------------
// Increment page views
// ---------------------------------------------------------------------------

try {
    Database::query(
        "UPDATE listings SET page_views = page_views + 1 WHERE property_id = ?",
        [(int)$property['id']]
    );
} catch (Throwable $e) {
    // Non-critical; log and continue
    error_log('Page view increment error: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Fetch audit data
// ---------------------------------------------------------------------------

$audit = false;
try {
    $audit = Database::fetch(
        "SELECT * FROM property_audit
         WHERE property_id = ?
         ORDER BY audited_at DESC
         LIMIT 1",
        [(int)$property['id']]
    );
} catch (Throwable $e) {
    error_log('Audit lookup error: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function propScoreColour(int $score): string
{
    if ($score >= 81) return '#3A7D44';
    if ($score >= 61) return '#5B9A3A';
    if ($score >= 31) return '#D4A84B';
    return '#C0392B';
}

function propScoreLabel(int $score): string
{
    if ($score >= 81) return 'Excellent AI Readiness';
    if ($score >= 61) return 'Good AI Readiness';
    if ($score >= 31) return 'Moderate AI Readiness';
    return 'Low AI Readiness';
}

function propScoreInterpretation(int $score): string
{
    if ($score >= 81) return 'This property has strong entity authority and structured data. AI assistants are likely to recommend it when travellers ask about safari options in this area.';
    if ($score >= 61) return 'This property has a solid digital foundation but is missing some key structured data elements. With targeted improvements, it could significantly increase AI visibility.';
    if ($score >= 31) return 'This property has some digital presence but is missing critical schema markup and entity authority signals. AI systems may struggle to identify and recommend it.';
    return 'This property has minimal structured data and entity authority. It is largely invisible to AI assistants and needs significant work to become discoverable.';
}

function propFormatType(string $type): string
{
    return ucwords(str_replace('_', ' ', $type));
}

function propRenderStars(float $rating): string
{
    $html = '';
    $full = (int)floor($rating);
    $half = ($rating - $full) >= 0.3 ? 1 : 0;
    $empty = 5 - $full - $half;

    for ($i = 0; $i < $full; $i++) {
        $html .= '<span class="star star-full">&#9733;</span>';
    }
    if ($half) {
        $html .= '<span class="star star-half">&#9733;</span>';
    }
    for ($i = 0; $i < $empty; $i++) {
        $html .= '<span class="star star-empty">&#9734;</span>';
    }
    return $html;
}

// ---------------------------------------------------------------------------
// Prepare data
// ---------------------------------------------------------------------------

$pid          = (int)$property['id'];
$name         = htmlspecialchars($property['name']);
$score        = (int)$property['ai_readiness_score'];
$scoreColor   = propScoreColour($score);
$scoreLabel   = propScoreLabel($score);
$scoreInterp  = propScoreInterpretation($score);

$lat = $property['latitude'] ? (float)$property['latitude'] : null;
$lng = $property['longitude'] ? (float)$property['longitude'] : null;

// Audit category scores
$schemaScore    = $audit ? (int)$audit['schema_score'] : 0;
$entityScore    = $audit ? (int)$audit['entity_authority_score'] : 0;
$aiDiscScore    = $audit ? (int)$audit['ai_discoverability_score'] : 0;
$techScore      = $audit ? (int)$audit['technical_score'] : 0;
$bookingScore   = $audit ? (int)$audit['booking_authority_score'] : 0;

// Recommendations from audit
$recommendations = [];
if ($audit && !empty($audit['recommendations'])) {
    $decoded = json_decode($audit['recommendations'], true);
    if (is_array($decoded)) {
        $recommendations = array_slice($decoded, 0, 3);
    }
}

// Success/error message for enquiry form
$enquiryMsg  = '';
$enquiryType = '';
if (isset($_GET['enquiry'])) {
    if ($_GET['enquiry'] === 'sent') {
        $enquiryMsg  = 'Thank you for your enquiry. We will forward it to the property.';
        $enquiryType = 'success';
    } elseif ($_GET['enquiry'] === 'error') {
        $enquiryMsg  = 'There was a problem submitting your enquiry. Please try again.';
        $enquiryType = 'error';
    }
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

renderHeader($name . ' - Safari Traveller', htmlspecialchars(strip_tags($property['short_description'] ?? $property['description'] ?? '')));
?>

<!-- Breadcrumb -->
<section class="breadcrumb-bar">
    <div class="section-container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <ol>
                <li><a href="/public/">Home</a></li>
                <?php if (!empty($property['country_name'])): ?>
                <li><a href="/public/country.php?slug=<?= htmlspecialchars($property['country_iso']) ?>"><?= htmlspecialchars($property['country_name']) ?></a></li>
                <?php endif; ?>
                <?php if (!empty($property['reserve_name'])): ?>
                <li><a href="/public/search.php?reserve=<?= (int)$property['reserve_park_id'] ?>"><?= htmlspecialchars($property['reserve_name']) ?></a></li>
                <?php endif; ?>
                <li aria-current="page"><?= $name ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Property Header -->
<section class="section property-header-section">
    <div class="section-container">
        <div class="property-header">
            <div class="property-header-info">
                <h1 class="property-name"><?= $name ?></h1>
                <div class="property-location-line">
                    <?php if (!empty($property['reserve_name'])): ?>
                    <span class="property-reserve"><?= htmlspecialchars($property['reserve_name']) ?></span> &middot;
                    <?php endif; ?>
                    <?php if (!empty($property['formatted_address'])): ?>
                    <span class="property-address"><?= htmlspecialchars($property['formatted_address']) ?></span>
                    <?php elseif (!empty($property['country_name'])): ?>
                    <span class="property-country"><?= htmlspecialchars($property['country_name']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($property['property_type'])): ?>
                <span class="property-type-badge"><?= propFormatType($property['property_type']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Key Details Grid -->
<section class="section property-details-section">
    <div class="section-container">
        <div class="property-layout">
            <div class="property-main">

                <!-- Key Details -->
                <div class="detail-card">
                    <h2 class="detail-card-title">Key Details</h2>
                    <div class="details-grid">
                        <?php if (!empty($property['property_type'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Property Type</span>
                            <span class="detail-value"><?= propFormatType($property['property_type']) ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($property['room_count'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Rooms</span>
                            <span class="detail-value"><?= (int)$property['room_count'] ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($property['price_tier'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Price Tier</span>
                            <span class="detail-value"><?= propFormatType($property['price_tier']) ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ((float)($property['google_rating'] ?? 0) > 0): ?>
                        <div class="detail-item">
                            <span class="detail-label">Google Rating</span>
                            <span class="detail-value">
                                <span class="stars-display"><?= propRenderStars((float)$property['google_rating']) ?></span>
                                <strong><?= number_format((float)$property['google_rating'], 1) ?></strong>
                                <?php if ((int)($property['google_review_count'] ?? 0) > 0): ?>
                                <span class="review-count-inline">(<?= number_format((int)$property['google_review_count']) ?> reviews)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($property['star_rating']) && (float)$property['star_rating'] > 0): ?>
                        <div class="detail-item">
                            <span class="detail-label">Star Rating</span>
                            <span class="detail-value"><?= number_format((float)$property['star_rating'], 0) ?>-Star</span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($property['country_name'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Country</span>
                            <span class="detail-value"><a href="/public/country.php?slug=<?= htmlspecialchars($property['country_iso']) ?>"><?= htmlspecialchars($property['country_name']) ?></a></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Description -->
                <?php if (!empty($property['description'])): ?>
                <div class="detail-card">
                    <h2 class="detail-card-title">About <?= $name ?></h2>
                    <div class="property-description">
                        <?= nl2br(htmlspecialchars($property['description'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contact Information -->
                <?php
                $hasContact = !empty($property['phone']) || !empty($property['international_phone'])
                           || !empty($property['email']) || !empty($property['website']);
                ?>
                <?php if ($hasContact): ?>
                <div class="detail-card">
                    <h2 class="detail-card-title">Contact Information</h2>
                    <div class="contact-info-grid">
                        <?php if (!empty($property['phone']) || !empty($property['international_phone'])): ?>
                        <div class="contact-item">
                            <span class="contact-label">Phone</span>
                            <span class="contact-value">
                                <?php
                                $phone = $property['international_phone'] ?: $property['phone'];
                                ?>
                                <a href="tel:<?= htmlspecialchars($phone) ?>"><?= htmlspecialchars($phone) ?></a>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($property['email'])): ?>
                        <div class="contact-item">
                            <span class="contact-label">Email</span>
                            <span class="contact-value"><a href="mailto:<?= htmlspecialchars($property['email']) ?>"><?= htmlspecialchars($property['email']) ?></a></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($property['website'])): ?>
                        <div class="contact-item">
                            <span class="contact-label">Website</span>
                            <span class="contact-value"><a href="<?= htmlspecialchars($property['website']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(preg_replace('#^https?://(www\.)?#', '', $property['website'])) ?></a></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($property['google_maps_url'])): ?>
                        <div class="contact-item">
                            <span class="contact-label">Map</span>
                            <span class="contact-value"><a href="<?= htmlspecialchars($property['google_maps_url']) ?>" target="_blank" rel="noopener">View on Google Maps</a></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Map Placeholder -->
                <?php if ($lat !== null && $lng !== null): ?>
                <div class="detail-card">
                    <h2 class="detail-card-title">Location</h2>
                    <div class="map-placeholder" id="property-map" data-lat="<?= $lat ?>" data-lng="<?= $lng ?>" data-name="<?= $name ?>">
                        <div class="map-placeholder-inner">
                            <p>Map loading...</p>
                            <p class="map-coords">Coordinates: <?= number_format($lat, 6) ?>, <?= number_format($lng, 6) ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- AI Readiness Score Section -->
                <div class="detail-card score-card" id="ai-score">
                    <h2 class="detail-card-title">AI Readiness Score</h2>

                    <div class="score-hero">
                        <!-- Large Radial Gauge -->
                        <div class="score-gauge-large">
                            <svg width="160" height="160" viewBox="0 0 160 160">
                                <!-- Background circle -->
                                <circle cx="80" cy="80" r="70" fill="none" stroke="#E8E8E3" stroke-width="10"/>
                                <!-- Score arc -->
                                <circle cx="80" cy="80" r="70" fill="none" stroke="<?= $scoreColor ?>" stroke-width="10"
                                        stroke-dasharray="<?= round(439.82 * $score / 100, 2) ?> 439.82"
                                        stroke-linecap="round"
                                        transform="rotate(-90 80 80)"/>
                                <!-- Score number -->
                                <text x="80" y="72" text-anchor="middle" font-size="40" font-weight="700" fill="<?= $scoreColor ?>" font-family="Poppins, sans-serif"><?= $score ?></text>
                                <text x="80" y="95" text-anchor="middle" font-size="13" fill="#6B6B6B" font-family="Poppins, sans-serif">out of 100</text>
                            </svg>
                        </div>
                        <div class="score-interpretation">
                            <h3 style="color: <?= $scoreColor ?>"><?= $scoreLabel ?></h3>
                            <p><?= $scoreInterp ?></p>
                        </div>
                    </div>

                    <!-- Category Breakdown -->
                    <div class="score-breakdown">
                        <h3 class="breakdown-title">Score Breakdown</h3>

                        <div class="breakdown-item">
                            <div class="breakdown-header">
                                <span class="breakdown-name">Schema.org Presence</span>
                                <span class="breakdown-score"><?= $schemaScore ?> / 30</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= round($schemaScore / 30 * 100, 1) ?>%; background-color: <?= propScoreColour((int)round($schemaScore / 30 * 100)) ?>"></div>
                            </div>
                        </div>

                        <div class="breakdown-item">
                            <div class="breakdown-header">
                                <span class="breakdown-name">Entity Authority</span>
                                <span class="breakdown-score"><?= $entityScore ?> / 25</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= round($entityScore / 25 * 100, 1) ?>%; background-color: <?= propScoreColour((int)round($entityScore / 25 * 100)) ?>"></div>
                            </div>
                        </div>

                        <div class="breakdown-item">
                            <div class="breakdown-header">
                                <span class="breakdown-name">AI Discoverability</span>
                                <span class="breakdown-score"><?= $aiDiscScore ?> / 20</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= round($aiDiscScore / 20 * 100, 1) ?>%; background-color: <?= propScoreColour((int)round($aiDiscScore / 20 * 100)) ?>"></div>
                            </div>
                        </div>

                        <div class="breakdown-item">
                            <div class="breakdown-header">
                                <span class="breakdown-name">Technical Foundation</span>
                                <span class="breakdown-score"><?= $techScore ?> / 15</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= round($techScore / 15 * 100, 1) ?>%; background-color: <?= propScoreColour((int)round($techScore / 15 * 100)) ?>"></div>
                            </div>
                        </div>

                        <div class="breakdown-item">
                            <div class="breakdown-header">
                                <span class="breakdown-name">Booking Authority</span>
                                <span class="breakdown-score"><?= $bookingScore ?> / 10</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= round($bookingScore / 10 * 100, 1) ?>%; background-color: <?= propScoreColour((int)round($bookingScore / 10 * 100)) ?>"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Recommendations -->
                    <?php if (!empty($recommendations)): ?>
                    <div class="score-recommendations">
                        <h3 class="breakdown-title">Top Recommendations</h3>
                        <ol class="recommendations-list">
                            <?php foreach ($recommendations as $rec): ?>
                            <li>
                                <?php if (is_array($rec)): ?>
                                    <strong><?= htmlspecialchars($rec['title'] ?? $rec['category'] ?? 'Recommendation') ?></strong>
                                    <?php if (!empty($rec['description'])): ?>
                                    <p><?= htmlspecialchars($rec['description']) ?></p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($rec) ?>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                    <?php endif; ?>

                    <!-- Link to Full Report -->
                    <?php if ($audit): ?>
                    <div class="score-cta">
                        <a href="audit-report.php?id=<?= $pid ?>" class="btn btn-primary">View Full Entity Audit Report</a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Enquiry Form -->
                <div class="detail-card" id="enquiry">
                    <h2 class="detail-card-title">Send an Enquiry to <?= $name ?></h2>

                    <?php if ($enquiryMsg): ?>
                    <div class="alert alert-<?= $enquiryType ?>">
                        <?= htmlspecialchars($enquiryMsg) ?>
                    </div>
                    <?php endif; ?>

                    <form class="enquiry-form" action="enquiry.php" method="post">
                        <input type="hidden" name="property_id" value="<?= $pid ?>">

                        <div class="form-group">
                            <label for="visitor_name">Your Name <span class="required">*</span></label>
                            <input type="text" id="visitor_name" name="visitor_name" required placeholder="Full name" maxlength="255">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="visitor_email">Email Address <span class="required">*</span></label>
                                <input type="email" id="visitor_email" name="visitor_email" required placeholder="you@example.com" maxlength="255">
                            </div>
                            <div class="form-group">
                                <label for="visitor_phone">Phone <span class="optional">(optional)</span></label>
                                <input type="tel" id="visitor_phone" name="visitor_phone" placeholder="+27 12 345 6789" maxlength="100">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="message">Message <span class="required">*</span></label>
                            <textarea id="message" name="message" rows="5" required placeholder="Tell us about your trip plans, dates, and any questions..." maxlength="5000"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">Send Enquiry</button>
                    </form>
                </div>

            </div><!-- /.property-main -->

            <!-- Sidebar -->
            <aside class="property-sidebar">

                <!-- Quick Score Card -->
                <div class="sidebar-card score-sidebar-card">
                    <div class="sidebar-score-gauge">
                        <svg width="100" height="100" viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="42" fill="none" stroke="#E8E8E3" stroke-width="8"/>
                            <circle cx="50" cy="50" r="42" fill="none" stroke="<?= $scoreColor ?>" stroke-width="8"
                                    stroke-dasharray="<?= round(263.89 * $score / 100, 2) ?> 263.89"
                                    stroke-linecap="round"
                                    transform="rotate(-90 50 50)"/>
                            <text x="50" y="46" text-anchor="middle" font-size="26" font-weight="700" fill="<?= $scoreColor ?>" font-family="Poppins, sans-serif"><?= $score ?></text>
                            <text x="50" y="62" text-anchor="middle" font-size="9" fill="#6B6B6B" font-family="Poppins, sans-serif">AI Score</text>
                        </svg>
                    </div>
                    <p class="sidebar-score-label" style="color: <?= $scoreColor ?>"><?= $scoreLabel ?></p>
                    <a href="#ai-score" class="btn btn-outline btn-sm">View Full Breakdown</a>
                </div>

                <!-- Quick Links -->
                <?php if ($hasContact): ?>
                <div class="sidebar-card">
                    <h4>Quick Links</h4>
                    <ul class="sidebar-links">
                        <?php if (!empty($property['website'])): ?>
                        <li><a href="<?= htmlspecialchars($property['website']) ?>" target="_blank" rel="noopener">Visit Website</a></li>
                        <?php endif; ?>
                        <?php if (!empty($property['google_maps_url'])): ?>
                        <li><a href="<?= htmlspecialchars($property['google_maps_url']) ?>" target="_blank" rel="noopener">Google Maps</a></li>
                        <?php endif; ?>
                        <li><a href="#enquiry">Send Enquiry</a></li>
                        <?php if ($audit): ?>
                        <li><a href="audit-report.php?id=<?= $pid ?>">Full Audit Report</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Claim Listing CTA -->
                <div class="sidebar-card claim-card">
                    <h4>Is This Your Property?</h4>
                    <p>Claim this listing to update your details, respond to enquiries, and improve your AI readiness score.</p>
                    <a href="claim.php?id=<?= $pid ?>" class="btn btn-primary btn-sm">Claim This Listing</a>
                </div>

            </aside>
        </div>
    </div>
</section>

<?php
renderFooter('<script src="/assets/js/app.js"></script>');
