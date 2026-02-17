<?php
/**
 * Filename: search.php
 * Description: Search and browse safari properties with filters, sorting, and pagination
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../templates/layout.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$perPage       = 24;
$maxScoreValue = 100;

// ---------------------------------------------------------------------------
// Read GET parameters
// ---------------------------------------------------------------------------

$searchQuery = trim($_GET['q'] ?? '');
$country     = trim($_GET['country'] ?? '');
$region      = trim($_GET['region'] ?? '');
$reserve     = trim($_GET['reserve'] ?? '');
$types       = $_GET['type'] ?? [];
$scoreMin    = $_GET['score_min'] ?? '';
$scoreMax    = $_GET['score_max'] ?? '';
$priceTiers  = $_GET['price_tier'] ?? [];
$sort        = trim($_GET['sort'] ?? 'score_desc');
$page        = max(1, (int) ($_GET['page'] ?? 1));

// Normalise type/price_tier into arrays when passed as single values
if (is_string($types)) {
    $types = $types !== '' ? [$types] : [];
}
if (is_string($priceTiers)) {
    $priceTiers = $priceTiers !== '' ? [$priceTiers] : [];
}

// Sanitise score range
$scoreMin = $scoreMin !== '' ? max(0, min($maxScoreValue, (int) $scoreMin)) : '';
$scoreMax = $scoreMax !== '' ? max(0, min($maxScoreValue, (int) $scoreMax)) : '';

// Allowed sort options
$allowedSorts = [
    'score_desc'  => 'AI Score (High to Low)',
    'score_asc'   => 'AI Score (Low to High)',
    'rating_desc' => 'Google Rating (High to Low)',
    'name_asc'    => 'Name (A-Z)',
];

if (!array_key_exists($sort, $allowedSorts)) {
    $sort = 'score_desc';
}

// Allowed property types
$allowedTypes = [
    'lodge'         => 'Lodge',
    'camp'          => 'Camp',
    'tented_camp'   => 'Tented Camp',
    'bush_camp'     => 'Bush Camp',
    'villa'         => 'Villa',
    'guesthouse'    => 'Guesthouse',
    'hotel'         => 'Hotel',
    'resort'        => 'Resort',
    'guide'         => 'Private Guide',
    'tour_operator' => 'Tour Operator',
    'dmc'           => 'DMC',
];

// Allowed price tiers
$allowedPriceTiers = [
    'budget'       => 'Budget',
    'mid'          => 'Mid-Range',
    'luxury'       => 'Luxury',
    'ultra_luxury' => 'Ultra Luxury',
];

// Allowed regions
$allowedRegions = [
    'Northern Africa',
    'Western Africa',
    'Central Africa',
    'Eastern Africa',
    'Southern Africa',
];

// Filter types and price tiers to only allowed values
$types      = array_intersect($types, array_keys($allowedTypes));
$priceTiers = array_intersect($priceTiers, array_keys($allowedPriceTiers));

// ---------------------------------------------------------------------------
// Fetch reference data for filter dropdowns
// ---------------------------------------------------------------------------

try {
    $countries = Database::fetchAll(
        "SELECT id, name, iso_code FROM countries WHERE is_active = 1 ORDER BY name ASC"
    );
} catch (Throwable $e) {
    $countries = [];
}

try {
    $reservesSql = "SELECT rp.id, rp.name, rp.country_id FROM reserves_parks rp ORDER BY rp.name ASC";
    $reservesParks = Database::fetchAll($reservesSql);
} catch (Throwable $e) {
    $reservesParks = [];
}

// ---------------------------------------------------------------------------
// Build the search query dynamically
// ---------------------------------------------------------------------------

$whereClauses = ["p.status = 'published'"];
$params       = [];

// Text search
if ($searchQuery !== '') {
    $whereClauses[] = "(p.name LIKE ? OR p.formatted_address LIKE ? OR p.description LIKE ?)";
    $searchWild     = '%' . $searchQuery . '%';
    $params[]       = $searchWild;
    $params[]       = $searchWild;
    $params[]       = $searchWild;
}

// Country filter
if ($country !== '') {
    $whereClauses[] = "p.country_id = ?";
    $params[]       = (int) $country;
}

// Region filter
if ($region !== '' && in_array($region, $allowedRegions, true)) {
    $whereClauses[] = "c.region = ?";
    $params[]       = $region;
}

// Reserve/Park filter
if ($reserve !== '') {
    $whereClauses[] = "p.reserve_park_id = ?";
    $params[]       = (int) $reserve;
}

// Property type filter
if (!empty($types)) {
    $typePlaceholders = implode(',', array_fill(0, count($types), '?'));
    $whereClauses[]   = "p.property_type IN ({$typePlaceholders})";
    $params           = array_merge($params, $types);
}

// AI Readiness Score range
if ($scoreMin !== '') {
    $whereClauses[] = "p.ai_readiness_score >= ?";
    $params[]       = (int) $scoreMin;
}
if ($scoreMax !== '') {
    $whereClauses[] = "p.ai_readiness_score <= ?";
    $params[]       = (int) $scoreMax;
}

// Price tier filter
if (!empty($priceTiers)) {
    $pricePlaceholders = implode(',', array_fill(0, count($priceTiers), '?'));
    $whereClauses[]    = "p.price_tier IN ({$pricePlaceholders})";
    $params            = array_merge($params, $priceTiers);
}

$whereSQL = implode(' AND ', $whereClauses);

// Sort clause
$orderSQL = match ($sort) {
    'score_asc'   => 'p.ai_readiness_score ASC, p.name ASC',
    'rating_desc' => 'p.google_rating DESC, p.google_review_count DESC, p.name ASC',
    'name_asc'    => 'p.name ASC',
    default       => 'p.ai_readiness_score DESC, p.google_rating DESC, p.name ASC',
};

// ---------------------------------------------------------------------------
// Count total results
// ---------------------------------------------------------------------------

$countSQL = "SELECT COUNT(*) AS cnt
             FROM properties p
             LEFT JOIN countries c ON p.country_id = c.id
             WHERE {$whereSQL}";

try {
    $countRow   = Database::fetch($countSQL, $params);
    $totalCount = (int) ($countRow['cnt'] ?? 0);
} catch (Throwable $e) {
    $totalCount = 0;
}

$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ---------------------------------------------------------------------------
// Fetch results
// ---------------------------------------------------------------------------

$resultsSQL = "SELECT p.id, p.name, p.slug, p.property_type, p.formatted_address,
                      p.ai_readiness_score, p.google_rating, p.google_review_count,
                      p.price_tier, p.short_description,
                      c.name AS country_name, c.iso_code AS country_iso,
                      rp.name AS reserve_name
               FROM properties p
               LEFT JOIN countries c ON p.country_id = c.id
               LEFT JOIN reserves_parks rp ON p.reserve_park_id = rp.id
               WHERE {$whereSQL}
               ORDER BY {$orderSQL}
               LIMIT ? OFFSET ?";

$resultsParams   = array_merge($params, [$perPage, $offset]);

try {
    $properties = Database::fetchAll($resultsSQL, $resultsParams);
} catch (Throwable $e) {
    $properties = [];
}

// ---------------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------------

/**
 * Build a URL preserving current filters while overriding specific params.
 *
 * @param array $overrides Key-value pairs to override
 * @return string
 */
