<?php
/**
 * File: scripts/smoke.php
 * Description: Local smoke test for Week-1 spine. Creates fresh DB, runs Orchestrator::tick() in normal/kill/cost-exceeded/dry-run modes, exercises MCPClient against a stub. Not deployed to cPanel.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 10:55 SAST
 * Modified: 2026-05-14 10:55 SAST
 * Changes:
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
    'CREATE TABLE sites (id INTEGER PRIMARY KEY, domain TEXT UNIQUE NOT NULL, gsc_property TEXT, sheet_id TEXT, notify_email TEXT, mcp_bridge_url TEXT, mcp_bridge_key TEXT, enabled INTEGER DEFAULT 1, auto_apply_threshold TEXT DEFAULT \'manual\', created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE runs (id INTEGER PRIMARY KEY, job_id INTEGER, site_id INTEGER, started_at TEXT DEFAULT CURRENT_TIMESTAMP, ended_at TEXT, status TEXT, rows_processed INTEGER DEFAULT 0, rows_failed INTEGER DEFAULT 0, cost_usd REAL DEFAULT 0, report_path TEXT, error_message TEXT)',
    'CREATE TABLE tasks (id INTEGER PRIMARY KEY, run_id INTEGER, task_type TEXT, payload_json TEXT, status TEXT, result_json TEXT, cost_usd REAL DEFAULT 0, duration_ms INTEGER, attempt INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE guardrails (id INTEGER PRIMARY KEY, date TEXT UNIQUE, cost_ceiling_usd REAL DEFAULT 5.00, cost_used_usd REAL DEFAULT 0, kill_switch INTEGER DEFAULT 0, dry_run INTEGER DEFAULT 0)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, role TEXT DEFAULT \'admin\', created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE findings (id INTEGER PRIMARY KEY, site_id INTEGER, run_id INTEGER, check_type TEXT, url TEXT, severity TEXT, issue TEXT, recommendation TEXT NOT NULL, fix_payload_json TEXT, fix_target_path TEXT, fix_estimated_seconds INTEGER, status TEXT DEFAULT \'open\', first_seen TEXT DEFAULT CURRENT_TIMESTAMP, last_seen TEXT, approved_at TEXT, approved_by INTEGER, applied_at TEXT, verified_at TEXT)',
    'CREATE TABLE fix_applications (id INTEGER PRIMARY KEY, finding_id INTEGER, applied_at TEXT DEFAULT CURRENT_TIMESTAMP, mcp_bridge TEXT, mcp_tool TEXT, request_payload_json TEXT, response_payload_json TEXT, status TEXT, error_message TEXT)',
    'CREATE TABLE verifications (id INTEGER PRIMARY KEY, finding_id INTEGER, scheduled_for TEXT, checked_at TEXT, result TEXT, evidence_json TEXT, notes TEXT)',
    'CREATE TABLE audit_log (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, detail TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->pdo()->exec($sql);
}
$tables = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
check('schema: 10 tables created', count($tables) === 10, 'got ' . count($tables));

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

echo "\n=== $pass passed, $fail failed ===\n";
@unlink($smokeDb);
exit($fail === 0 ? 0 : 1);
