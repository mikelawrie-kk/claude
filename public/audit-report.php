<?php
/**
 * Filename: audit-report.php
 * Description: Full entity authority audit report page with detailed scoring, schema analysis, and recommendations
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../templates/layout.php';

// ---------------------------------------------------------------------------
// Lookup property
// ---------------------------------------------------------------------------

$propertyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$property = false;
$audit    = false;

try {
    if ($propertyId > 0) {
        $property = Database::fetch(
            "SELECT p.*, c.name AS country_name, c.iso_code AS country_iso,
                    c.avg_ai_score AS country_avg_score,
                    rp.name AS reserve_name
             FROM properties p
             LEFT JOIN countries c ON p.country_id = c.id
             LEFT JOIN reserves_parks rp ON p.reserve_park_id = rp.id
             WHERE p.id = ?",
            [$propertyId]
        );

        if ($property) {
            $audit = Database::fetch(
                "SELECT * FROM property_audit
                 WHERE property_id = ?
                 ORDER BY audited_at DESC
                 LIMIT 1",
                [$propertyId]
            );
        }
    }
} catch (Throwable $e) {
    error_log('Audit report lookup error: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 404 if not found
// ---------------------------------------------------------------------------

if (!$property || !$audit) {
    http_response_code(404);
    renderHeader('Audit Report Not Found - Safari Traveller');
    ?>
    <section class="section">
        <div class="section-container error-page">
            <h1>Audit Report Not Found</h1>
            <?php if ($property && !$audit): ?>
            <p>This property has not been audited yet. Check back soon.</p>
            <a href="property.php?id=<?= (int)$property['id'] ?>" class="btn btn-primary">View Property</a>
            <?php else: ?>
            <p>The property or audit report you are looking for does not exist.</p>
            <a href="/public/" class="btn btn-primary">Return to Homepage</a>
            <?php endif; ?>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function auditScoreColour(int $score): string
{
    if ($score >= 81) return '#3A7D44';
    if ($score >= 61) return '#5B9A3A';
    if ($score >= 31) return '#D4A84B';
    return '#C0392B';
}

function auditScoreLabel(int $score): string
{
    if ($score >= 81) return 'Excellent AI Readiness';
    if ($score >= 61) return 'Good AI Readiness';
    if ($score >= 31) return 'Moderate AI Readiness';
    return 'Low AI Readiness';
}

function auditScoreInterpretation(int $score): string
{
    if ($score >= 81) return 'This property has strong entity authority and is well-positioned for AI-driven discovery. AI assistants are likely to recommend it when travellers search for safari options in this area.';
    if ($score >= 61) return 'This property has a good digital foundation but is missing some structured data elements that would significantly boost AI visibility. The recommendations below can help close the gap.';
    if ($score >= 31) return 'This property has some online presence but lacks critical schema markup and entity authority signals. Without improvements, AI assistants will struggle to recommend it over competitors.';
    return 'This property has minimal structured data and entity authority. It is largely invisible to AI systems. Implementing the recommendations below is essential for being discovered by AI-powered travel assistants.';
}

function auditFormatType(string $type): string
{
    return ucwords(str_replace('_', ' ', $type));
}

// ---------------------------------------------------------------------------
// Prepare data
// ---------------------------------------------------------------------------

$pid        = (int)$property['id'];
$name       = htmlspecialchars($property['name']);
$score      = (int)$audit['total_score'];
$scoreColor = auditScoreColour($score);
$scoreLabel = auditScoreLabel($score);
$scoreInterp = auditScoreInterpretation($score);

$schemaScore  = (int)$audit['schema_score'];
$entityScore  = (int)$audit['entity_authority_score'];
$aiDiscScore  = (int)$audit['ai_discoverability_score'];
$techScore    = (int)$audit['technical_score'];
$bookingScore = (int)$audit['booking_authority_score'];

// Parse JSON fields
$scoreBreakdown  = !empty($audit['score_breakdown']) ? json_decode($audit['score_breakdown'], true) : [];
$findings        = !empty($audit['findings']) ? json_decode($audit['findings'], true) : [];
$recommendations = !empty($audit['recommendations']) ? json_decode($audit['recommendations'], true) : [];
$schemaFound     = !empty($audit['schema_found']) ? json_decode($audit['schema_found'], true) : [];
$otaComparison   = !empty($audit['ota_comparison']) ? json_decode($audit['ota_comparison'], true) : [];
$competitorComp  = !empty($audit['competitor_comparison']) ? json_decode($audit['competitor_comparison'], true) : [];

// Country average score
$countryAvgScore = (float)($property['country_avg_score'] ?? 0);
$countryName     = htmlspecialchars($property['country_name'] ?? 'this country');

// ---------------------------------------------------------------------------
// Scoring criteria definitions
// ---------------------------------------------------------------------------

$categories = [
    'schema' => [
        'name'  => 'Schema.org Presence',
        'max'   => 30,
        'score' => $schemaScore,
        'criteria' => [
            ['name' => 'Has ANY structured data',              'max' => 5, 'key' => 'has_structured_data'],
            ['name' => 'Uses JSON-LD format',                  'max' => 3, 'key' => 'uses_jsonld'],
            ['name' => 'Has LodgingBusiness/Hotel/Resort type','max' => 5, 'key' => 'has_lodging_type'],
            ['name' => 'Has room/accommodation markup',        'max' => 4, 'key' => 'has_room_markup'],
            ['name' => 'Has address + geo in schema',          'max' => 3, 'key' => 'has_address_geo'],
            ['name' => 'Has aggregateRating in schema',        'max' => 3, 'key' => 'has_aggregate_rating'],
            ['name' => 'Has Offer/priceRange markup',          'max' => 3, 'key' => 'has_price_markup'],
            ['name' => 'Has FAQPage schema',                   'max' => 2, 'key' => 'has_faq_schema'],
            ['name' => 'Has BreadcrumbList',                   'max' => 2, 'key' => 'has_breadcrumb'],
        ],
    ],
    'entity' => [
        'name'  => 'Entity Authority',
        'max'   => 25,
        'score' => $entityScore,
        'criteria' => [
            ['name' => '@id uses canonical URL',        'max' => 5, 'key' => 'has_canonical_id'],
            ['name' => 'mainEntityOfPage declared',     'max' => 5, 'key' => 'has_main_entity'],
            ['name' => 'sameAs includes social profiles','max' => 3, 'key' => 'has_social_sameas'],
            ['name' => 'sameAs includes OTA listings',  'max' => 4, 'key' => 'has_ota_sameas'],
            ['name' => 'sameAs includes Wikipedia/Wikidata','max' => 5, 'key' => 'has_wiki_sameas'],
            ['name' => 'potentialAction with ReserveAction','max' => 3, 'key' => 'has_reserve_action'],
        ],
    ],
    'ai_discoverability' => [
        'name'  => 'AI Discoverability',
        'max'   => 20,
        'score' => $aiDiscScore,
        'criteria' => [
            ['name' => 'Has LLM.txt file',                     'max' => 5, 'key' => 'has_llm_txt'],
            ['name' => 'robots.txt allows AI crawlers',         'max' => 3, 'key' => 'robots_allows_ai'],
            ['name' => 'Content length >2,000 words',           'max' => 4, 'key' => 'content_length_ok'],
            ['name' => 'FAQ content present',                   'max' => 4, 'key' => 'has_faq_content'],
            ['name' => 'Blog/content hub with regular updates', 'max' => 4, 'key' => 'has_blog'],
        ],
    ],
    'technical' => [
        'name'  => 'Technical Foundation',
        'max'   => 15,
        'score' => $techScore,
        'criteria' => [
            ['name' => 'HTTPS',                          'max' => 2, 'key' => 'is_https'],
            ['name' => 'Mobile responsive',              'max' => 3, 'key' => 'is_mobile_responsive'],
            ['name' => 'Page speed <3 seconds',          'max' => 3, 'key' => 'page_speed_ok'],
            ['name' => 'Has sitemap.xml',                'max' => 2, 'key' => 'has_sitemap'],
            ['name' => 'Google Business Profile exists', 'max' => 3, 'key' => 'has_gbp'],
            ['name' => 'GBP has >10 reviews',            'max' => 2, 'key' => 'gbp_reviews_ok'],
        ],
    ],
    'booking' => [
        'name'  => 'Booking Authority',
        'max'   => 10,
        'score' => $bookingScore,
        'criteria' => [
            ['name' => 'Has own booking engine',         'max' => 5, 'key' => 'has_booking_engine'],
            ['name' => 'Direct booking CTA visible',     'max' => 3, 'key' => 'has_direct_booking_cta'],
            ['name' => 'tourBookingPage property used',   'max' => 2, 'key' => 'has_tour_booking_page'],
        ],
    ],
];

// ---------------------------------------------------------------------------
// Generate recommended JSON-LD
// ---------------------------------------------------------------------------

$websiteUrl = htmlspecialchars($property['website'] ?? 'https://www.example-lodge.com');
$propAddress = htmlspecialchars($property['formatted_address'] ?? '');
$propLat     = $property['latitude'] ? (float)$property['latitude'] : -24.0;
$propLng     = $property['longitude'] ? (float)$property['longitude'] : 31.5;
$propRating  = (float)($property['google_rating'] ?? 0);
$propReviews = (int)($property['google_review_count'] ?? 0);
$propPhone   = htmlspecialchars($property['international_phone'] ?? $property['phone'] ?? '');
$propEmail   = htmlspecialchars($property['email'] ?? '');
$rawName     = $property['name'];

$schemaType = 'LodgingBusiness';
$pType = $property['property_type'] ?? 'lodge';
if (in_array($pType, ['hotel', 'resort'])) {
    $schemaType = ucfirst($pType);
}

$recommendedSchema = [
    '@context'        => 'https://schema.org',
    '@type'           => $schemaType,
    '@id'             => $property['website'] ?? ('https://safari-traveller.com/public/property.php?id=' . $pid),
    'name'            => $rawName,
    'description'     => $property['short_description'] ?? substr($property['description'] ?? '', 0, 250),
    'url'             => $property['website'] ?? ('https://safari-traveller.com/public/property.php?id=' . $pid),
    'telephone'       => $property['international_phone'] ?? $property['phone'] ?? '',
    'email'           => $property['email'] ?? '',
    'address'         => [
        '@type'           => 'PostalAddress',
        'addressCountry'  => $property['country_iso'] ?? '',
        'addressLocality' => $property['reserve_name'] ?? '',
        'streetAddress'   => $property['formatted_address'] ?? '',
    ],
    'geo'             => [
        '@type'     => 'GeoCoordinates',
        'latitude'  => $propLat,
        'longitude' => $propLng,
    ],
    'mainEntityOfPage' => [
        '@type' => 'WebPage',
        '@id'   => $property['website'] ?? ('https://safari-traveller.com/public/property.php?id=' . $pid),
    ],
    'sameAs'           => [],
    'potentialAction'  => [
        '@type'  => 'ReserveAction',
        'target' => ($property['website'] ?? '') . '/book',
        'name'   => 'Book Now',
    ],
];

if ($propRating > 0 && $propReviews > 0) {
    $recommendedSchema['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => number_format($propRating, 1),
        'reviewCount' => $propReviews,
        'bestRating'  => '5',
        'worstRating' => '1',
    ];
}

if (!empty($property['price_tier'])) {
    $priceMap = [
        'budget'       => '$',
        'mid'          => '$$',
        'luxury'       => '$$$',
        'ultra_luxury' => '$$$$',
    ];
    $recommendedSchema['priceRange'] = $priceMap[$property['price_tier']] ?? '$$';
}

// Remove empty fields for cleaner output
if (empty($recommendedSchema['telephone'])) unset($recommendedSchema['telephone']);
if (empty($recommendedSchema['email'])) unset($recommendedSchema['email']);
if (empty($recommendedSchema['address']['addressLocality'])) unset($recommendedSchema['address']['addressLocality']);

$recommendedSchemaJson = json_encode($recommendedSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

renderHeader('Entity Audit: ' . $property['name'] . ' - Safari Traveller');
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
                <li><a href="/public/property.php?id=<?= $pid ?>"><?= $name ?></a></li>
                <li aria-current="page">Entity Audit Report</li>
            </ol>
        </nav>
    </div>
</section>

<!-- Report Header -->
<section class="section audit-header-section">
    <div class="section-container">
        <div class="audit-header">
            <div class="audit-header-info">
                <h1 class="audit-title">Entity Authority Audit</h1>
                <h2 class="audit-property-name"><?= $name ?></h2>
                <?php if (!empty($property['reserve_name']) || !empty($property['country_name'])): ?>
                <p class="audit-property-location">
                    <?php if (!empty($property['reserve_name'])): ?>
                    <?= htmlspecialchars($property['reserve_name']) ?> &middot;
                    <?php endif; ?>
                    <?= htmlspecialchars($property['country_name'] ?? '') ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($audit['audited_at'])): ?>
                <p class="audit-date">Audited: <?= date('j F Y', strtotime($audit['audited_at'])) ?></p>
                <?php endif; ?>
            </div>
            <div class="audit-header-score">
                <svg width="140" height="140" viewBox="0 0 140 140">
                    <circle cx="70" cy="70" r="60" fill="none" stroke="#E8E8E3" stroke-width="10"/>
                    <circle cx="70" cy="70" r="60" fill="none" stroke="<?= $scoreColor ?>" stroke-width="10"
                            stroke-dasharray="<?= round(376.99 * $score / 100, 2) ?> 376.99"
                            stroke-linecap="round"
                            transform="rotate(-90 70 70)"/>
                    <text x="70" y="64" text-anchor="middle" font-size="36" font-weight="700" fill="<?= $scoreColor ?>" font-family="Poppins, sans-serif"><?= $score ?></text>
                    <text x="70" y="84" text-anchor="middle" font-size="11" fill="#6B6B6B" font-family="Poppins, sans-serif">out of 100</text>
                </svg>
                <p class="audit-score-label" style="color: <?= $scoreColor ?>"><?= $scoreLabel ?></p>
            </div>
        </div>
        <div class="audit-interpretation">
            <p><?= $scoreInterp ?></p>
        </div>
    </div>
</section>

<!-- Detailed Scoring Breakdown -->
<section class="section audit-breakdown-section">
    <div class="section-container">
        <h2 class="section-title">Detailed Scoring Breakdown</h2>

        <?php foreach ($categories as $catKey => $category): ?>
        <div class="audit-category-card">
            <div class="category-header">
                <h3 class="category-name"><?= htmlspecialchars($category['name']) ?></h3>
                <div class="category-score-wrap">
                    <span class="category-score" style="color: <?= auditScoreColour((int)round($category['score'] / $category['max'] * 100)) ?>"><?= $category['score'] ?> / <?= $category['max'] ?></span>
                </div>
            </div>
            <div class="category-progress">
                <div class="progress-bar progress-bar-lg">
                    <div class="progress-fill" style="width: <?= round($category['score'] / $category['max'] * 100, 1) ?>%; background-color: <?= auditScoreColour((int)round($category['score'] / $category['max'] * 100)) ?>"></div>
                </div>
            </div>

            <div class="criteria-list">
                <?php foreach ($category['criteria'] as $criterion): ?>
                <?php
                    // Look up earned points from score_breakdown JSON
                    $earned = 0;
                    if (is_array($scoreBreakdown) && isset($scoreBreakdown[$criterion['key']])) {
                        $earned = (int)$scoreBreakdown[$criterion['key']];
                    }
                    $passed = $earned > 0;
                ?>
                <div class="criterion-row <?= $passed ? 'criterion-pass' : 'criterion-fail' ?>">
                    <span class="criterion-icon"><?= $passed ? '&#10003;' : '&#10007;' ?></span>
                    <span class="criterion-name"><?= htmlspecialchars($criterion['name']) ?></span>
                    <span class="criterion-score"><?= $earned ?> / <?= $criterion['max'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Schema.org Markup Found -->
<section class="section audit-schema-section">
    <div class="section-container">
        <h2 class="section-title">Schema.org Markup Found</h2>

        <?php if (!empty($schemaFound)): ?>
        <div class="audit-info-card">
            <p>The following structured data was detected on this property's website:</p>
            <div class="schema-code-block">
                <div class="code-block-header">
                    <span>JSON-LD Structured Data</span>
                </div>
                <pre class="code-block"><code><?= htmlspecialchars(json_encode($schemaFound, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></code></pre>
            </div>
        </div>
        <?php else: ?>
        <div class="audit-warning-card">
            <h3>No Structured Data Found</h3>
            <p>This property's website does not contain any detectable JSON-LD or Schema.org structured data. This is a significant gap because:</p>
            <ul>
                <li>AI assistants rely on structured data to understand what a business is and what it offers</li>
                <li>Google uses Schema.org markup to generate rich search results</li>
                <li>Without a <code>LodgingBusiness</code> schema, search engines and AI systems cannot properly categorise this property</li>
                <li>OTAs like Booking.com and TripAdvisor add their own schema markup about this property -- meaning they control the entity definition</li>
            </ul>
            <p>See the <a href="#recommended-code">Recommended Code</a> section below for the exact JSON-LD this property should add.</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- What Competitors Have -->
<section class="section audit-competitors-section">
    <div class="section-container">
        <h2 class="section-title">What Competitors Have</h2>
        <p class="section-subtitle">Based on analysis of similar properties in <?= $countryName ?></p>

        <div class="audit-info-card">
            <div class="competitor-stats-grid">
                <div class="competitor-stat">
                    <span class="competitor-stat-label">Country Average AI Score</span>
                    <span class="competitor-stat-value" style="color: <?= auditScoreColour((int)round($countryAvgScore)) ?>"><?= number_format($countryAvgScore, 1) ?> / 100</span>
                </div>
                <div class="competitor-stat">
                    <span class="competitor-stat-label">This Property</span>
                    <span class="competitor-stat-value" style="color: <?= $scoreColor ?>"><?= $score ?> / 100</span>
                </div>
                <div class="competitor-stat">
                    <span class="competitor-stat-label">Difference</span>
                    <?php
                    $diff = $score - $countryAvgScore;
                    $diffColor = $diff >= 0 ? '#3A7D44' : '#C0392B';
                    $diffSign  = $diff >= 0 ? '+' : '';
                    ?>
                    <span class="competitor-stat-value" style="color: <?= $diffColor ?>"><?= $diffSign ?><?= number_format($diff, 1) ?></span>
                </div>
            </div>

            <?php if (!empty($competitorComp) && is_array($competitorComp)): ?>
            <div class="competitor-insights">
                <h4>What Top-Scoring Properties Have That This One Doesn't</h4>
                <ul>
                    <?php foreach ($competitorComp as $insight): ?>
                    <li><?= htmlspecialchars(is_string($insight) ? $insight : ($insight['description'] ?? $insight['text'] ?? '')) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php else: ?>
            <div class="competitor-insights">
                <h4>Common Traits of Top-Scoring Properties</h4>
                <ul>
                    <li>Complete <code>LodgingBusiness</code> JSON-LD schema with <code>@id</code>, <code>mainEntityOfPage</code>, and <code>sameAs</code> properties</li>
                    <li>Room-level structured data with <code>Offer</code> markup for pricing</li>
                    <li><code>AggregateRating</code> included in schema from verified review platforms</li>
                    <li>An <code>llm.txt</code> file making their property explicitly discoverable by AI</li>
                    <li>Active blog or content hub with regularly updated, long-form content</li>
                    <li>Direct booking engine with <code>ReserveAction</code> potential action markup</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- OTA vs Own-Site Comparison -->
<section class="section audit-ota-section">
    <div class="section-container">
        <h2 class="section-title">OTA vs Own-Site Comparison</h2>
        <p class="section-subtitle">Who has more structured data about your property?</p>

        <div class="audit-info-card">
            <div class="ota-explanation">
                <p>Online Travel Agencies (OTAs) like Booking.com, Expedia, and TripAdvisor create their own structured data about your property. When they do, they set the <code>@id</code> property to <strong>their URL</strong>, not yours. This means AI systems may associate your property's entity with the OTA rather than your own website.</p>

                <div class="ota-example">
                    <h4>How OTAs Define Your Entity</h4>
                    <div class="ota-comparison-grid">
                        <div class="ota-column ota-column-bad">
                            <h5>OTA's Schema (Controls Your Entity)</h5>
                            <pre class="code-block code-block-sm"><code>{
  "@type": "LodgingBusiness",
  "@id": "https://www.booking.com/hotel/<?= strtolower($property['country_iso'] ?? 'za') ?>/<?= urlencode(strtolower(str_replace(' ', '-', $rawName))) ?>.html",
  "name": "<?= htmlspecialchars($rawName) ?>"
}</code></pre>
                            <p class="ota-note">The OTA claims entity ownership via <code>@id</code></p>
                        </div>
                        <div class="ota-column ota-column-good">
                            <h5>Your Schema (Should Control Your Entity)</h5>
                            <pre class="code-block code-block-sm"><code>{
  "@type": "<?= $schemaType ?>",
  "@id": "<?= htmlspecialchars($property['website'] ?? 'https://www.yourlodge.com') ?>",
  "name": "<?= htmlspecialchars($rawName) ?>",
  "mainEntityOfPage": {
    "@id": "<?= htmlspecialchars($property['website'] ?? 'https://www.yourlodge.com') ?>"
  }
}</code></pre>
                            <p class="ota-note">You claim entity ownership with your own <code>@id</code></p>
                        </div>
                    </div>
                </div>

                <?php if (!empty($otaComparison) && is_array($otaComparison)): ?>
                <div class="ota-findings">
                    <h4>Findings for <?= $name ?></h4>
                    <ul>
                        <?php foreach ($otaComparison as $finding): ?>
                        <li><?= htmlspecialchars(is_string($finding) ? $finding : ($finding['description'] ?? $finding['text'] ?? '')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <div class="ota-findings">
                    <h4>Why This Matters</h4>
                    <ul>
                        <li>If an OTA has better structured data about your property than your own website does, AI systems will reference the OTA as the authoritative source</li>
                        <li>When a traveller asks an AI assistant to book your lodge, the AI may direct them to the OTA (earning them a commission) instead of your direct booking page</li>
                        <li>By adding comprehensive Schema.org markup to your own site, you reclaim entity authority and direct bookings</li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Specific Code Recommendations -->
<section class="section audit-code-section" id="recommended-code">
    <div class="section-container">
        <h2 class="section-title">Recommended Schema.org Code</h2>
        <p class="section-subtitle">Add this JSON-LD to the <code>&lt;head&gt;</code> of your homepage to establish entity authority.</p>

        <div class="audit-info-card">
            <div class="schema-code-block">
                <div class="code-block-header">
                    <span>Recommended JSON-LD for <?= $name ?></span>
                    <button class="copy-btn" id="copySchemaBtn" data-copy-target="recommendedSchema" title="Copy to clipboard">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                        Copy
                    </button>
                </div>
                <pre class="code-block code-block-highlight" id="recommendedSchema"><code>&lt;script type="application/ld+json"&gt;
<?= htmlspecialchars($recommendedSchemaJson) ?>
&lt;/script&gt;</code></pre>
            </div>

            <div class="code-instructions">
                <h4>How to Add This Code</h4>
                <ol>
                    <li>Copy the code block above</li>
                    <li>Open your website's homepage HTML template</li>
                    <li>Paste it inside the <code>&lt;head&gt;</code> section, before the closing <code>&lt;/head&gt;</code> tag</li>
                    <li>Update any placeholder values (e.g., booking URL, social profiles in <code>sameAs</code>)</li>
                    <li>Test with <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">Google's Rich Results Test</a></li>
                    <li>Also add an <code>llm.txt</code> file to your website root for AI crawler discovery</li>
                </ol>
            </div>
        </div>

        <!-- All Recommendations -->
        <?php if (!empty($recommendations)): ?>
        <div class="audit-info-card">
            <h3>All Recommendations</h3>
            <div class="recommendations-full">
                <?php foreach ($recommendations as $index => $rec): ?>
                <div class="recommendation-item">
                    <span class="recommendation-num"><?= $index + 1 ?></span>
                    <div class="recommendation-content">
                        <?php if (is_array($rec)): ?>
                            <strong><?= htmlspecialchars($rec['title'] ?? $rec['category'] ?? 'Recommendation') ?></strong>
                            <?php if (!empty($rec['description'])): ?>
                            <p><?= htmlspecialchars($rec['description']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($rec['priority'])): ?>
                            <span class="recommendation-priority priority-<?= htmlspecialchars($rec['priority']) ?>"><?= htmlspecialchars(ucfirst($rec['priority'])) ?> Priority</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <p><?= htmlspecialchars($rec) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Safari Web Online CTA -->
<section class="section audit-cta-section">
    <div class="section-container">
        <div class="cta-card cta-card-dark">
            <h2 class="cta-title">Want Help Fixing This?</h2>
            <p class="cta-text">Safari Web Online specialises in entity authority optimisation for African safari properties. We can implement all the recommendations in this report, set up your Schema.org markup, create your <code>llm.txt</code> file, and ensure AI assistants recommend your lodge.</p>
            <div class="cta-actions">
                <a href="https://safariwebonline.com" target="_blank" rel="noopener" class="btn btn-primary">Talk to Safari Web Online</a>
                <a href="property.php?id=<?= $pid ?>" class="btn btn-outline">Back to Property</a>
            </div>
        </div>
    </div>
</section>

<script>
// Copy button functionality
document.addEventListener('DOMContentLoaded', function() {
    var copyBtn = document.getElementById('copySchemaBtn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-copy-target');
            var codeBlock = document.getElementById(targetId);
            if (codeBlock) {
                var text = codeBlock.textContent || codeBlock.innerText;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function() {
                        copyBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg> Copied!';
                        setTimeout(function() {
                            copyBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copy';
                        }, 2000);
                    });
                } else {
                    // Fallback
                    var textarea = document.createElement('textarea');
                    textarea.value = text;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function() {
                        copyBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copy';
                    }, 2000);
                }
            }
        });
    }
});
</script>

<?php
renderFooter();
