<?php
/**
 * Safari Traveller - One-File Installer
 *
 * Version:     1.0.0
 * Created:     2026-02-17
 * Updated:     2026-02-17
 * Description: Single-file installer for Safari Traveller platform.
 *              Creates all database tables, seeds reference data (countries,
 *              reserves/parks, settings, discovery queue, cron schedule),
 *              creates the initial admin user, writes config.php, and
 *              self-deletes on success.
 *
 * Requirements: PHP 8.x, PDO with MySQL driver, MySQL 5.7+ / MariaDB 10.3+
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function renderHeader(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safari Traveller &mdash; Installer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --gold: #D4A84B;
            --gold-hover: #c49a3a;
            --cream: #FAFAF5;
            --dark: #2C2C2C;
            --grey: #6B6B6B;
            --light-grey: #E8E8E3;
            --success-green: #3A7D44;
            --error-red: #C0392B;
            --white: #FFFFFF;
            --shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
            --radius: 8px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--cream);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 1rem;
        }

        .container {
            width: 100%;
            max-width: 640px;
        }

        .logo-area {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-area h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .logo-area h1 span {
            color: var(--gold);
        }

        .logo-area p {
            font-size: 0.9rem;
            color: var(--grey);
            font-weight: 300;
        }

        .card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }

        .card h2 {
            font-size: 1.15rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--light-grey);
        }

        .form-group {
            margin-bottom: 1.15rem;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.35rem;
            color: var(--dark);
        }

        .form-group input {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1px solid var(--light-grey);
            border-radius: var(--radius);
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            transition: border-color 0.2s;
            background: var(--cream);
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(212, 168, 75, 0.15);
        }

        .form-group .hint {
            font-size: 0.75rem;
            color: var(--grey);
            margin-top: 0.25rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .btn {
            display: inline-block;
            width: 100%;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            text-align: center;
        }

        .btn:active { transform: scale(0.98); }

        .btn-primary {
            background-color: var(--gold);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--gold-hover);
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 1.25rem;
            font-size: 0.88rem;
        }

        .alert-error {
            background: #FDEDEB;
            color: var(--error-red);
            border: 1px solid #F5C6CB;
        }

        .alert-success {
            background: #EAF5EB;
            color: var(--success-green);
            border: 1px solid #C3E6CB;
        }

        .success-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
            color: var(--success-green);
        }

        .success-card {
            text-align: center;
        }

        .success-card h2 {
            border: none;
            text-align: center;
        }

        .success-card p {
            color: var(--grey);
            margin-bottom: 0.75rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 0.75rem;
            margin: 1.5rem 0;
        }

        .stat-box {
            background: var(--cream);
            border-radius: var(--radius);
            padding: 0.85rem;
        }

        .stat-box .num {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gold);
        }

        .stat-box .label {
            font-size: 0.75rem;
            color: var(--grey);
        }

        .links-list {
            list-style: none;
            margin: 1.5rem 0;
            text-align: left;
        }

        .links-list li {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--light-grey);
        }

        .links-list li:last-child { border-bottom: none; }

        .links-list a {
            color: var(--gold);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .links-list a:hover {
            text-decoration: underline;
        }

        .warning {
            background: #FFF8E1;
            border: 1px solid #FFE082;
            color: #8D6E00;
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
            margin-top: 1rem;
            font-size: 0.82rem;
        }

        @media (max-width: 500px) {
            .form-row { grid-template-columns: 1fr; }
            .card { padding: 1.25rem; }
            body { padding: 1rem 0.75rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="logo-area">
        <h1>Safari <span>Traveller</span></h1>
        <p>Platform Installer</p>
    </div>
HTML;
}

function renderFooter(): string
{
    return <<<'HTML'
</div>
</body>
</html>
HTML;
}

// ---------------------------------------------------------------------------
// Show setup form
// ---------------------------------------------------------------------------

function showForm(string $error = ''): void
{
    echo renderHeader();

    if ($error !== '') {
        echo '<div class="alert alert-error">' . htmlspecialchars($error) . '</div>';
    }

    $dbHost  = htmlspecialchars($_POST['db_host']  ?? 'localhost');
    $dbName  = htmlspecialchars($_POST['db_name']  ?? 'safari_traveller');
    $dbUser  = htmlspecialchars($_POST['db_user']  ?? 'root');
    $dbPass  = htmlspecialchars($_POST['db_pass']  ?? '');
    $email   = htmlspecialchars($_POST['admin_email']  ?? '');
    $apiKey  = htmlspecialchars($_POST['google_api_key'] ?? '');

    echo <<<HTML
    <form method="post" action="">
        <input type="hidden" name="install" value="1">

        <div class="card">
            <h2>Database Connection</h2>
            <div class="form-row">
                <div class="form-group">
                    <label for="db_host">Database Host</label>
                    <input type="text" id="db_host" name="db_host" value="{$dbHost}" required>
                </div>
                <div class="form-group">
                    <label for="db_name">Database Name</label>
                    <input type="text" id="db_name" name="db_name" value="{$dbName}" required>
                    <div class="hint">Must already exist on the server</div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="db_user">Database User</label>
                    <input type="text" id="db_user" name="db_user" value="{$dbUser}" required>
                </div>
                <div class="form-group">
                    <label for="db_pass">Database Password</label>
                    <input type="password" id="db_pass" name="db_pass" value="{$dbPass}">
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Admin Account</h2>
            <div class="form-group">
                <label for="admin_email">Admin Email</label>
                <input type="email" id="admin_email" name="admin_email" value="{$email}" required>
            </div>
            <div class="form-group">
                <label for="admin_pass">Admin Password</label>
                <input type="password" id="admin_pass" name="admin_pass" minlength="8" required>
                <div class="hint">Minimum 8 characters</div>
            </div>
        </div>

        <div class="card">
            <h2>API Keys</h2>
            <div class="form-group">
                <label for="google_api_key">Google Places API Key</label>
                <input type="text" id="google_api_key" name="google_api_key" value="{$apiKey}" required>
                <div class="hint">Used for property discovery via Google Places</div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Install Safari Traveller</button>
    </form>
HTML;

    echo renderFooter();
}

// ---------------------------------------------------------------------------
// Table definitions
// ---------------------------------------------------------------------------

function getTableSQL(): array
{
    return [
        'countries' => <<<'SQL'
CREATE TABLE countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    iso_code CHAR(2) NOT NULL UNIQUE,
    region VARCHAR(100),
    tier INT DEFAULT 5,
    property_count INT DEFAULT 0,
    avg_ai_score DECIMAL(5,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'reserves_parks' => <<<'SQL'
CREATE TABLE reserves_parks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255),
    country_id INT NOT NULL,
    park_type ENUM('national_park','game_reserve','conservancy','private_reserve','marine_park','forest_reserve') DEFAULT 'game_reserve',
    description TEXT,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    property_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (country_id) REFERENCES countries(id),
    INDEX idx_country (country_id),
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'properties' => <<<'SQL'
CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    place_id VARCHAR(255) UNIQUE,
    name VARCHAR(500) NOT NULL,
    slug VARCHAR(500),
    property_type ENUM('lodge','camp','tented_camp','bush_camp','villa','guesthouse','hotel','resort','guide','tour_operator','dmc') DEFAULT 'lodge',
    status ENUM('discovered','scraped','enriched','audited','published','unpublished') DEFAULT 'discovered',
    country_id INT,
    reserve_park_id INT,
    formatted_address TEXT,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    website VARCHAR(500),
    phone VARCHAR(100),
    international_phone VARCHAR(100),
    email VARCHAR(255),
    google_maps_url VARCHAR(500),
    google_rating DECIMAL(3,2),
    google_review_count INT DEFAULT 0,
    price_tier ENUM('budget','mid','luxury','ultra_luxury'),
    room_count INT,
    star_rating DECIMAL(2,1),
    description TEXT,
    short_description VARCHAR(500),
    ai_readiness_score INT DEFAULT 0,
    last_scraped_at DATETIME,
    last_audited_at DATETIME,
    published_at DATETIME,
    business_status VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_country (country_id),
    INDEX idx_reserve (reserve_park_id),
    INDEX idx_status (status),
    INDEX idx_score (ai_readiness_score),
    INDEX idx_type (property_type),
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'property_contacts' => <<<'SQL'
CREATE TABLE property_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    contact_name VARCHAR(255),
    contact_email VARCHAR(255),
    contact_phone VARCHAR(100),
    contact_role VARCHAR(100),
    is_primary TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'property_sources' => <<<'SQL'
CREATE TABLE property_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    source_name VARCHAR(100) NOT NULL,
    source_url VARCHAR(500),
    source_id VARCHAR(255),
    raw_data JSON,
    discovered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_source (source_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'property_scrapes' => <<<'SQL'
CREATE TABLE property_scrapes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    url VARCHAR(500),
    page_type VARCHAR(50),
    raw_html LONGTEXT,
    extracted_data JSON,
    status ENUM('pending','complete','failed') DEFAULT 'pending',
    error_message TEXT,
    scraped_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property_status (property_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'property_enrichment' => <<<'SQL'
CREATE TABLE property_enrichment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    schema_data JSON,
    cms_platform VARCHAR(100),
    booking_engine VARCHAR(100),
    analytics_tools JSON,
    room_types JSON,
    activities JSON,
    amenities JSON,
    dining JSON,
    has_llm_txt TINYINT(1) DEFAULT 0,
    llm_txt_content TEXT,
    robots_txt TEXT,
    sitemap_url VARCHAR(500),
    page_speed_score INT,
    is_mobile_responsive TINYINT(1),
    is_https TINYINT(1) DEFAULT 0,
    total_word_count INT DEFAULT 0,
    has_faq_content TINYINT(1) DEFAULT 0,
    has_blog TINYINT(1) DEFAULT 0,
    enriched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'property_audit' => <<<'SQL'
CREATE TABLE property_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    total_score INT DEFAULT 0,
    schema_score INT DEFAULT 0,
    entity_authority_score INT DEFAULT 0,
    ai_discoverability_score INT DEFAULT 0,
    technical_score INT DEFAULT 0,
    booking_authority_score INT DEFAULT 0,
    score_breakdown JSON,
    findings JSON,
    recommendations JSON,
    competitor_comparison JSON,
    schema_found JSON,
    ota_comparison JSON,
    audited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property (property_id),
    INDEX idx_score (total_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'property_images' => <<<'SQL'
CREATE TABLE property_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    image_url VARCHAR(500),
    alt_text VARCHAR(500),
    source_page VARCHAR(500),
    image_type ENUM('hero','room','activity','dining','exterior','interior','map','other') DEFAULT 'other',
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'property_reviews' => <<<'SQL'
CREATE TABLE property_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    platform VARCHAR(100),
    rating DECIMAL(3,2),
    review_count INT DEFAULT 0,
    snippet TEXT,
    profile_url VARCHAR(500),
    last_checked DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'scrape_queue' => <<<'SQL'
CREATE TABLE scrape_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT,
    queue_type ENUM('discover','scrape','enrich','audit','publish') NOT NULL,
    status ENUM('pending','in_progress','complete','failed','paused') DEFAULT 'pending',
    priority INT DEFAULT 5,
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    last_attempt DATETIME,
    error_message TEXT,
    scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_status_type (status, queue_type),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'scrape_log' => <<<'SQL'
CREATE TABLE scrape_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(100),
    action VARCHAR(100),
    url VARCHAR(500),
    status_code INT,
    response_size INT,
    cost_estimate DECIMAL(10,6) DEFAULT 0,
    duration_ms INT,
    error_message TEXT,
    metadata JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_source (source),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'outreach_queue' => <<<'SQL'
CREATE TABLE outreach_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    contact_email VARCHAR(255) NOT NULL,
    contact_name VARCHAR(255),
    status ENUM('pending','sent','opened','clicked','claimed','bounced','unsubscribed') DEFAULT 'pending',
    template_name VARCHAR(100) DEFAULT 'default',
    sent_at DATETIME,
    opened_at DATETIME,
    clicked_at DATETIME,
    claimed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'listings' => <<<'SQL'
CREATE TABLE listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL UNIQUE,
    is_published TINYINT(1) DEFAULT 0,
    is_claimed TINYINT(1) DEFAULT 0,
    claimed_by_email VARCHAR(255),
    claimed_at DATETIME,
    verification_token VARCHAR(255),
    verification_sent_at DATETIME,
    page_views INT DEFAULT 0,
    enquiry_count INT DEFAULT 0,
    published_at DATETIME,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'enquiries' => <<<'SQL'
CREATE TABLE enquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    visitor_name VARCHAR(255) NOT NULL,
    visitor_email VARCHAR(255) NOT NULL,
    visitor_phone VARCHAR(100),
    message TEXT NOT NULL,
    status ENUM('new','forwarded','responded','closed') DEFAULT 'new',
    forwarded_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property (property_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'admin_users' => <<<'SQL'
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    role ENUM('admin','editor','viewer') DEFAULT 'admin',
    last_login DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'settings' => <<<'SQL'
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type ENUM('string','int','float','bool','json') DEFAULT 'string',
    description VARCHAR(255),
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'discovery_queue' => <<<'SQL'
CREATE TABLE discovery_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    country_id INT NOT NULL,
    search_term VARCHAR(255) NOT NULL,
    status ENUM('pending','in_progress','complete','failed') DEFAULT 'pending',
    results_count INT DEFAULT 0,
    api_cost DECIMAL(10,6) DEFAULT 0,
    last_run DATETIME,
    next_page_token TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (country_id) REFERENCES countries(id),
    INDEX idx_status (status),
    INDEX idx_country (country_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'cron_schedule' => <<<'SQL'
CREATE TABLE cron_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL UNIQUE,
    script_path VARCHAR(255) NOT NULL,
    frequency VARCHAR(50) DEFAULT 'every_5_min',
    is_enabled TINYINT(1) DEFAULT 1,
    is_running TINYINT(1) DEFAULT 0,
    last_run DATETIME,
    last_duration_sec INT,
    last_status ENUM('success','error','timeout') DEFAULT NULL,
    last_error TEXT,
    run_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'rate_limits' => <<<'SQL'
CREATE TABLE rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    last_request_at DATETIME,
    min_delay_ms INT DEFAULT 2000,
    request_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

        'api_costs' => <<<'SQL'
CREATE TABLE api_costs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_name VARCHAR(100) NOT NULL,
    endpoint VARCHAR(255),
    cost DECIMAL(10,6) NOT NULL,
    request_count INT DEFAULT 1,
    country_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api (api_name),
    INDEX idx_date (created_at),
    INDEX idx_country (country_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    ];
}

// ---------------------------------------------------------------------------
// Seed data: 54 African countries
// ---------------------------------------------------------------------------

function getCountriesSeed(): array
{
    // [name, iso_code, region, tier]
    return [
        // Eastern Africa
        ['Burundi',        'BI', 'Eastern Africa',  4],
        ['Comoros',         'KM', 'Eastern Africa',  5],
        ['Djibouti',       'DJ', 'Eastern Africa',  5],
        ['Eritrea',        'ER', 'Eastern Africa',  5],
        ['Ethiopia',       'ET', 'Eastern Africa',  3],
        ['Kenya',          'KE', 'Eastern Africa',  1],
        ['Madagascar',     'MG', 'Eastern Africa',  3],
        ['Malawi',         'MW', 'Eastern Africa',  3],
        ['Mauritius',      'MU', 'Eastern Africa',  3],
        ['Mozambique',     'MZ', 'Eastern Africa',  2],
        ['Rwanda',         'RW', 'Eastern Africa',  1],
        ['Seychelles',     'SC', 'Eastern Africa',  3],
        ['Somalia',        'SO', 'Eastern Africa',  5],
        ['South Sudan',    'SS', 'Eastern Africa',  5],
        ['Tanzania',       'TZ', 'Eastern Africa',  1],
        ['Uganda',         'UG', 'Eastern Africa',  1],

        // Southern Africa
        ['Botswana',       'BW', 'Southern Africa', 1],
        ['Eswatini',       'SZ', 'Southern Africa', 4],
        ['Lesotho',        'LS', 'Southern Africa', 5],
        ['Namibia',        'NA', 'Southern Africa', 1],
        ['South Africa',   'ZA', 'Southern Africa', 1],
        ['Zambia',         'ZM', 'Southern Africa', 1],
        ['Zimbabwe',       'ZW', 'Southern Africa', 1],

        // Western Africa
        ['Benin',          'BJ', 'Western Africa',  4],
        ['Burkina Faso',   'BF', 'Western Africa',  5],
        ['Cabo Verde',     'CV', 'Western Africa',  5],
        ['Cote d\'Ivoire', 'CI', 'Western Africa',  4],
        ['Gambia',         'GM', 'Western Africa',  4],
        ['Ghana',          'GH', 'Western Africa',  3],
        ['Guinea',         'GN', 'Western Africa',  5],
        ['Guinea-Bissau',  'GW', 'Western Africa',  5],
        ['Liberia',        'LR', 'Western Africa',  5],
        ['Mali',           'ML', 'Western Africa',  5],
        ['Mauritania',     'MR', 'Western Africa',  5],
        ['Niger',          'NE', 'Western Africa',  5],
        ['Nigeria',        'NG', 'Western Africa',  4],
        ['Senegal',        'SN', 'Western Africa',  3],
        ['Sierra Leone',   'SL', 'Western Africa',  5],
        ['Togo',           'TG', 'Western Africa',  5],

        // Northern Africa
        ['Algeria',        'DZ', 'Northern Africa', 4],
        ['Egypt',          'EG', 'Northern Africa', 3],
        ['Libya',          'LY', 'Northern Africa', 5],
        ['Morocco',        'MA', 'Northern Africa', 3],
        ['Sudan',          'SD', 'Northern Africa', 5],
        ['Tunisia',        'TN', 'Northern Africa', 4],

        // Central Africa
        ['Angola',                              'AO', 'Central Africa', 4],
        ['Cameroon',                            'CM', 'Central Africa', 4],
        ['Central African Republic',            'CF', 'Central Africa', 5],
        ['Chad',                                'TD', 'Central Africa', 5],
        ['Congo',                               'CG', 'Central Africa', 5],
        ['Democratic Republic of the Congo',    'CD', 'Central Africa', 4],
        ['Equatorial Guinea',                   'GQ', 'Central Africa', 5],
        ['Gabon',                               'GA', 'Central Africa', 3],
        ['Sao Tome and Principe',               'ST', 'Central Africa', 5],
    ];
}

// ---------------------------------------------------------------------------
// Seed data: top 50 reserves/parks
// ---------------------------------------------------------------------------

function getReservesParksSeed(): array
{
    // [name, slug, country_iso, park_type, latitude, longitude]
    return [
        // South Africa (10)
        ['Kruger National Park',               'kruger-national-park',           'ZA', 'national_park',    -23.98840000, 31.55420000],
        ['Sabi Sands Game Reserve',            'sabi-sands-game-reserve',        'ZA', 'private_reserve',  -24.80000000, 31.45000000],
        ['Timbavati Private Nature Reserve',   'timbavati-private-nature-reserve','ZA', 'private_reserve', -24.35000000, 31.35000000],
        ['MalaMala Game Reserve',              'malamala-game-reserve',          'ZA', 'private_reserve',  -24.78000000, 31.52000000],
        ['Pilanesberg National Park',          'pilanesberg-national-park',      'ZA', 'national_park',    -25.27380000, 27.08530000],
        ['Madikwe Game Reserve',               'madikwe-game-reserve',           'ZA', 'game_reserve',     -24.72000000, 26.28000000],
        ['Addo Elephant National Park',        'addo-elephant-national-park',    'ZA', 'national_park',    -33.44310000, 25.77120000],
        ['Hluhluwe-iMfolozi Park',             'hluhluwe-imfolozi-park',         'ZA', 'national_park',    -28.21930000, 31.95100000],
        ['iSimangaliso Wetland Park',          'isimangaliso-wetland-park',      'ZA', 'national_park',    -28.00580000, 32.55000000],
        ['Kgalagadi Transfrontier Park',       'kgalagadi-transfrontier-park',   'ZA', 'national_park',    -25.77890000, 20.60790000],

        // Kenya (6)
        ['Masai Mara National Reserve',        'masai-mara-national-reserve',    'KE', 'game_reserve',     -1.50650000, 35.14380000],
        ['Amboseli National Park',             'amboseli-national-park',         'KE', 'national_park',    -2.65270000, 37.26060000],
        ['Tsavo East National Park',           'tsavo-east-national-park',       'KE', 'national_park',    -2.98700000, 38.76700000],
        ['Tsavo West National Park',           'tsavo-west-national-park',       'KE', 'national_park',    -3.00500000, 38.10000000],
        ['Samburu National Reserve',           'samburu-national-reserve',       'KE', 'game_reserve',      0.61670000, 37.53330000],
        ['Laikipia Plateau',                   'laikipia-plateau',               'KE', 'conservancy',       0.30000000, 36.90000000],

        // Tanzania (6)
        ['Serengeti National Park',            'serengeti-national-park',        'TZ', 'national_park',    -2.33330000, 34.83330000],
        ['Ngorongoro Conservation Area',       'ngorongoro-conservation-area',   'TZ', 'national_park',    -3.17500000, 35.58780000],
        ['Tarangire National Park',            'tarangire-national-park',        'TZ', 'national_park',    -4.01670000, 36.01670000],
        ['Nyerere National Park',              'nyerere-national-park',          'TZ', 'national_park',    -9.00000000, 37.40000000],
        ['Ruaha National Park',                'ruaha-national-park',            'TZ', 'national_park',    -7.50000000, 34.90000000],
        ['Katavi National Park',               'katavi-national-park',           'TZ', 'national_park',    -6.83330000, 31.25000000],

        // Botswana (4)
        ['Okavango Delta',                     'okavango-delta',                 'BW', 'game_reserve',     -19.50000000, 22.95000000],
        ['Chobe National Park',                'chobe-national-park',            'BW', 'national_park',    -18.45000000, 25.15000000],
        ['Moremi Game Reserve',                'moremi-game-reserve',            'BW', 'game_reserve',     -19.43500000, 22.80580000],
        ['Makgadikgadi Pans National Park',    'makgadikgadi-pans-national-park','BW', 'national_park',    -20.50000000, 25.25000000],

        // Namibia (4)
        ['Etosha National Park',               'etosha-national-park',           'NA', 'national_park',    -18.85560000, 16.32860000],
        ['Namib-Naukluft National Park',        'namib-naukluft-national-park',   'NA', 'national_park',    -24.28850000, 15.84590000],
        ['Damaraland',                         'damaraland',                     'NA', 'conservancy',      -20.50000000, 14.50000000],
        ['Skeleton Coast National Park',       'skeleton-coast-national-park',   'NA', 'national_park',    -19.90710000, 13.01000000],

        // Zimbabwe (4)
        ['Hwange National Park',               'hwange-national-park',           'ZW', 'national_park',    -18.36050000, 26.50190000],
        ['Mana Pools National Park',           'mana-pools-national-park',       'ZW', 'national_park',    -15.75000000, 29.40000000],
        ['Gonarezhou National Park',           'gonarezhou-national-park',       'ZW', 'national_park',    -21.83330000, 31.75000000],
        ['Matusadona National Park',           'matusadona-national-park',       'ZW', 'national_park',    -16.78330000, 28.51670000],

        // Zambia (4)
        ['South Luangwa National Park',        'south-luangwa-national-park',    'ZM', 'national_park',    -13.08330000, 31.55000000],
        ['Lower Zambezi National Park',        'lower-zambezi-national-park',    'ZM', 'national_park',    -15.25000000, 29.60000000],
        ['Kafue National Park',                'kafue-national-park',            'ZM', 'national_park',    -14.78000000, 26.05000000],
        ['North Luangwa National Park',        'north-luangwa-national-park',    'ZM', 'national_park',    -12.20000000, 31.75000000],

        // Uganda (3)
        ['Bwindi Impenetrable National Park',  'bwindi-impenetrable-national-park','UG','national_park',   -1.04870000, 29.61870000],
        ['Queen Elizabeth National Park',       'queen-elizabeth-national-park',  'UG', 'national_park',     0.08470000, 30.02140000],
        ['Murchison Falls National Park',      'murchison-falls-national-park',  'UG', 'national_park',     2.26940000, 31.67680000],

        // Rwanda (1)
        ['Volcanoes National Park',            'volcanoes-national-park',        'RW', 'national_park',    -1.46360000, 29.53490000],

        // Mozambique (3)
        ['Gorongosa National Park',            'gorongosa-national-park',        'MZ', 'national_park',    -18.96840000, 34.35420000],
        ['Bazaruto Archipelago National Park', 'bazaruto-archipelago-national-park','MZ','marine_park',    -21.65000000, 35.47500000],
        ['Quirimbas National Park',            'quirimbas-national-park',        'MZ', 'national_park',    -12.28860000, 40.12370000],

        // Madagascar (1)
        ['Andasibe-Mantadia National Park',    'andasibe-mantadia-national-park','MG', 'national_park',    -18.92670000, 48.42570000],

        // Malawi (1)
        ['Liwonde National Park',              'liwonde-national-park',          'MW', 'national_park',    -15.05000000, 35.33330000],

        // Ethiopia (1)
        ['Simien Mountains National Park',     'simien-mountains-national-park', 'ET', 'national_park',     13.26810000, 38.23830000],

        // Democratic Republic of the Congo (1)
        ['Virunga National Park',              'virunga-national-park',          'CD', 'national_park',    -0.90000000, 29.20000000],

        // Central African Republic (1)
        ['Dzanga-Sangha Special Reserve',      'dzanga-sangha-special-reserve',  'CF', 'forest_reserve',    2.38330000, 16.23330000],

        // Gabon (1)
        ['Lope National Park',                 'lope-national-park',             'GA', 'national_park',    -0.48330000, 11.53330000],

        // Senegal (1)
        ['Niokolo-Koba National Park',         'niokolo-koba-national-park',     'SN', 'national_park',    12.89000000, -12.73000000],

        // Ghana (1)
        ['Mole National Park',                 'mole-national-park',             'GH', 'national_park',     9.26670000, -1.85000000],

        // Eswatini (1)
        ['Hlane Royal National Park',          'hlane-royal-national-park',      'SZ', 'national_park',    -26.24160000, 31.87500000],

        // Egypt (1)
        ['Ras Mohammed National Park',         'ras-mohammed-national-park',     'EG', 'marine_park',      27.72710000, 34.25520000],
    ];
}

// ---------------------------------------------------------------------------
// Seed data: settings
// ---------------------------------------------------------------------------

function getSettingsSeed(string $apiKey, string $adminEmail): array
{
    // [setting_key, setting_value, setting_type, description]
    return [
        ['daily_api_budget',       '10.00',                          'float',  'Maximum daily spend on third-party APIs (USD)'],
        ['scrape_delay_ms',        '3000',                           'int',    'Minimum delay between scrape requests in milliseconds'],
        ['max_outreach_per_day',   '50',                             'int',    'Maximum outreach emails to send per day'],
        ['google_places_api_key',  $apiKey,                          'string', 'Google Places API key for property discovery'],
        ['smtp_host',              '',                                'string', 'SMTP mail server hostname'],
        ['smtp_port',              '587',                             'int',    'SMTP mail server port'],
        ['smtp_user',              '',                                'string', 'SMTP authentication username'],
        ['smtp_password',          '',                                'string', 'SMTP authentication password'],
        ['admin_email',            $adminEmail,                      'string', 'Primary administrator email address'],
        ['site_name',              'Safari Traveller',               'string', 'Website display name'],
        ['site_url',               'https://safari-traveller.com',   'string', 'Primary website URL'],
    ];
}

// ---------------------------------------------------------------------------
// Seed data: discovery search terms for Tier 1 countries
// ---------------------------------------------------------------------------

function getDiscoverySearchTerms(): array
{
    return [
        'safari lodge',
        'game lodge',
        'bush camp',
        'tented camp',
        'safari villa',
        'wildlife lodge',
        'game reserve accommodation',
        'national park lodge',
        'eco lodge safari',
        'luxury safari',
    ];
}

// ---------------------------------------------------------------------------
// Seed data: cron schedule
// ---------------------------------------------------------------------------

function getCronScheduleSeed(): array
{
    // [job_name, script_path, frequency]
    return [
        ['discover', 'cron/discover.php', 'every_15_min'],
        ['enrich',   'cron/enrich.php',   'every_5_min'],
        ['outreach', 'cron/outreach.php', 'daily'],
    ];
}

// ---------------------------------------------------------------------------
// Write config.php
// ---------------------------------------------------------------------------

function writeConfig(string $dbHost, string $dbName, string $dbUser, string $dbPass, string $apiKey): void
{
    $configPath = __DIR__ . '/config.php';

    $dbHostEsc  = addslashes($dbHost);
    $dbNameEsc  = addslashes($dbName);
    $dbUserEsc  = addslashes($dbUser);
    $dbPassEsc  = addslashes($dbPass);
    $apiKeyEsc  = addslashes($apiKey);

    $content = <<<PHP
<?php
/**
 * Safari Traveller - Configuration
 *
 * Generated by installer on %s
 * Do NOT commit this file to version control.
 */

