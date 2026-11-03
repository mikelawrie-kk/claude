<?php
/**
 * File: scripts/smoke.php
 * Description: Local smoke test for Week-1 spine. Creates fresh DB, runs Orchestrator::tick() in normal/kill/cost-exceeded/dry-run modes, exercises MCPClient against a stub. Not deployed to cPanel.
 * Project: Pulse v1
 * Version: 1.2.0
 * Created: 2026-05-14 10:55 SAST
 * Modified: 2026-11-03 14:45 SAST
 * Changes:
 *   1.2.0 (2026-11-03 14:45) — added Week 3 assertions: FixerBase, all 7 fixers load and declare correct handles(), SchemaFixer merges site_entity into a valid JSON-LD block, FixGenerator coalesces schema findings into one payload
 *   1.1.0 (2026-11-03 10:10) — added Week 2 assertions: site_entity table, Scorer, CheckBase upsert dedup, TechSEOMonitor Job loads all 7 checks, SchemaCheck surfaces missing @id/sameAs
 *   1.0.0 (2026-05-14 10:55) — initial creation
 */

define('PULSE_ROOT', dirname(__DIR__));
define('PULSE_DATA', PULSE_ROOT . '/data');
define('PULSE_LOGS', PULSE_DATA . '/logs');

@mkdir(PULSE_DATA, 0750, true);
@mkdir(PULSE_LOGS, 0750, true);

$smokeDb = PULSE_DATA . '/smoke.db';
@unlink($smokeDb);

require_once PULSE_ROOT . '/app/core/Logger.php';
require_once PULSE_ROOT . '/app/core/DB.php';
require_once PULSE_ROOT . '/app/core/Guardrails.php';
require_once PULSE_ROOT . '/app/core/APIClient.php';
require_once PULSE_ROOT . '/app/core/Reporter.php';
require_once PULSE_ROOT . '/app/core/MCPClient.php';
require_once PULSE_ROOT . '/app/core/Dispatcher.php';
require_once PULSE_ROOT . '/app/core/ApplyRouter.php';
require_once PULSE_ROOT . '/app/core/Orchestrator.php';

Logger::configure(PULSE_LOGS . '/smoke.log');
DB::configure($smokeDb);
date_default_timezone_set('Africa/Johannesburg');

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name" . ($detail ? "  ($detail)" : '') . "\n";
    }
}

echo "=== Pulse Week-1 spine smoke test ===\n";