function buildSearchUrl(array $overrides = []): string
{
    $current = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($current[$key]);
        } else {
            $current[$key] = $value;
        }
    }
    return 'search.php?' . http_build_query($current);
}

/**
 * Render star rating as HTML.
 *
 * @param float|null $rating Google rating (0-5)
 * @return string HTML string
 */
function renderStars(?float $rating): string
{
    if ($rating === null) {
        return '<span class="stars-empty">No rating</span>';
    }

    $fullStars  = (int) floor($rating);
    $halfStar   = ($rating - $fullStars) >= 0.25;
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

    $html = '<span class="stars" title="' . number_format($rating, 1) . ' out of 5">';
    for ($i = 0; $i < $fullStars; $i++) {
        $html .= '<span class="star star-full">&#9733;</span>';
    }
    if ($halfStar) {
        $html .= '<span class="star star-half">&#9733;</span>';
    }
    for ($i = 0; $i < $emptyStars; $i++) {
        $html .= '<span class="star star-empty">&#9734;</span>';
    }
    $html .= '</span>';

    return $html;
}

/**
 * Get the CSS class for an AI score gauge colour.
 *
 * @param int $score
 * @return string CSS class suffix
 */
function scoreColourClass(int $score): string
{
    if ($score >= 70) {
        return 'high';
    }
    if ($score >= 40) {
        return 'mid';
    }
    return 'low';
}