declare(strict_types=1);

// Database
define('DB_HOST', '{$dbHostEsc}');
define('DB_NAME', '{$dbNameEsc}');
define('DB_USER', '{$dbUserEsc}');
define('DB_PASS', '{$dbPassEsc}');

// Google Places API
define('GOOGLE_PLACES_API_KEY', '{$apiKeyEsc}');

// PDO connection helper
function getDB(): PDO
{
    static \$pdo = null;

    if (\$pdo === null) {
        \$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return \$pdo;
}
PHP;

    $content = sprintf($content, date('Y-m-d H:i:s'));

    file_put_contents($configPath, $content);
}

// ---------------------------------------------------------------------------
// Run installation
// ---------------------------------------------------------------------------

function runInstall(
    string $dbHost,
    string $dbName,
    string $dbUser,
    string $dbPass,
    string $adminEmail,
    string $adminPass,
    string $googleApiKey
): array {
    $stats = [
        'tables'          => 0,
        'countries'       => 0,
        'reserves'        => 0,
        'settings'        => 0,
        'discovery_queue' => 0,
        'cron_jobs'       => 0,
    ];

    // -----------------------------------------------------------------------
    // Connect
    // -----------------------------------------------------------------------
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // -----------------------------------------------------------------------
    // Create tables
    // -----------------------------------------------------------------------
    $tables = getTableSQL();

    foreach ($tables as $tableName => $sql) {
        $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
    }

    // Create in correct order (foreign key dependencies)
    foreach ($tables as $tableName => $sql) {
        $pdo->exec($sql);
        $stats['tables']++;
    }

    // -----------------------------------------------------------------------
    // Seed countries
    // -----------------------------------------------------------------------
    $countries = getCountriesSeed();
    $stmtCountry = $pdo->prepare(
        'INSERT INTO countries (name, iso_code, region, tier) VALUES (:name, :iso_code, :region, :tier)'
    );

    foreach ($countries as $c) {
        $stmtCountry->execute([
            ':name'     => $c[0],
            ':iso_code' => $c[1],
            ':region'   => $c[2],
            ':tier'     => $c[3],
        ]);
        $stats['countries']++;
    }

    // Build a lookup: iso_code => country_id
    $countryLookup = [];
    $rows = $pdo->query('SELECT id, iso_code, tier FROM countries')->fetchAll();
    foreach ($rows as $row) {
        $countryLookup[$row['iso_code']] = [
            'id'   => (int) $row['id'],
            'tier' => (int) $row['tier'],
        ];
    }

    // -----------------------------------------------------------------------
    // Seed reserves / parks
    // -----------------------------------------------------------------------
    $reserves = getReservesParksSeed();
    $stmtReserve = $pdo->prepare(
        'INSERT INTO reserves_parks (name, slug, country_id, park_type, latitude, longitude) '
        . 'VALUES (:name, :slug, :country_id, :park_type, :latitude, :longitude)'
    );

    foreach ($reserves as $r) {
        $countryId = $countryLookup[$r[2]]['id'] ?? null;
        if ($countryId === null) {
            continue;
        }
        $stmtReserve->execute([
            ':name'       => $r[0],
            ':slug'       => $r[1],
            ':country_id' => $countryId,
            ':park_type'  => $r[3],
            ':latitude'   => $r[4],
            ':longitude'  => $r[5],
        ]);
        $stats['reserves']++;
    }

    // -----------------------------------------------------------------------
    // Seed settings
    // -----------------------------------------------------------------------
    $settings = getSettingsSeed($googleApiKey, $adminEmail);
    $stmtSetting = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, setting_type, description) '
        . 'VALUES (:setting_key, :setting_value, :setting_type, :description)'
    );

    foreach ($settings as $s) {
        $stmtSetting->execute([
            ':setting_key'   => $s[0],
            ':setting_value' => $s[1],
            ':setting_type'  => $s[2],
            ':description'   => $s[3],
        ]);
        $stats['settings']++;
    }

    // -----------------------------------------------------------------------
    // Seed discovery_queue (Tier 1 countries x search terms)
    // -----------------------------------------------------------------------
    $searchTerms = getDiscoverySearchTerms();
    $stmtDiscovery = $pdo->prepare(
        'INSERT INTO discovery_queue (country_id, search_term) VALUES (:country_id, :search_term)'
    );

    foreach ($countryLookup as $iso => $info) {
        if ($info['tier'] !== 1) {
            continue;
        }
        foreach ($searchTerms as $term) {
            $stmtDiscovery->execute([
                ':country_id'  => $info['id'],
                ':search_term' => $term,
            ]);
            $stats['discovery_queue']++;
        }
    }

    // -----------------------------------------------------------------------
    // Seed cron_schedule
    // -----------------------------------------------------------------------
    $cronJobs = getCronScheduleSeed();
    $stmtCron = $pdo->prepare(
        'INSERT INTO cron_schedule (job_name, script_path, frequency) '
        . 'VALUES (:job_name, :script_path, :frequency)'
    );

    foreach ($cronJobs as $job) {
        $stmtCron->execute([
            ':job_name'    => $job[0],
            ':script_path' => $job[1],
            ':frequency'   => $job[2],
        ]);
        $stats['cron_jobs']++;
    }

    // -----------------------------------------------------------------------
    // Create admin user
    // -----------------------------------------------------------------------
    $passwordHash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmtAdmin = $pdo->prepare(
        'INSERT INTO admin_users (email, password_hash, name, role) '
        . 'VALUES (:email, :password_hash, :name, :role)'
    );

    $stmtAdmin->execute([
        ':email'         => $adminEmail,
        ':password_hash' => $passwordHash,
        ':name'          => 'Administrator',
        ':role'          => 'admin',
    ]);

    // -----------------------------------------------------------------------
    // Write config.php
    // -----------------------------------------------------------------------
    writeConfig($dbHost, $dbName, $dbUser, $dbPass, $googleApiKey);

    return $stats;
}

