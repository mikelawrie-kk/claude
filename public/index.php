<?php
/**
 * Filename: index.php
 * Description: Public homepage - hero section, search, featured countries, latest audited properties
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../templates/layout.php';

// ---------------------------------------------------------------------------
// Data queries
// ---------------------------------------------------------------------------

try {
    $db = Database::getInstance();

    // Tier 1 countries with property counts
    $featuredCountries = Database::fetchAll(
        "SELECT c.id, c.name, c.iso_code, c.region, c.property_count, c.avg_ai_score
         FROM countries c
         WHERE c.tier = 1 AND c.is_active = 1
         ORDER BY c.property_count DESC, c.name ASC"
    );

    // Latest 8 audited properties with score > 0
    $latestProperties = Database::fetchAll(
        "SELECT p.id, p.name, p.slug, p.property_type, p.formatted_address,
                p.ai_readiness_score, p.google_rating, p.google_review_count,
                p.price_tier, c.name AS country_name, c.iso_code AS country_iso,
                rp.name AS reserve_name
         FROM properties p
         LEFT JOIN countries c ON p.country_id = c.id
         LEFT JOIN reserves_parks rp ON p.reserve_park_id = rp.id
         WHERE p.ai_readiness_score > 0 AND p.status = 'published'
         ORDER BY p.last_audited_at DESC
         LIMIT 8"
    );

    // All active countries for the dropdown
    $allCountries = Database::fetchAll(
        "SELECT id, name, iso_code
         FROM countries
         WHERE is_active = 1
         ORDER BY name ASC"
    );

    // Overall stats
    $totalProperties = Database::count('properties', "status = 'published'");
    $totalCountries  = Database::count('countries', 'is_active = 1 AND property_count > 0');
    $totalAudited    = Database::count('properties', 'ai_readiness_score > 0');

} catch (Throwable $e) {
    $featuredCountries = [];
    $latestProperties  = [];
    $allCountries      = [];
    $totalProperties   = 0;
    $totalCountries    = 0;
    $totalAudited      = 0;
    error_log('Homepage query error: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Helper: score colour
// ---------------------------------------------------------------------------
function getScoreColour(int $score): string
{
    if ($score >= 81) return '#3A7D44';
    if ($score >= 61) return '#5B9A3A';
    if ($score >= 31) return '#D4A84B';
    return '#C0392B';
}

function getScoreLabel(int $score): string
{
    if ($score >= 81) return 'Excellent';
    if ($score >= 61) return 'Good';
    if ($score >= 31) return 'Needs Work';
    return 'Poor';
}

function formatPropertyType(string $type): string
{
    return ucwords(str_replace('_', ' ', $type));
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

renderHeader('Safari Traveller - Discover Africa\'s Safari Lodges');
?>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-container">
        <h1 class="hero-title">Discover Africa's Safari Lodges <span class="hero-accent">&mdash; Rated for AI Visibility</span></h1>
        <p class="hero-subtitle">Safari Traveller audits every African safari lodge, camp, and guide for entity authority and AI readiness. We show you who's visible to AI assistants, search engines, and travellers &mdash; and who's being left behind.</p>

        <!-- Search Bar -->
        <form class="hero-search" action="search.php" method="get">
            <div class="search-fields">
                <div class="search-input-wrap">
                    <input type="text" name="q" placeholder="Search lodges, camps, reserves..." aria-label="Search properties" class="search-input" autocomplete="off">
                </div>
                <div class="search-select-wrap">
                    <select name="country" aria-label="Filter by country" class="search-select">
                        <option value="">All Countries</option>
                        <?php foreach ($allCountries as $c): ?>
                        <option value="<?= htmlspecialchars($c['iso_code']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="search-btn">Search</button>
            </div>
        </form>

        <!-- Quick Stats -->
        <div class="hero-stats">
            <div class="hero-stat">
                <span class="hero-stat-num"><?= number_format($totalProperties) ?></span>
                <span class="hero-stat-label">Properties Listed</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-num"><?= number_format($totalCountries) ?></span>
                <span class="hero-stat-label">African Countries</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-num"><?= number_format($totalAudited) ?></span>
                <span class="hero-stat-label">AI Audits Completed</span>
            </div>
        </div>
    </div>
</section>

<!-- Featured Countries -->
<?php if (!empty($featuredCountries)): ?>
<section class="section featured-countries">
    <div class="section-container">
        <h2 class="section-title">Explore by Country</h2>
        <p class="section-subtitle">Start with Africa's top safari destinations. Each country page shows every audited lodge, camp, and operator.</p>

        <div class="country-grid">
            <?php foreach ($featuredCountries as $country): ?>
            <a href="country.php?slug=<?= htmlspecialchars($country['iso_code']) ?>" class="country-card">
                <div class="country-card-inner">
                    <div class="country-flag"><?= htmlspecialchars($country['iso_code']) ?></div>
                    <h3 class="country-card-name"><?= htmlspecialchars($country['name']) ?></h3>
                    <p class="country-card-region"><?= htmlspecialchars($country['region']) ?></p>
                    <div class="country-card-stats">
                        <span class="country-card-count"><?= number_format((int)$country['property_count']) ?> <?= (int)$country['property_count'] === 1 ? 'property' : 'properties' ?></span>
                        <?php if ((float)$country['avg_ai_score'] > 0): ?>
                        <span class="country-card-score">Avg Score: <strong><?= number_format((float)$country['avg_ai_score'], 1) ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Latest Audited Properties -->
<?php if (!empty($latestProperties)): ?>
<section class="section latest-properties">
    <div class="section-container">
        <h2 class="section-title">Recently Audited Properties</h2>
        <p class="section-subtitle">The latest safari lodges and camps to receive an AI readiness audit.</p>

        <div class="property-grid">
            <?php foreach ($latestProperties as $prop): ?>
            <?php
                $score      = (int)$prop['ai_readiness_score'];
                $scoreColor = getScoreColour($score);
                $scoreLabel = getScoreLabel($score);
                $propLink   = 'property.php?id=' . (int)$prop['id'];
                if (!empty($prop['slug'])) {
                    $propLink = 'property.php?slug=' . urlencode($prop['slug']);
                }
            ?>
            <a href="<?= $propLink ?>" class="property-card">
                <div class="property-card-header">
                    <h3 class="property-card-name"><?= htmlspecialchars($prop['name']) ?></h3>
                    <span class="property-card-type"><?= formatPropertyType($prop['property_type']) ?></span>
                </div>
                <div class="property-card-location">
                    <?php if (!empty($prop['reserve_name'])): ?>
                    <span class="property-card-reserve"><?= htmlspecialchars($prop['reserve_name']) ?></span> &middot;
                    <?php endif; ?>
                    <span class="property-card-country"><?= htmlspecialchars($prop['country_name']) ?></span>
                </div>
                <div class="property-card-footer">
                    <!-- Mini Score Gauge -->
                    <div class="mini-gauge" title="AI Readiness Score: <?= $score ?>/100">
                        <svg width="48" height="48" viewBox="0 0 48 48">
                            <circle cx="24" cy="24" r="20" fill="none" stroke="#E8E8E3" stroke-width="4"/>
                            <circle cx="24" cy="24" r="20" fill="none" stroke="<?= $scoreColor ?>" stroke-width="4"
                                    stroke-dasharray="<?= round(125.66 * $score / 100, 2) ?> 125.66"
                                    stroke-linecap="round"
                                    transform="rotate(-90 24 24)"/>
                            <text x="24" y="28" text-anchor="middle" font-size="13" font-weight="600" fill="<?= $scoreColor ?>"><?= $score ?></text>
                        </svg>
                    </div>
                    <div class="property-card-meta">
                        <?php if ($prop['google_rating'] > 0): ?>
                        <span class="property-card-rating">
                            <span class="star">&#9733;</span> <?= number_format((float)$prop['google_rating'], 1) ?>
                            <span class="review-count">(<?= number_format((int)$prop['google_review_count']) ?>)</span>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($prop['price_tier'])): ?>
                        <span class="property-card-price"><?= formatPropertyType($prop['price_tier']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="section-cta">
            <a href="search.php?sort=score" class="btn btn-outline">View All Audited Properties</a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- AI Visibility CTA -->
<section class="section cta-section">
    <div class="section-container">
        <div class="cta-card">
            <h2 class="cta-title">How Visible Is Your Lodge to AI?</h2>
            <p class="cta-text">When travellers ask ChatGPT, Gemini, or Perplexity for safari recommendations, does your lodge come up? Our Entity Authority Audit analyses your structured data, schema markup, and digital footprint to give you a clear score out of 100.</p>
            <div class="cta-features">
                <div class="cta-feature">
                    <div class="cta-feature-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4A84B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                    </div>
                    <div>
                        <strong>Schema.org Analysis</strong>
                        <p>We check your JSON-LD structured data against the LodgingBusiness standard.</p>
                    </div>
                </div>
                <div class="cta-feature">
                    <div class="cta-feature-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4A84B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    </div>
                    <div>
                        <strong>Entity Authority Score</strong>
                        <p>Measures whether AI systems can verify your lodge is a real, authoritative entity.</p>
                    </div>
                </div>
                <div class="cta-feature">
                    <div class="cta-feature-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4A84B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    </div>
                    <div>
                        <strong>Actionable Recommendations</strong>
                        <p>Specific code snippets and fixes you can implement today.</p>
                    </div>
                </div>
            </div>
            <div class="cta-actions">
                <a href="search.php" class="btn btn-primary">Find Your Lodge</a>
                <a href="claim.php" class="btn btn-outline">Claim Your Listing</a>
            </div>
        </div>
    </div>
</section>

<!-- How It Works -->
<section class="section how-it-works">
    <div class="section-container">
        <h2 class="section-title">How Safari Traveller Works</h2>
        <div class="steps-grid">
            <div class="step-card">
                <div class="step-num">1</div>
                <h3>Discover</h3>
                <p>We systematically find every safari lodge, camp, and operator across all 54 African countries using multiple data sources.</p>
            </div>
            <div class="step-card">
                <div class="step-num">2</div>
                <h3>Audit</h3>
                <p>Each property's website is analysed for structured data, entity authority signals, and AI discoverability across 30+ criteria.</p>
            </div>
            <div class="step-card">
                <div class="step-num">3</div>
                <h3>Score</h3>
                <p>Properties receive an AI Readiness Score out of 100, with a detailed breakdown across five categories.</p>
            </div>
            <div class="step-card">
                <div class="step-num">4</div>
                <h3>Improve</h3>
                <p>Every property gets specific, actionable recommendations with code snippets to boost their visibility to AI systems.</p>
            </div>
        </div>
    </div>
</section>

<?php
renderFooter();