/**
 * Format a property type enum value for display.
 *
 * @param string $type
 * @return string
 */
function formatPropertyType(string $type): string
{
    $map = [
        'lodge'         => 'Lodge',
        'camp'          => 'Camp',
        'tented_camp'   => 'Tented Camp',
        'bush_camp'     => 'Bush Camp',
        'villa'         => 'Villa',
        'guesthouse'    => 'Guesthouse',
        'hotel'         => 'Hotel',
        'resort'        => 'Resort',
        'guide'         => 'Private Guide',
        'tour_operator' => 'Tour Operator',
        'dmc'           => 'DMC',
    ];
    return $map[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/**
 * Format a price tier for display.
 *
 * @param string|null $tier
 * @return string
 */
function formatPriceTier(?string $tier): string
{
    $map = [
        'budget'       => 'Budget',
        'mid'          => 'Mid-Range',
        'luxury'       => 'Luxury',
        'ultra_luxury' => 'Ultra Luxury',
    ];
    return $map[$tier ?? ''] ?? '';
}

// ---------------------------------------------------------------------------
// Render the page
// ---------------------------------------------------------------------------

renderHeader('Search Safari Properties');
?>

<style>
/* Search page layout */
.search-page {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 2rem;
    align-items: start;
}

/* Sidebar filters */
.filter-sidebar {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    padding: 1.5rem;
    position: sticky;
    top: 5rem;
}

.filter-sidebar h3 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #E8E8E3;
}

.filter-sidebar .form-group {
    margin-bottom: 1rem;
}

.filter-sidebar .form-group label {
    display: block;
    font-size: 0.8rem;
    font-weight: 500;
    margin-bottom: 0.3rem;
    color: #2C2C2C;
}

.filter-sidebar .form-group input[type="text"],
.filter-sidebar .form-group input[type="number"],
.filter-sidebar .form-group select {
    width: 100%;
    padding: 0.5rem 0.7rem;
    border: 1px solid #E8E8E3;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.85rem;
    background: #FAFAF5;
    transition: border-color 0.2s;
}

.filter-sidebar .form-group input:focus,
.filter-sidebar .form-group select:focus {
    outline: none;
    border-color: #D4A84B;
    box-shadow: 0 0 0 3px rgba(212,168,75,0.15);
}

.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.checkbox-group label {
    display: flex !important;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.82rem !important;
    font-weight: 400 !important;
    cursor: pointer;
}

.checkbox-group input[type="checkbox"] {
    width: auto;
    accent-color: #D4A84B;
}

.score-range {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}

.score-range input[type="number"] {
    width: 100% !important;
}

.filter-sidebar .btn-apply {
    width: 100%;
    padding: 0.65rem 1rem;
    background: #D4A84B;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.2s;
    margin-top: 0.5rem;
}

.filter-sidebar .btn-apply:hover {
    background: #c49a3a;
}

.filter-sidebar .btn-clear {
    display: block;
    width: 100%;
    text-align: center;
    margin-top: 0.5rem;
    font-size: 0.8rem;
    color: #6B6B6B;
    text-decoration: none;
    padding: 0.35rem;
}

.filter-sidebar .btn-clear:hover {
    color: #D4A84B;
}

/* Mobile filter toggle */
.filter-toggle-btn {
    display: none;
    width: 100%;
    padding: 0.7rem 1rem;
    background: #fff;
    border: 1px solid #E8E8E3;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    margin-bottom: 1rem;
    color: #2C2C2C;
}

/* Results area */
.results-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.results-count {
    font-size: 0.9rem;
    color: #6B6B6B;
}

.results-count strong {
    color: #2C2C2C;
}

.sort-select {
    padding: 0.45rem 0.75rem;
    border: 1px solid #E8E8E3;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.85rem;
    background: #fff;
    cursor: pointer;
}

.sort-select:focus {
    outline: none;
    border-color: #D4A84B;
}

/* Property card grid */
.property-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
}