// ---------------------------------------------------------------------------
// Show success page
// ---------------------------------------------------------------------------

function showSuccess(array $stats): void
{
    echo renderHeader();

    $tableCount     = $stats['tables'];
    $countryCount   = $stats['countries'];
    $reserveCount   = $stats['reserves'];
    $settingCount   = $stats['settings'];
    $discoveryCount = $stats['discovery_queue'];
    $cronCount      = $stats['cron_jobs'];

    echo <<<HTML
    <div class="card success-card">
        <span class="success-icon">&#10003;</span>
        <h2>Installation Complete</h2>
        <p>Safari Traveller has been installed successfully.</p>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="num">{$tableCount}</div>
                <div class="label">Tables Created</div>
            </div>
            <div class="stat-box">
                <div class="num">{$countryCount}</div>
                <div class="label">Countries</div>
            </div>
            <div class="stat-box">
                <div class="num">{$reserveCount}</div>
                <div class="label">Reserves &amp; Parks</div>
            </div>
            <div class="stat-box">
                <div class="num">{$settingCount}</div>
                <div class="label">Settings</div>
            </div>
            <div class="stat-box">
                <div class="num">{$discoveryCount}</div>
                <div class="label">Discovery Queue</div>
            </div>
            <div class="stat-box">
                <div class="num">{$cronCount}</div>
                <div class="label">Cron Jobs</div>
            </div>
        </div>

        <ul class="links-list">
            <li><a href="admin/">Admin Dashboard</a></li>
            <li><a href="public/">Public Website</a></li>
        </ul>

        <div class="warning">
            <strong>Security Notice:</strong> This installer file has been automatically deleted.
            The generated <code>config.php</code> contains your database credentials &mdash;
            ensure it is not publicly accessible and never committed to version control.
        </div>
    </div>
HTML;

    echo renderFooter();
}

