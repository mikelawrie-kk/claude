<?php
/**
 * Filename: country.php
 * Description: Country landing page with reserves, paginated properties, stats sidebar, and sorting
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../templates/layout.php';

// ---------------------------------------------------------------------------
// Lookup country by id or iso_code (slug)
// ---------------------------------------------------------------------------

$countryId   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$countrySlug = isset($_GET['slug']) ? strtoupper(trim($_GET['slug'])) : '';

$country = false;

try {
    if ($countryId > 0) {
        $country = Database::fetch(
            "SELECT * FROM countries WHERE id = ? AND is_active = 1",
            [$countryId]
        );
    } elseif ($countrySlug !== '') {
        $country = Database::fetch(
            "SELECT * FROM countries WHERE iso_code = ? AND is_active = 1",
            [$countrySlug]
        );
    }
} catch (Throwable $e) {
    error_log('Country lookup error: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 404 if not found
// ---------------------------------------------------------------------------

if (!$country) {
    http_response_code(404);
    renderHeader('Country Not Found - Safari Traveller');
    ?>
    <section class="section">
        <div class="section-container error-page">
            <h1>Country Not Found</h1>
            <p>The country you are looking for does not exist or is not yet active on Safari Traveller.</p>
            <a href="/public/" class="btn btn-primary">Return to Homepage</a>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function countryScoreColour(int $score): string
{
    if ($score >= 81) return '#3A7D44';
    if ($score >= 61) return '#5B9A3A';
    if ($score >= 31) return '#D4A84B';
    return '#C0392B';
}

function countryFormatType(string $type): string
{
    return ucwords(str_replace('_', ' ', $type));
}

function countryRenderStars(float $rating): string
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

function countryFormatParkType(string $type): string
{
    $labels = [
        'national_park'    => 'National Park',
        'game_reserve'     => 'Game Reserve',
        'conservancy'      => 'Conservancy',
        'private_reserve'  => 'Private Reserve',
        'marine_park'      => 'Marine Park',
        'forest_reserve'   => 'Forest Reserve',
    ];
    return $labels[$type] ?? countryFormatType($type);
}

// ---------------------------------------------------------------------------
// Prepare data
// ---------------------------------------------------------------------------

$cid         = (int)$country['id'];
$countryName = htmlspecialchars($country['name']);
$countryIso  = htmlspecialchars($country['iso_code']);
$countryRegion = htmlspecialchars($country['region'] ?? '');

// Pagination
$perPage     = 20;
$currentPage = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1);
$offset      = ($currentPage - 1) * $perPage;

// Sorting
$allowedSorts = [
    'score'  => 'p.ai_readiness_score DESC',
    'rating' => 'p.google_rating DESC',
    'name'   => 'p.name ASC',
];
$sortParam = isset($_GET['sort']) ? trim($_GET['sort']) : 'score';
$sortOrder = $allowedSorts[$sortParam] ?? $allowedSorts['score'];
$currentSort = array_key_exists($sortParam, $allowedSorts) ? $sortParam : 'score';

try {
    // Reserves and parks in this country
    $reserves = Database::fetchAll(
        "SELECT rp.*,
                (SELECT COUNT(*) FROM properties pr WHERE pr.reserve_park_id = rp.id AND pr.status = 'published') AS prop_count
         FROM reserves_parks rp
         WHERE rp.country_id = ?
         ORDER BY rp.name ASC",
        [$cid]
    );

    // Total published properties in country
    $totalProperties = Database::count('properties', "country_id = ? AND status = 'published'", [$cid]);
    $totalPages      = max(1, (int)ceil($totalProperties / $perPage));

    // Properties with pagination and sorting
    $properties = Database::fetchAll(
        "SELECT p.id, p.name, p.slug, p.property_type, p.formatted_address,
                p.ai_readiness_score, p.google_rating, p.google_review_count,
                p.price_tier, p.room_count,
                rp.name AS reserve_name
         FROM properties p
         LEFT JOIN reserves_parks rp ON p.reserve_park_id = rp.id
         WHERE p.country_id = ? AND p.status = 'published'
         ORDER BY {$sortOrder}, p.name ASC
         LIMIT ? OFFSET ?",
        [$cid, $perPage, $offset]
    );

    // Stats: average score
    $avgScoreRow = Database::fetch(
        "SELECT AVG(ai_readiness_score) AS avg_score
         FROM properties
         WHERE country_id = ? AND status = 'published' AND ai_readiness_score > 0",
        [$cid]
    );
    $avgScore = $avgScoreRow ? round((float)$avgScoreRow['avg_score'], 1) : 0;

    // Stats: top scorer
    $topScorer = Database::fetch(
        "SELECT id, name, slug, ai_readiness_score
         FROM properties
         WHERE country_id = ? AND status = 'published' AND ai_readiness_score > 0
         ORDER BY ai_readiness_score DESC
         LIMIT 1",
        [$cid]
    );

    // Stats: properties by type
    $typeStats = Database::fetchAll(
        "SELECT property_type, COUNT(*) AS cnt
         FROM properties
         WHERE country_id = ? AND status = 'published'
         GROUP BY property_type
         ORDER BY cnt DESC",
        [$cid]
    );

} catch (Throwable $e) {
    error_log('Country page query error: ' . $e->getMessage());
    $reserves         = [];
    $properties       = [];
    $totalProperties  = 0;
    $totalPages       = 1;
    $avgScore         = 0;
    $topScorer        = null;
    $typeStats        = [];
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

renderHeader($countryName . ' Safari Properties - Safari Traveller', 'Browse ' . $country['name'] . ' safari lodges, camps, and guides. AI readiness scores and entity authority audits for every property.');
?>

<!-- Breadcrumb -->
<section class="breadcrumb-bar">
    <div class="section-container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <ol>
                <li><a href="/public/">Home</a></li>
                <li aria-current="page"><?= $countryName ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Country Header -->
<section class="section country-header-section">
    <div class="section-container">
        <div class="country-page-header">
            <div class="country-header-info">
                <h1 class="country-page-name"><?= $countryName ?></h1>
                <?php if ($countryRegion): ?>
                <p class="country-page-region"><?= $countryRegion ?></p>
                <?php endif; ?>
            </div>
            <div class="country-header-stats">
                <div class="country-header-stat">
                    <span class="country-header-stat-num"><?= number_format($totalProperties) ?></span>
                    <span class="country-header-stat-label"><?= $totalProperties === 1 ? 'Property' : 'Properties' ?></span>
                </div>
                <div class="country-header-stat">
                    <span class="country-header-stat-num" style="color: <?= countryScoreColour((int)round($avgScore)) ?>"><?= $avgScore > 0 ? number_format($avgScore, 1) : '--' ?></span>
                    <span class="country-header-stat-label">Avg AI Score</span>
                </div>
                <div class="country-header-stat">
                    <span class="country-header-stat-num"><?= count($reserves) ?></span>
                    <span class="country-header-stat-label">Reserves &amp; Parks</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Reserves and Parks -->
<?php if (!empty($reserves)): ?>
<section class="section country-reserves-section">
    <div class="section-container">
        <h2 class="section-title">Reserves &amp; Parks in <?= $countryName ?></h2>

        <div class="reserve-grid">
            <?php foreach ($reserves as $reserve): ?>
            <a href="search.php?reserve=<?= (int)$reserve['id'] ?>&country=<?= $countryIso ?>" class="reserve-card">
                <div class="reserve-card-inner">
                    <h3 class="reserve-card-name"><?= htmlspecialchars($reserve['name']) ?></h3>
                    <span class="reserve-card-type"><?= countryFormatParkType($reserve['park_type']) ?></span>
                    <span class="reserve-card-count"><?= (int)$reserve['prop_count'] ?> <?= (int)$reserve['prop_count'] === 1 ? 'property' : 'properties' ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- All Properties -->
<section class="section country-properties-section">
    <div class="section-container">
        <div class="country-content-layout">

            <!-- Main Property List -->
            <div class="country-main">
                <div class="properties-header">
                    <h2 class="section-title">All Properties in <?= $countryName ?></h2>

                    <!-- Sort Options -->
                    <div class="sort-options">
                        <span class="sort-label">Sort by:</span>
                        <a href="?<?= $countryId ? 'id=' . $cid : 'slug=' . $countryIso ?>&sort=score&page=1" class="sort-option <?= $currentSort === 'score' ? 'sort-active' : '' ?>">AI Score</a>
                        <a href="?<?= $countryId ? 'id=' . $cid : 'slug=' . $countryIso ?>&sort=rating&page=1" class="sort-option <?= $currentSort === 'rating' ? 'sort-active' : '' ?>">Google Rating</a>
                        <a href="?<?= $countryId ? 'id=' . $cid : 'slug=' . $countryIso ?>&sort=name&page=1" class="sort-option <?= $currentSort === 'name' ? 'sort-active' : '' ?>">Name</a>
                    </div>
                </div>

                <?php if (empty($properties)): ?>
                <div class="no-results">
                    <h3>No Properties Yet</h3>
                    <p>We haven't published any properties for <?= $countryName ?> yet. Our harvester is working on discovering and auditing safari properties across Africa.</p>
                    <a href="/public/" class="btn btn-outline">Browse Other Countries</a>
                </div>
                <?php else: ?>

                <div class="property-list">
                    <?php foreach ($properties as $prop): ?>
                    <?php
                        $propScore      = (int)$prop['ai_readiness_score'];
                        $propScoreColor = countryScoreColour($propScore);
                        $propLink       = 'property.php?id=' . (int)$prop['id'];
                        if (!empty($prop['slug'])) {
                            $propLink = 'property.php?slug=' . urlencode($prop['slug']);
                        }
                    ?>
                    <a href="<?= $propLink ?>" class="property-list-item">
                        <div class="property-list-info">
                            <h3 class="property-list-name"><?= htmlspecialchars($prop['name']) ?></h3>
                            <div class="property-list-meta">
                                <?php if (!empty($prop['reserve_name'])): ?>
                                <span class="property-list-reserve"><?= htmlspecialchars($prop['reserve_name']) ?></span>
                                <?php endif; ?>
                                <span class="property-list-type"><?= countryFormatType($prop['property_type']) ?></span>
                                <?php if (!empty($prop['price_tier'])): ?>
                                <span class="property-list-price"><?= countryFormatType($prop['price_tier']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="property-list-scores">
                            <!-- Mini Score Gauge -->
                            <div class="mini-gauge" title="AI Readiness Score: <?= $propScore ?>/100">
                                <svg width="48" height="48" viewBox="0 0 48 48">
                                    <circle cx="24" cy="24" r="20" fill="none" stroke="#E8E8E3" stroke-width="4"/>
                                    <circle cx="24" cy="24" r="20" fill="none" stroke="<?= $propScoreColor ?>" stroke-width="4"
                                            stroke-dasharray="<?= round(125.66 * $propScore / 100, 2) ?> 125.66"
                                            stroke-linecap="round"
                                            transform="rotate(-90 24 24)"/>
                                    <text x="24" y="28" text-anchor="middle" font-size="13" font-weight="600" fill="<?= $propScoreColor ?>"><?= $propScore ?></text>
                                </svg>
                            </div>
                            <?php if ((float)($prop['google_rating'] ?? 0) > 0): ?>
                            <div class="property-list-rating">
                                <span class="star star-full">&#9733;</span>
                                <span><?= number_format((float)$prop['google_rating'], 1) ?></span>
                                <?php if ((int)($prop['google_review_count'] ?? 0) > 0): ?>
                                <span class="review-count-sm">(<?= number_format((int)$prop['google_review_count']) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Property list pagination">
                    <?php
                    $baseUrl = '?' . ($countryId ? 'id=' . $cid : 'slug=' . $countryIso) . '&sort=' . urlencode($currentSort);
                    ?>

                    <?php if ($currentPage > 1): ?>
                    <a href="<?= $baseUrl ?>&page=<?= $currentPage - 1 ?>" class="page-link page-prev" aria-label="Previous page">&laquo; Prev</a>
                    <?php endif; ?>

                    <?php
                    // Show page numbers with ellipsis
                    $startPage = max(1, $currentPage - 2);
                    $endPage   = min($totalPages, $currentPage + 2);

                    if ($startPage > 1): ?>
                    <a href="<?= $baseUrl ?>&page=1" class="page-link">1</a>
                    <?php if ($startPage > 2): ?>
                    <span class="page-ellipsis">&hellip;</span>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                    <?php if ($p === $currentPage): ?>
                    <span class="page-link page-current" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                    <a href="<?= $baseUrl ?>&page=<?= $p ?>" class="page-link"><?= $p ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                    <span class="page-ellipsis">&hellip;</span>
                    <?php endif; ?>
                    <a href="<?= $baseUrl ?>&page=<?= $totalPages ?>" class="page-link"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($currentPage < $totalPages): ?>
                    <a href="<?= $baseUrl ?>&page=<?= $currentPage + 1 ?>" class="page-link page-next" aria-label="Next page">Next &raquo;</a>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>

                <?php endif; ?>
            </div>

            <!-- Stats Sidebar -->
            <aside class="country-sidebar">

                <!-- Overview Stats -->
                <div class="sidebar-card">
                    <h4><?= $countryName ?> Stats</h4>
                    <div class="sidebar-stats">
                        <div class="sidebar-stat-row">
                            <span class="sidebar-stat-label">Total Properties</span>
                            <span class="sidebar-stat-value"><?= number_format($totalProperties) ?></span>
                        </div>
                        <div class="sidebar-stat-row">
                            <span class="sidebar-stat-label">Avg AI Score</span>
                            <span class="sidebar-stat-value" style="color: <?= countryScoreColour((int)round($avgScore)) ?>"><?= $avgScore > 0 ? number_format($avgScore, 1) . '/100' : 'N/A' ?></span>
                        </div>
                        <div class="sidebar-stat-row">
                            <span class="sidebar-stat-label">Reserves &amp; Parks</span>
                            <span class="sidebar-stat-value"><?= count($reserves) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Top Scorer -->
                <?php if ($topScorer): ?>
                <div class="sidebar-card">
                    <h4>Top AI Score</h4>
                    <div class="sidebar-top-scorer">
                        <div class="mini-gauge" title="AI Readiness Score: <?= (int)$topScorer['ai_readiness_score'] ?>/100">
                            <svg width="56" height="56" viewBox="0 0 56 56">
                                <circle cx="28" cy="28" r="23" fill="none" stroke="#E8E8E3" stroke-width="4"/>
                                <circle cx="28" cy="28" r="23" fill="none" stroke="<?= countryScoreColour((int)$topScorer['ai_readiness_score']) ?>" stroke-width="4"
                                        stroke-dasharray="<?= round(144.51 * (int)$topScorer['ai_readiness_score'] / 100, 2) ?> 144.51"
                                        stroke-linecap="round"
                                        transform="rotate(-90 28 28)"/>
                                <text x="28" y="33" text-anchor="middle" font-size="15" font-weight="600" fill="<?= countryScoreColour((int)$topScorer['ai_readiness_score']) ?>"><?= (int)$topScorer['ai_readiness_score'] ?></text>
                            </svg>
                        </div>
                        <div class="sidebar-top-scorer-info">
                            <?php
                            $topLink = 'property.php?id=' . (int)$topScorer['id'];
                            if (!empty($topScorer['slug'])) {
                                $topLink = 'property.php?slug=' . urlencode($topScorer['slug']);
                            }
                            ?>
                            <a href="<?= $topLink ?>" class="sidebar-top-scorer-name"><?= htmlspecialchars($topScorer['name']) ?></a>
                            <span class="sidebar-top-scorer-score"><?= (int)$topScorer['ai_readiness_score'] ?>/100</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Properties by Type -->
                <?php if (!empty($typeStats)): ?>
                <div class="sidebar-card">
                    <h4>Properties by Type</h4>
                    <div class="sidebar-type-list">
                        <?php foreach ($typeStats as $typeStat): ?>
                        <div class="sidebar-type-row">
                            <span class="sidebar-type-name"><?= countryFormatType($typeStat['property_type']) ?></span>
                            <span class="sidebar-type-count"><?= (int)$typeStat['cnt'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Claim CTA -->
                <div class="sidebar-card claim-card">
                    <h4>Own a Lodge in <?= $countryName ?>?</h4>
                    <p>Claim your listing to update your property details and improve your AI readiness score.</p>
                    <a href="claim.php" class="btn btn-primary btn-sm">Claim Your Listing</a>
                </div>
            </aside>

        </div>
    </div>
</section>

<?php
renderFooter();