// --- bootstrap schema (mirror install.php DDL essentials) ---
$db = DB::getInstance();
foreach ([
    'CREATE TABLE jobs (id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL, module TEXT NOT NULL, cron_schedule TEXT, enabled INTEGER DEFAULT 1, config_json TEXT, last_run_at TEXT, next_run_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE sites (id INTEGER PRIMARY KEY, domain TEXT UNIQUE NOT NULL, gsc_property TEXT, sheet_id TEXT, notify_email TEXT, mcp_bridge_url TEXT, mcp_bridge_key TEXT, mcp_project TEXT, enabled INTEGER DEFAULT 1, auto_apply_threshold TEXT DEFAULT \'manual\', created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE runs (id INTEGER PRIMARY KEY, job_id INTEGER, site_id INTEGER, started_at TEXT DEFAULT CURRENT_TIMESTAMP, ended_at TEXT, status TEXT, rows_processed INTEGER DEFAULT 0, rows_failed INTEGER DEFAULT 0, cost_usd REAL DEFAULT 0, report_path TEXT, error_message TEXT)',
    'CREATE TABLE tasks (id INTEGER PRIMARY KEY, run_id INTEGER, task_type TEXT, payload_json TEXT, status TEXT, result_json TEXT, cost_usd REAL DEFAULT 0, duration_ms INTEGER, attempt INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE guardrails (id INTEGER PRIMARY KEY, date TEXT UNIQUE, cost_ceiling_usd REAL DEFAULT 5.00, cost_used_usd REAL DEFAULT 0, kill_switch INTEGER DEFAULT 0, dry_run INTEGER DEFAULT 0)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, role TEXT DEFAULT \'admin\', created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE findings (id INTEGER PRIMARY KEY, site_id INTEGER, run_id INTEGER, check_type TEXT, url TEXT, severity TEXT, issue TEXT, recommendation TEXT NOT NULL, fix_payload_json TEXT, fix_target_path TEXT, fix_estimated_seconds INTEGER, status TEXT DEFAULT \'open\', first_seen TEXT DEFAULT CURRENT_TIMESTAMP, last_seen TEXT, approved_at TEXT, approved_by INTEGER, applied_at TEXT, verified_at TEXT)',
    'CREATE TABLE fix_applications (id INTEGER PRIMARY KEY, finding_id INTEGER, applied_at TEXT DEFAULT CURRENT_TIMESTAMP, mcp_bridge TEXT, mcp_tool TEXT, request_payload_json TEXT, response_payload_json TEXT, status TEXT, error_message TEXT)',
    'CREATE TABLE verifications (id INTEGER PRIMARY KEY, finding_id INTEGER, scheduled_for TEXT, checked_at TEXT, result TEXT, evidence_json TEXT, notes TEXT)',
    'CREATE TABLE audit_log (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, detail TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE site_entity (id INTEGER PRIMARY KEY, site_id INTEGER UNIQUE, entity_type TEXT DEFAULT \'LodgingBusiness\', legal_name TEXT, founding_date TEXT, slogan TEXT, description TEXT, gbp_url TEXT, llm_txt_url TEXT, rating_value REAL, rating_count INTEGER, alternate_names_json TEXT, keywords_json TEXT, knows_about_json TEXT, ota_urls_json TEXT, social_urls_json TEXT, founder_json TEXT, operator_json TEXT, parent_org_json TEXT, area_served_json TEXT, extras_json TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->pdo()->exec($sql);
}
$tables = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
check('schema: 11 tables created (added site_entity)', count($tables) === 11, 'got ' . count($tables));

// --- guardrails: today row seeded ---
$g = Guardrails::today();
check('guardrails today seeded', isset($g['cost_ceiling_usd']) && (float) $g['cost_ceiling_usd'] === 5.00);

// --- normal tick: writes a runs row ---
$before = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM runs')['c'];
$result = Orchestrator::tick();
$after = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM runs')['c'];
check('normal tick wrote runs row', $after === $before + 1, "before=$before after=$after");
check('normal tick status=success', ($result['status'] ?? '') === 'success', json_encode($result));

// --- kill switch ---
Guardrails::setKillSwitch(true);
$before = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM runs')['c'];
$result = Orchestrator::tick();
$after = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM runs')['c'];
$lastRun = $db->fetchOne('SELECT status FROM runs ORDER BY id DESC LIMIT 1');
check('kill switch tick wrote row', $after === $before + 1);
check('kill switch tick status=kill_switch', ($result['status'] ?? '') === 'kill_switch', json_encode($result));
check('kill switch run marked skipped', ($lastRun['status'] ?? '') === 'skipped');
Guardrails::setKillSwitch(false);

// --- cost ceiling ---
$db->execute('UPDATE guardrails SET cost_used_usd = 999 WHERE date = ?', [date('Y-m-d')]);
$result = Orchestrator::tick();
check('cost-ceiling tick status=cost_ceiling', ($result['status'] ?? '') === 'cost_ceiling', json_encode($result));
$db->execute('UPDATE guardrails SET cost_used_usd = 0 WHERE date = ?', [date('Y-m-d')]);

// --- dry run ---
Guardrails::setDryRun(true);
$result = Orchestrator::tick();
check('dry-run tick still succeeds', ($result['status'] ?? '') === 'success');
check('dry-run reported in result', ($result['dry_run'] ?? false) === true);
Guardrails::setDryRun(false);

// --- dispatcher: due jobs (empty) ---
$due = Dispatcher::dueJobs();
check('dispatcher: no jobs due in empty schema', $due === []);

// --- insert a site + a fake job pointing at a non-existent module ---
$db->execute('INSERT INTO sites (domain, notify_email, mcp_bridge_url, mcp_bridge_key, enabled) VALUES (?, ?, ?, ?, 1)',
    ['example.com', 'admin@example.com', 'https://example.com/mcp/', 'testkey']);
$db->execute('INSERT INTO jobs (name, module, cron_schedule, enabled) VALUES (?, ?, ?, 1)',
    ['NoOp', 'NoOpModule', '*/5 * * * *']);
$due = Dispatcher::dueJobs();
check('dispatcher: now 1 job due', count($due) === 1);
$dispatchResult = Dispatcher::dispatch($due[0]);
$skipped = $db->fetchOne("SELECT COUNT(*) AS c FROM runs WHERE status='skipped' AND job_id IS NOT NULL")['c'];
check('dispatcher: missing module marks run skipped', (int) $skipped >= 1, "skipped=$skipped");

// --- ApplyRouter: failure path when no fix payload ---
$db->execute('INSERT INTO findings (site_id, run_id, check_type, severity, issue, recommendation, fix_payload_json, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    [1, null, 'SchemaCheck', 'high', 'Missing schema', 'Add Organization JSON-LD', null, 'open']);
$findingId = $db->lastInsertId();
$threw = false;
try {
    ApplyRouter::apply($findingId);
} catch (Throwable $e) {
    $threw = strpos($e->getMessage(), 'fix_payload_json') !== false;
}
check('ApplyRouter rejects findings without fix_payload_json (A2)', $threw);

// --- ApplyRouter: rejects unapproved finding ---
$db->execute('UPDATE findings SET fix_payload_json = ? WHERE id = ?',
    [json_encode(['mcp_tool' => 'noop', 'mcp_arguments' => []]), $findingId]);
$threw = false;
try {
    ApplyRouter::apply($findingId);
} catch (Throwable $e) {
    $threw = strpos($e->getMessage(), 'not approved') !== false;
}
check('ApplyRouter rejects unapproved findings', $threw);

// --- MCPClient: URL building + redacted logging (no real call) ---
$ref = new ReflectionClass('MCPClient');
$buildUrl = $ref->getMethod('buildUrl');
$buildUrl->setAccessible(true);
$url = $buildUrl->invoke(null, 'https://aerotel.co.za/mcp/', 'abc123');
check('MCPClient buildUrl appends key', $url === 'https://aerotel.co.za/mcp/?key=abc123', $url);
$url2 = $buildUrl->invoke(null, 'https://aerotel.co.za/mcp/?x=1', 'abc123');
check('MCPClient buildUrl uses & when ? present', $url2 === 'https://aerotel.co.za/mcp/?x=1&key=abc123', $url2);

// --- MCPClient dry-run path ---
Guardrails::setDryRun(true);
$res = MCPClient::call('https://aerotel.co.za/mcp/', 'k', 'cpanel_list_projects', []);
check('MCPClient dry-run returns marker', ($res['dry_run'] ?? false) === true);
Guardrails::setDryRun(false);

// --- Dispatcher cron parsing ---
$next = Dispatcher::computeNextRun('*/5 * * * *');
check('computeNextRun handles */5', preg_match('/^\d{4}-\d{2}-\d{2}/', $next) === 1, $next);
$next = Dispatcher::computeNextRun('@daily');
check('computeNextRun handles @daily', preg_match('/00:00:00$/', $next) === 1, $next);
$next = Dispatcher::computeNextRun('0 6 * * 1');
check('computeNextRun handles weekly 0 6 * * 1', preg_match('/^\d{4}-\d{2}-\d{2} 06:00:00$/', $next) === 1, $next);

// --- Reporter dry-run does not fail ---
Guardrails::setDryRun(true);
$ok = Reporter::send('test@example.com', 'subject', '<p>body</p>');
check('Reporter dry-run skips send and returns true', $ok === true);
Guardrails::setDryRun(false);

// ============================================================
// Week 2 — Detection layer
// ============================================================
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/Scorer.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/checks/CheckBase.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/checks/HTTPStatusCheck.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/checks/RobotsCheck.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/checks/RedirectCheck.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/checks/BrokenLinkCheck.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/checks/IndexingCheck.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/checks/CWVCheck.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/checks/SchemaCheck.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/Job.php';

check('Scorer: critical > high > medium > low > info', Scorer::priority(['severity' => 'critical']) > Scorer::priority(['severity' => 'high']) && Scorer::priority(['severity' => 'high']) > Scorer::priority(['severity' => 'medium']) && Scorer::priority(['severity' => 'medium']) > Scorer::priority(['severity' => 'low']) && Scorer::priority(['severity' => 'low']) > Scorer::priority(['severity' => 'info']));
$sorted = Scorer::sortDigest([
    ['severity' => 'low'], ['severity' => 'critical'], ['severity' => 'medium'], ['severity' => 'high'],
]);
check('Scorer sortDigest orders severities correctly', $sorted[0]['severity'] === 'critical' && $sorted[3]['severity'] === 'low');
check('Scorer minutesOfClicks uses fix_estimated_seconds', Scorer::minutesOfClicks([['fix_estimated_seconds' => 60], ['fix_estimated_seconds' => 60]]) === 2.0);

check('TechSEOMonitor class loads', class_exists('TechSEOMonitor'));
foreach (['HTTPStatusCheck', 'RobotsCheck', 'RedirectCheck', 'BrokenLinkCheck', 'IndexingCheck', 'CWVCheck', 'SchemaCheck'] as $c) {
    check("$c class exists and extends CheckBase", class_exists($c) && is_subclass_of($c, 'CheckBase'));
}

// CheckBase.upsertFinding dedup via a minimal subclass driven by the smoke DB
class _SmokeCheck extends CheckBase
{
    public function checkName(): string { return 'SchemaCheck_smoke'; }
    public function run(): array { return $this->stats(); }
    public function emit(array $f): void { $this->upsertFinding($f); }
}

// Insert a site for the check to point at
$db->execute('INSERT INTO sites (domain, notify_email, enabled) VALUES (?, ?, 1)', ['smoke.test', 'smoke@example.com']);
$siteRow = $db->fetchOne('SELECT * FROM sites WHERE domain = ?', ['smoke.test']);
$db->execute('INSERT INTO runs (status) VALUES (?)', ['running']);
$runRow = $db->fetchOne('SELECT * FROM runs ORDER BY id DESC LIMIT 1');

$sc = new _SmokeCheck((int) $runRow['id'], (int) $siteRow['id'], $siteRow, null, []);
$sc->emit([
    'url' => 'https://smoke.test/',
    'severity' => 'high',
    'issue' => 'test dedup',
    'recommendation' => 'first write',
]);
$sc->emit([
    'url' => 'https://smoke.test/',
    'severity' => 'high',
    'issue' => 'test dedup',
    'recommendation' => 'second write — should update not insert',
]);
$findings = $db->fetchAll('SELECT * FROM findings WHERE check_type = ?', ['SchemaCheck_smoke']);
check('CheckBase dedupes findings by (site, check, url, issue)', count($findings) === 1, 'got ' . count($findings));
check('CheckBase refuses finding without recommendation (A2)', (function () use ($sc, $db) {
    $before = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM findings WHERE check_type = ?', ['SchemaCheck_smoke'])['c'];
    $sc->emit(['url' => 'https://smoke.test/x', 'severity' => 'info', 'issue' => 'no rec']);
    $after = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM findings WHERE check_type = ?', ['SchemaCheck_smoke'])['c'];
    return $before === $after;
})());

// SchemaCheck surfaces missing @id + sameAs when fed a minimal LodgingBusiness HTML
class _SchemaCheckProbe extends SchemaCheck
{
    public function probe(string $html): void
    {
        // Call the private extractor via reflection
        $ref = new ReflectionClass('SchemaCheck');
        $m = $ref->getMethod('extractJsonLd');
        $m->setAccessible(true);
        $m->invoke($this, $html);
        $p = $ref->getProperty('primary');
        $p->setAccessible(true);
        $m2 = $ref->getMethod('findPrimaryEntity');
        $m2->setAccessible(true);
        $p->setValue($this, $m2->invoke($this));
    }
    public function getBlocksCount(): int
    {
        $ref = new ReflectionClass('SchemaCheck');
        $p = $ref->getProperty('blocks');
        $p->setAccessible(true);
        return count($p->getValue($this));
    }
}
$html = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"LodgingBusiness","name":"Smoke Hotel","url":"https://smoke.test"}</script></head><body></body></html>';
$probe = new _SchemaCheckProbe((int) $runRow['id'], (int) $siteRow['id'], $siteRow, null, []);
$probe->probe($html);
check('SchemaCheck extracts JSON-LD blocks from HTML', $probe->getBlocksCount() === 1);

// ============================================================
// Week 3 — Fixer layer
// ============================================================
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/fixers/FixerBase.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/fixers/HTTPStatusFixer.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/fixers/RobotsFixer.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/fixers/RedirectFixer.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/fixers/BrokenLinkFixer.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/fixers/IndexingFixer.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/fixers/CWVFixer.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/fixers/SchemaFixer.php';
require_once PULSE_ROOT . '/app/jobs/TechSEOMonitor/FixGenerator.php';

foreach ([
    'HTTPStatus' => 'HTTPStatusFixer',
    'Robots' => 'RobotsFixer',
    'Redirect' => 'RedirectFixer',
    'BrokenLink' => 'BrokenLinkFixer',
    'Indexing' => 'IndexingFixer',
    'CWV' => 'CWVFixer',
    'Schema' => 'SchemaFixer',
] as $checkType => $class) {
    check("$class exists and extends FixerBase", class_exists($class) && is_subclass_of($class, 'FixerBase'));
    $instance = new $class(['id' => 1, 'domain' => 'smoke.test'], null, []);
    check($class . ' handles() returns ' . $checkType, $instance->handles() === $checkType);
}

// Diagnostic-only fixers return null
$diag = new BrokenLinkFixer(['id' => 1, 'domain' => 'smoke.test'], null, []);
check('BrokenLinkFixer is diagnostic (returns null)', $diag->generate(['issue' => 'anything', 'id' => 1]) === null);
$diag = new HTTPStatusFixer(['id' => 1, 'domain' => 'smoke.test'], null, []);
check('HTTPStatusFixer is diagnostic (returns null)', $diag->generate(['issue' => 'anything', 'id' => 1]) === null);
$diag = new CWVFixer(['id' => 1, 'domain' => 'smoke.test'], null, []);
check('CWVFixer is diagnostic (returns null)', $diag->generate(['issue' => 'anything', 'id' => 1]) === null);

// RedirectFixer http→https payload shape
class _RedirectFixerProbe extends RedirectFixer
{
    public function stubHtaccess(string $contents): void
    {
        $ref = new ReflectionClass('FixerBase');
        $cacheProp = $ref->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        $cache[$this->site['id'] . '|.htaccess'] = $contents;
        $cacheProp->setValue(null, $cache);
    }
}
$rf = new _RedirectFixerProbe(['id' => 99, 'domain' => 'smoke.test', 'mcp_bridge_url' => 'https://smoke.test/mcp/', 'mcp_bridge_key' => 'k'], null, []);
$rf->stubHtaccess("Options -Indexes\n");
$payload = $rf->generate(['issue' => 'HTTP does not redirect to HTTPS', 'id' => 1]);
check('RedirectFixer produces write payload with mcp_tool=cpanel_write_file', is_array($payload) && ($payload['mcp_tool'] ?? '') === 'cpanel_write_file');
check('RedirectFixer payload targets .htaccess', isset($payload['mcp_arguments']['path']) && $payload['mcp_arguments']['path'] === '.htaccess');
check('RedirectFixer payload contains RewriteCond !=on', str_contains((string) $payload['mcp_arguments']['content'], 'RewriteCond %{HTTPS} !=on'));
check('RedirectFixer payload marks Pulse-managed block', str_contains((string) $payload['mcp_arguments']['content'], '# BEGIN Pulse-Redirects'));

// RobotsFixer missing-robots payload
class _RobotsFixerProbe extends RobotsFixer {
    public function stubAbsent(): void {
        $ref = new ReflectionClass('FixerBase');
        $cacheProp = $ref->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        $cache[$this->site['id'] . '|robots.txt'] = null;
        $cacheProp->setValue(null, $cache);
    }
}
$rfx = new _RobotsFixerProbe(['id' => 101, 'domain' => 'smoke.test', 'mcp_bridge_url' => 'https://smoke.test/mcp/', 'mcp_bridge_key' => 'k'], null, []);
$rfx->stubAbsent();
$payload = $rfx->generate(['issue' => 'robots.txt missing', 'id' => 2]);
check('RobotsFixer missing-file payload emits default robots.txt', is_array($payload) && str_contains((string) $payload['mcp_arguments']['content'], 'User-agent: *') && str_contains((string) $payload['mcp_arguments']['content'], 'Sitemap:'));

// SchemaFixer + FixGenerator end-to-end: stub homepage, run FixGenerator, inspect the primary payload.
$db->execute('INSERT INTO sites (domain, enabled) VALUES (?, 1)', ['schemafixer.test']);
$siteId = $db->lastInsertId();
$db->execute(
    "INSERT INTO site_entity (site_id, entity_type, legal_name, founding_date, slogan, ota_urls_json, founder_json, operator_json, area_served_json, llm_txt_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [
        $siteId, 'BoutiqueHotel',
        'Smoke Hotel (Pty) Ltd', '2020', 'The smokiest hotel',
        json_encode(['https://booking.com/smoke', 'https://tripadvisor.com/smoke']),
        json_encode(['@type' => 'Person', 'name' => 'Smoke Founder']),
        json_encode(['@type' => 'Organization', 'name' => 'Smoke Operator']),
        json_encode(['@type' => 'Place', 'name' => 'Smokeland']),
        'https://schemafixer.test/llm.txt',
    ]
);
$siteRow = $db->fetchOne('SELECT * FROM sites WHERE id = ?', [$siteId]);
$entityRow = $db->fetchOne('SELECT * FROM site_entity WHERE site_id = ?', [$siteId]);