.property-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    overflow: hidden;
    transition: box-shadow 0.2s, transform 0.2s;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
}

.property-card:hover {
    box-shadow: 0 6px 24px rgba(0,0,0,0.12);
    transform: translateY(-2px);
    text-decoration: none;
    color: inherit;
}

.property-card-body {
    padding: 1rem 1.15rem 1.15rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.property-card-name {
    font-size: 0.95rem;
    font-weight: 600;
    color: #2C2C2C;
    margin-bottom: 0.3rem;
    line-height: 1.35;
}

.property-card-location {
    font-size: 0.78rem;
    color: #6B6B6B;
    margin-bottom: 0.65rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.country-flag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 14px;
    background: #E8E8E3;
    border-radius: 2px;
    font-size: 0.6rem;
    font-weight: 600;
    color: #6B6B6B;
    flex-shrink: 0;
}

.property-card-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: auto;
    padding-top: 0.65rem;
    border-top: 1px solid #f0f0eb;
    flex-wrap: wrap;
    gap: 0.4rem;
}

/* AI Score mini gauge */
.ai-score-mini {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.ai-score-ring {
    width: 36px;
    height: 36px;
    position: relative;
}

.ai-score-ring svg {
    transform: rotate(-90deg);
    width: 36px;
    height: 36px;
}

.ai-score-ring .ring-bg {
    fill: none;
    stroke: #E8E8E3;
    stroke-width: 3;
}

.ai-score-ring .ring-fg {
    fill: none;
    stroke-width: 3;
    stroke-linecap: round;
    transition: stroke-dashoffset 0.5s ease;
}

.ai-score-ring .ring-fg.high { stroke: #3A7D44; }
.ai-score-ring .ring-fg.mid { stroke: #D4A84B; }
.ai-score-ring .ring-fg.low { stroke: #C0392B; }

.ai-score-value {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.65rem;
    font-weight: 700;
    color: #2C2C2C;
}

.ai-score-label {
    font-size: 0.7rem;
    color: #6B6B6B;
    line-height: 1.2;
}

/* Stars */
.stars {
    font-size: 0.85rem;
    letter-spacing: -1px;
}

.star-full { color: #D4A84B; }
.star-half { color: #D4A84B; opacity: 0.6; }
.star-empty { color: #E8E8E3; }
.stars-empty { font-size: 0.75rem; color: #aaa; }

/* Badges */
.badge {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: 20px;
    font-size: 0.68rem;
    font-weight: 500;
    line-height: 1.4;
}

.badge-type {
    background: #E8F4FD;
    color: #1565C0;
}

.badge-price {
    background: rgba(212,168,75,0.12);
    color: #8D6E00;
}

/* Pagination */
.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    margin-top: 2rem;
    flex-wrap: wrap;
}

.pagination a,
.pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 0.5rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.2s, color 0.2s;
}

.pagination a {
    background: #fff;
    color: #2C2C2C;
    border: 1px solid #E8E8E3;
}

.pagination a:hover {
    background: #D4A84B;
    color: #fff;
    border-color: #D4A84B;
    text-decoration: none;
}

.pagination .current {
    background: #D4A84B;
    color: #fff;
    border: 1px solid #D4A84B;
    font-weight: 600;
}

.pagination .disabled {
    color: #ccc;
    border: 1px solid #E8E8E3;
    cursor: default;
    pointer-events: none;
}

/* No results */
.no-results {
    text-align: center;
    padding: 3rem 1rem;
}

.no-results h2 {
    font-size: 1.25rem;
    margin-bottom: 0.5rem;
    color: #2C2C2C;
}

.no-results p {
    color: #6B6B6B;
    margin-bottom: 1.5rem;
}

/* Responsive */
@media (max-width: 1024px) {
    .property-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .search-page {
        grid-template-columns: 1fr;
    }

    .filter-sidebar {
        position: static;
        display: none;
    }

    .filter-sidebar.open {
        display: block;
    }

    .filter-toggle-btn {
        display: block;
    }

    .property-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 520px) {
    .property-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<h1 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem;">Search Safari Properties</h1>
<p style="color: #6B6B6B; font-size: 0.9rem; margin-bottom: 1.5rem;">
    Discover Africa's finest safari lodges, camps, guides, and tour operators.
</p>

<!-- Mobile filter toggle -->
<button class="filter-toggle-btn" onclick="document.getElementById('filterSidebar').classList.toggle('open')">
    &#9776; Filters
</button>

<div class="search-page">

    <!-- Left sidebar filter panel -->
    <aside class="filter-sidebar" id="filterSidebar">
        <form method="get" action="search.php">
            <h3>Filter Properties</h3>

            <!-- Text search -->
            <div class="form-group">
                <label for="filter-q">Search</label>
                <input type="text" id="filter-q" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Lodge name, location...">
            </div>

            <!-- Country -->
            <div class="form-group">
                <label for="filter-country">Country</label>
                <select id="filter-country" name="country">
                    <option value="">All Countries</option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $country === (string) $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Region -->
            <div class="form-group">
                <label for="filter-region">Region</label>
                <select id="filter-region" name="region">
                    <option value="">All Regions</option>
                    <?php foreach ($allowedRegions as $r): ?>
                        <option value="<?= htmlspecialchars($r) ?>" <?= $region === $r ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Reserve/Park -->
            <div class="form-group">
                <label for="filter-reserve">Reserve / Park</label>
                <select id="filter-reserve" name="reserve">
                    <option value="">All Reserves</option>
                    <?php foreach ($reservesParks as $rp): ?>
                        <option value="<?= (int) $rp['id'] ?>"
                                data-country="<?= (int) $rp['country_id'] ?>"
                                <?= $reserve === (string) $rp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Property Type -->
            <div class="form-group">
                <label>Property Type</label>
                <div class="checkbox-group">
                    <?php foreach ($allowedTypes as $typeKey => $typeLabel): ?>
                        <label>
                            <input type="checkbox" name="type[]" value="<?= htmlspecialchars($typeKey) ?>"
                                   <?= in_array($typeKey, $types, true) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($typeLabel) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- AI Readiness Score Range -->
            <div class="form-group">
                <label>AI Readiness Score</label>
                <div class="score-range">
                    <input type="number" name="score_min" placeholder="Min" min="0" max="<?= $maxScoreValue ?>"
                           value="<?= $scoreMin !== '' ? (int) $scoreMin : '' ?>">
                    <input type="number" name="score_max" placeholder="Max" min="0" max="<?= $maxScoreValue ?>"
                           value="<?= $scoreMax !== '' ? (int) $scoreMax : '' ?>">
                </div>
            </div>

            <!-- Price Tier -->
            <div class="form-group">
                <label>Price Tier</label>
                <div class="checkbox-group">
                    <?php foreach ($allowedPriceTiers as $tierKey => $tierLabel): ?>
                        <label>
                            <input type="checkbox" name="price_tier[]" value="<?= htmlspecialchars($tierKey) ?>"
                                   <?= in_array($tierKey, $priceTiers, true) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($tierLabel) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Hidden sort field to preserve sort on filter apply -->
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">

            <button type="submit" class="btn-apply">Apply Filters</button>
            <a href="search.php" class="btn-clear">Clear All Filters</a>
        </form>
    </aside>

    <!-- Results area -->
    <section class="results-area">

        <!-- Results header: count + sort -->
        <div class="results-header">
            <div class="results-count">
                <?php if ($totalCount === 0): ?>
                    No properties found
                <?php elseif ($totalCount === 1): ?>
                    <strong>1</strong> property found
                <?php else: ?>
                    <strong><?= number_format($totalCount) ?></strong> properties found
                <?php endif; ?>
            </div>

            <select class="sort-select" onchange="window.location.href=this.value" aria-label="Sort results">
                <?php foreach ($allowedSorts as $sortKey => $sortLabel): ?>
                    <option value="<?= htmlspecialchars(buildSearchUrl(['sort' => $sortKey, 'page' => null])) ?>"
                            <?= $sort === $sortKey ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sortLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (empty($properties)): ?>
            <!-- No results -->
            <div class="no-results">
                <h2>No properties match your filters</h2>
                <p>Try adjusting your search criteria or clearing the filters to browse all properties.</p>
                <a href="search.php" class="btn btn-primary">Browse All Properties</a>
            </div>

        <?php else: ?>
            <!-- Property cards grid -->
            <div class="property-grid">
                <?php foreach ($properties as $prop): ?>
                    <?php
                    $slug        = htmlspecialchars($prop['slug'] ?? '');
                    $propName    = htmlspecialchars($prop['name']);
                    $score       = (int) $prop['ai_readiness_score'];
                    $rating      = $prop['google_rating'] !== null ? (float) $prop['google_rating'] : null;
                    $reviewCount = (int) ($prop['google_review_count'] ?? 0);
                    $propType    = $prop['property_type'] ?? '';
                    $priceTier   = $prop['price_tier'] ?? '';
                    $countryName = htmlspecialchars($prop['country_name'] ?? '');
                    $countryIso  = htmlspecialchars($prop['country_iso'] ?? '');
                    $reserveName = htmlspecialchars($prop['reserve_name'] ?? '');
                    $colourClass = scoreColourClass($score);

                    // SVG ring calculation
                    $ringRadius  = 14;
                    $circumference = 2 * M_PI * $ringRadius;
                    $dashOffset    = $circumference - ($circumference * $score / $maxScoreValue);

                    // Location display
                    $locationParts = [];
                    if ($reserveName !== '') {
                        $locationParts[] = $reserveName;
                    }
                    if ($countryName !== '') {
                        $locationParts[] = $countryName;
                    }
                    $locationStr = implode(', ', $locationParts);
                    if ($locationStr === '' && !empty($prop['formatted_address'])) {
                        $locationStr = htmlspecialchars($prop['formatted_address']);
                    }
                    ?>
                    <a href="property.php?slug=<?= $slug ?>" class="property-card">
                        <div class="property-card-body">
                            <div class="property-card-name"><?= $propName ?></div>
                            <div class="property-card-location">
                                <?php if ($countryIso !== ''): ?>
                                    <span class="country-flag" title="<?= $countryName ?>"><?= $countryIso ?></span>
                                <?php endif; ?>
                                <span><?= $locationStr !== '' ? $locationStr : 'Africa' ?></span>
                            </div>

                            <div class="property-card-meta">
                                <!-- AI Score mini gauge -->
                                <div class="ai-score-mini">
                                    <div class="ai-score-ring">
                                        <svg viewBox="0 0 36 36">
                                            <circle class="ring-bg" cx="18" cy="18" r="<?= $ringRadius ?>"/>
                                            <circle class="ring-fg <?= $colourClass ?>" cx="18" cy="18" r="<?= $ringRadius ?>"
                                                    stroke-dasharray="<?= number_format($circumference, 2) ?>"
                                                    stroke-dashoffset="<?= number_format($dashOffset, 2) ?>"/>
                                        </svg>
                                        <span class="ai-score-value"><?= $score ?></span>
                                    </div>
                                    <span class="ai-score-label">AI<br>Score</span>
                                </div>

                                <!-- Google rating stars -->
                                <div>
                                    <?= renderStars($rating) ?>
                                    <?php if ($reviewCount > 0): ?>
                                        <span style="font-size:0.7rem;color:#6B6B6B;">(<?= number_format($reviewCount) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Badges row -->
                            <div style="display:flex;gap:0.35rem;margin-top:0.6rem;flex-wrap:wrap;">
                                <?php if ($propType !== ''): ?>
                                    <span class="badge badge-type"><?= htmlspecialchars(formatPropertyType($propType)) ?></span>
                                <?php endif; ?>
                                <?php if ($priceTier !== ''): ?>
                                    <span class="badge badge-price"><?= htmlspecialchars(formatPriceTier($priceTier)) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Search results pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= htmlspecialchars(buildSearchUrl(['page' => $page - 1])) ?>" aria-label="Previous page">&laquo; Prev</a>
                    <?php else: ?>
                        <span class="disabled">&laquo; Prev</span>
                    <?php endif; ?>

                    <?php
                    // Determine visible page range (show max 7 pages)
                    $maxVisible = 7;
                    $startPage  = max(1, $page - (int) floor($maxVisible / 2));
                    $endPage    = min($totalPages, $startPage + $maxVisible - 1);
                    if ($endPage - $startPage + 1 < $maxVisible) {
                        $startPage = max(1, $endPage - $maxVisible + 1);
                    }

                    if ($startPage > 1): ?>
                        <a href="<?= htmlspecialchars(buildSearchUrl(['page' => 1])) ?>">1</a>
                        <?php if ($startPage > 2): ?>
                            <span class="disabled">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars(buildSearchUrl(['page' => $i])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="disabled">...</span>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars(buildSearchUrl(['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= htmlspecialchars(buildSearchUrl(['page' => $page + 1])) ?>" aria-label="Next page">Next &raquo;</a>
                    <?php else: ?>
                        <span class="disabled">Next &raquo;</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>

        <?php endif; ?>
    </section>
</div>

<!-- JavaScript: filter reserve dropdown by selected country -->
<script>
(function() {
    var countrySelect = document.getElementById('filter-country');
    var reserveSelect = document.getElementById('filter-reserve');

    if (!countrySelect || !reserveSelect) return;

    // Store all reserve options
    var allOptions = [];
    for (var i = 0; i < reserveSelect.options.length; i++) {
        var opt = reserveSelect.options[i];
        allOptions.push({
            value: opt.value,
            text: opt.text,
            countryId: opt.getAttribute('data-country') || ''
        });
    }

    function filterReserves() {
        var selectedCountry = countrySelect.value;
        var currentReserve  = reserveSelect.value;

        // Clear all options except the first ("All Reserves")
        reserveSelect.length = 0;

        allOptions.forEach(function(opt) {
            // Always show the "All Reserves" option (value === "")
            if (opt.value === '') {
                reserveSelect.add(new Option(opt.text, opt.value));
                return;
            }
            // If no country selected, show all
            if (selectedCountry === '' || opt.countryId === selectedCountry) {
                var newOpt = new Option(opt.text, opt.value);
                if (opt.value === currentReserve) {
                    newOpt.selected = true;
                }
                reserveSelect.add(newOpt);
            }
        });
    }

    countrySelect.addEventListener('change', function() {
        filterReserves();
    });

    // Run once on page load to filter if country is pre-selected
    if (countrySelect.value !== '') {
        filterReserves();
    }
})();
</script>

<?php
renderFooter();
