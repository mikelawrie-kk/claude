<?php
/**
 * File: scripts/migrate-add-site-entity.php
 * Description: One-shot migration for Pulse installs that pre-date v1.1.0 — adds the site_entity table. Idempotent (CREATE TABLE IF NOT EXISTS). Run from CLI: `php scripts/migrate-add-site-entity.php`.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 09:05 SAST
 * Modified: 2026-11-03 09:05 SAST
 * Changes:
 *   1.0.0 (2026-11-03 09:05) — initial creation
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!pulse_installed()) {
    fwrite(STDERR, "Pulse not installed. Run /install.php first.\n");
    exit(2);
}

$db = DB::getInstance();
$pdo = $db->pdo();

$ddl = 'CREATE TABLE IF NOT EXISTS site_entity (
    id INTEGER PRIMARY KEY,
    site_id INTEGER UNIQUE REFERENCES sites(id),
    entity_type TEXT DEFAULT \'LodgingBusiness\',
    legal_name TEXT,
    founding_date TEXT,
    slogan TEXT,
    description TEXT,
    gbp_url TEXT,
    llm_txt_url TEXT,
    rating_value REAL,
    rating_count INTEGER,
    alternate_names_json TEXT,
    keywords_json TEXT,
    knows_about_json TEXT,
    ota_urls_json TEXT,
    social_urls_json TEXT,
    founder_json TEXT,
    operator_json TEXT,
    parent_org_json TEXT,
    area_served_json TEXT,
    extras_json TEXT,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)';

$pdo->exec($ddl);
echo "✓ site_entity table ready\n";

// Add sites.mcp_project column if absent (older installs lack it)
$cols = $db->fetchAll('PRAGMA table_info(sites)');
$hasMcpProject = false;
foreach ($cols as $c) {
    if (($c['name'] ?? '') === 'mcp_project') {
        $hasMcpProject = true;
        break;
    }
}
if (!$hasMcpProject) {
    $pdo->exec('ALTER TABLE sites ADD COLUMN mcp_project TEXT');
    echo "✓ sites.mcp_project column added\n";
} else {
    echo "✓ sites.mcp_project column already present\n";
}

// Enable TechSEOMonitor if present and still disabled
$job = $db->fetchOne("SELECT id, enabled FROM jobs WHERE name = 'TechSEOMonitor'");
if ($job && (int) $job['enabled'] === 0) {
    $db->execute('UPDATE jobs SET enabled = 1, config_json = ? WHERE id = ?', [
        json_encode([
            'checks' => ['Schema', 'Indexing', 'CWV', 'BrokenLink', 'Redirect', 'Robots', 'HTTPStatus'],
            'max_internal_links' => 100,
            'pagespeed_api_key' => '',
        ], JSON_UNESCAPED_SLASHES),
        $job['id'],
    ]);
    echo "✓ TechSEOMonitor job enabled\n";
} elseif ($job) {
    echo "✓ TechSEOMonitor job already enabled\n";
} else {
    echo "! TechSEOMonitor job row missing — re-run install.php to seed it\n";
}

$sites = $db->fetchAll('SELECT id, domain FROM sites WHERE enabled = 1');
foreach ($sites as $site) {
    $existing = $db->fetchOne('SELECT id FROM site_entity WHERE site_id = ?', [$site['id']]);
    if ($existing) {
        echo "✓ site_entity row for {$site['domain']} exists\n";
        continue;
    }
    $db->execute(
        'INSERT INTO site_entity (site_id, entity_type) VALUES (?, ?)',
        [$site['id'], 'LodgingBusiness']
    );
    echo "✓ seeded empty site_entity row for {$site['domain']} (edit via admin UI in Week 4)\n";
}

echo "\nMigration complete. Weekly audit will run on next Monday 06:00 SAST unless the next_run_at is cleared.\n";
exit(0);