// ===========================================================================
// MAIN EXECUTION
// ===========================================================================

// Block access if config.php already exists (already installed)
if (file_exists(__DIR__ . '/config.php')) {
    http_response_code(403);
    echo renderHeader();
    echo '<div class="alert alert-error">Safari Traveller is already installed. '
       . 'If you need to reinstall, delete <code>config.php</code> first.</div>';
    echo renderFooter();
    exit;
}

// ---- Show form or process submission ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['install'])) {
    showForm();
    exit;
}

// ---- Validate inputs ----
$dbHost       = trim($_POST['db_host']       ?? '');
$dbName       = trim($_POST['db_name']       ?? '');
$dbUser       = trim($_POST['db_user']       ?? '');
$dbPass       =      $_POST['db_pass']       ?? '';
$adminEmail   = trim($_POST['admin_email']   ?? '');
$adminPass    =      $_POST['admin_pass']    ?? '';
$googleApiKey = trim($_POST['google_api_key'] ?? '');

if ($dbHost === '' || $dbName === '' || $dbUser === '') {
    showForm('Please fill in all database connection fields.');
    exit;
}

if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    showForm('Please provide a valid admin email address.');
    exit;
}

if (strlen($adminPass) < 8) {
    showForm('Admin password must be at least 8 characters.');
    exit;
}

if ($googleApiKey === '') {
    showForm('Please provide your Google Places API key.');
    exit;
}

// ---- Run installation ----
try {
    $stats = runInstall($dbHost, $dbName, $dbUser, $dbPass, $adminEmail, $adminPass, $googleApiKey);
} catch (PDOException $e) {
    showForm('Database error: ' . $e->getMessage());
    exit;
} catch (Throwable $e) {
    showForm('Installation error: ' . $e->getMessage());
    exit;
}

// ---- Show success page ----
showSuccess($stats);

// ---- Self-delete this installer ----
@unlink(__FILE__);