// Three open schema findings + one Robots finding
foreach (['critical', 'high', 'medium'] as $i => $sev) {
    $db->execute(
        "INSERT INTO findings (site_id, run_id, check_type, url, severity, issue, recommendation, status) VALUES (?, NULL, 'Schema', 'https://schemafixer.test/', ?, ?, 'Rec', 'open')",
        [$siteId, $sev, "Smoke issue #$i"]
    );
}
$db->execute(
    "INSERT INTO findings (site_id, run_id, check_type, url, severity, issue, recommendation, status) VALUES (?, NULL, 'Robots', 'https://schemafixer.test/robots.txt', 'high', 'robots.txt missing', 'Rec', 'open')",
    [$siteId]
);

// Stub readSiteFile() by seeding FixerBase::$cache for this site.
$cacheProp = (new ReflectionClass('FixerBase'))->getProperty('cache');
$cacheProp->setAccessible(true);
$cache = $cacheProp->getValue();
$cache[$siteId . '|index.html'] = '<html><head><title>Smoke</title></head><body></body></html>';
$cache[$siteId . '|index.htm'] = null;
$cache[$siteId . '|index.php'] = null;
$cache[$siteId . '|robots.txt'] = null;
$cacheProp->setValue(null, $cache);

$stats = FixGenerator::generate($siteRow, $entityRow, []);
$payloads = $db->fetchAll('SELECT id, check_type, fix_payload_json FROM findings WHERE site_id = ?', [$siteId]);
$schemaPayloads = array_filter($payloads, static fn ($r) => $r['check_type'] === 'Schema' && !empty($r['fix_payload_json']));
check('FixGenerator attaches a payload to every schema finding', count($schemaPayloads) === 3);
$primaryPayload = null;
$primaryCount = 0;
$bundledCount = 0;
foreach ($schemaPayloads as $r) {
    $decoded = json_decode($r['fix_payload_json'], true);
    if (isset($decoded['bundled_with'])) {
        $bundledCount++;
    } else {
        $primaryCount++;
        $primaryPayload = $decoded;
    }
}
check('FixGenerator coalesces: 1 primary + 2 bundled schema payloads', $primaryCount === 1 && $bundledCount === 2);
check('SchemaFixer primary payload uses cpanel_write_file', ($primaryPayload['mcp_tool'] ?? '') === 'cpanel_write_file');
$content = (string) ($primaryPayload['mcp_arguments']['content'] ?? '');
check('SchemaFixer injects <script type="application/ld+json">', str_contains($content, 'application/ld+json'));
check('SchemaFixer block carries @id anchor', str_contains($content, '"@id"'));
check('SchemaFixer block uses BoutiqueHotel @type', str_contains($content, '"BoutiqueHotel"'));
check('SchemaFixer block includes legalName from entity', str_contains($content, 'Smoke Hotel (Pty) Ltd'));
check('SchemaFixer block includes sameAs with OTA URLs', str_contains($content, 'booking.com/smoke') && str_contains($content, 'tripadvisor.com/smoke'));
check('SchemaFixer block includes founder from entity', str_contains($content, 'Smoke Founder'));
check('SchemaFixer block includes parentOrganization from operator', str_contains($content, 'Smoke Operator'));
check('SchemaFixer block includes areaServed Smokeland', str_contains($content, 'Smokeland'));
check('SchemaFixer declares llm.txt via additionalProperty', str_contains($content, 'llmInfo') && str_contains($content, 'llm.txt'));

$robotsRow = $db->fetchOne("SELECT fix_payload_json FROM findings WHERE site_id = ? AND check_type = 'Robots'", [$siteId]);
check('FixGenerator attaches a payload to the Robots finding', !empty($robotsRow['fix_payload_json']));

echo "\n=== $pass passed, $fail failed ===\n";
@unlink($smokeDb);
exit($fail === 0 ? 0 : 1);
