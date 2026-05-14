<?php
/**
 * File: install.php
 * Description: One-file Pulse installer. Idempotent. Creates all 10 tables, prompts admin + first site + mail, writes app/config/pulse.config.php, and gates completion on §A1 (first site live, test tick written, test email sent). Renames itself to install.php.done on success.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 10:30 SAST
 * Modified: 2026-05-14 10:30 SAST
 * Changes:
 *   1.0.0 (2026-05-14 10:30) — initial creation
 */

date_default_timezone_set('Africa/Johannesburg');
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PULSE_ROOT', __DIR__);
define('PULSE_DATA', PULSE_ROOT . '/data');
define('PULSE_LOGS', PULSE_DATA . '/logs');
define('PULSE_CONFIG_PATH', PULSE_ROOT . '/app/config/pulse.config.php');
define('PULSE_DB_PATH', PULSE_DATA . '/pulse.db');

foreach ([PULSE_DATA, PULSE_LOGS, dirname(PULSE_CONFIG_PATH)] as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
}

require_once __DIR__ . '/app/core/Logger.php';
require_once __DIR__ . '/app/core/DB.php';
require_once __DIR__ . '/app/core/Guardrails.php';
require_once __DIR__ . '/app/core/APIClient.php';
require_once __DIR__ . '/app/core/Reporter.php';
require_once __DIR__ . '/app/core/MCPClient.php';
require_once __DIR__ . '/app/core/Dispatcher.php';
require_once __DIR__ . '/app/core/ApplyRouter.php';
require_once __DIR__ . '/app/core/Orchestrator.php';

Logger::configure(PULSE_LOGS . '/pulse.log');
DB::configure(PULSE_DB_PATH);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

// ---- Status mode if already installed ------------------------------------
$alreadyInstalled = is_file(PULSE_CONFIG_PATH) && pulse_already_installed();
if ($alreadyInstalled && !isset($_GET['force_status'])) {
    render_status_page();
    exit;
}

// ---- Ensure schema exists (idempotent) ----------------------------------
try {
    ensure_schema();
    seed_today_guardrail();
} catch (Throwable $e) {
    render_error('Schema creation failed: ' . $e->getMessage());
    exit;
}

$step = (int) ($_GET['step'] ?? ($_POST['step'] ?? 1));
$errors = [];

// ---- Step handlers -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['install_csrf']) || !hash_equals($_SESSION['install_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Session expired. Refresh and try again.';
    } else {
        if ($step === 1) {
            $errors = handle_step1($_POST);
            if (!$errors) {
                header('Location: /install.php?step=2');
                exit;
            }
        } elseif ($step === 2) {
            $errors = handle_step2($_POST);
            if (!$errors) {
                header('Location: /install.php?step=3');
                exit;
            }
        } elseif ($step === 3) {
            $errors = run_gate();
            if (!$errors) {
                finalize_install();
                header('Location: /install.php?step=4');
                exit;
            }
        }
    }
}

if (!isset($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
}

if ($step >= 2 && empty($_SESSION['install']['admin_email'])) {
    header('Location: /install.php?step=1');
    exit;
}
if ($step >= 3 && empty($_SESSION['install']['site_domain'])) {
    header('Location: /install.php?step=2');
    exit;
}

render_install_page($step, $errors);
exit;

// ==========================================================================
// Functions
// ==========================================================================

function ensure_schema(): void
{
    $db = DB::getInstance();
    $ddl = [
        'CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY,
            name TEXT UNIQUE NOT NULL,
            module TEXT NOT NULL,
            cron_schedule TEXT,
            enabled INTEGER DEFAULT 1,
            config_json TEXT,
            last_run_at TEXT,
            next_run_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY,
            domain TEXT UNIQUE NOT NULL,
            gsc_property TEXT,
            sheet_id TEXT,
            notify_email TEXT,
            mcp_bridge_url TEXT,
            mcp_bridge_key TEXT,
            enabled INTEGER DEFAULT 1,
            auto_apply_threshold TEXT DEFAULT \'manual\',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE IF NOT EXISTS runs (
            id INTEGER PRIMARY KEY,
            job_id INTEGER REFERENCES jobs(id),
            site_id INTEGER REFERENCES sites(id),
            started_at TEXT DEFAULT CURRENT_TIMESTAMP,
            ended_at TEXT,
            status TEXT,
            rows_processed INTEGER DEFAULT 0,
            rows_failed INTEGER DEFAULT 0,
            cost_usd REAL DEFAULT 0,
            report_path TEXT,
            error_message TEXT
        )',
        'CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY,
            run_id INTEGER REFERENCES runs(id),
            task_type TEXT,
            payload_json TEXT,
            status TEXT,
            result_json TEXT,
            cost_usd REAL DEFAULT 0,
            duration_ms INTEGER,
            attempt INTEGER DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE IF NOT EXISTS guardrails (
            id INTEGER PRIMARY KEY,
            date TEXT UNIQUE,
            cost_ceiling_usd REAL DEFAULT 5.00,
            cost_used_usd REAL DEFAULT 0,
            kill_switch INTEGER DEFAULT 0,
            dry_run INTEGER DEFAULT 0
        )',
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT \'admin\',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE IF NOT EXISTS findings (
            id INTEGER PRIMARY KEY,
            site_id INTEGER REFERENCES sites(id),
            run_id INTEGER REFERENCES runs(id),
            check_type TEXT,
            url TEXT,
            severity TEXT,
            issue TEXT,
            recommendation TEXT NOT NULL,
            fix_payload_json TEXT,
            fix_target_path TEXT,
            fix_estimated_seconds INTEGER,
            status TEXT DEFAULT \'open\',
            first_seen TEXT DEFAULT CURRENT_TIMESTAMP,
            last_seen TEXT,
            approved_at TEXT,
            approved_by INTEGER REFERENCES users(id),
            applied_at TEXT,
            verified_at TEXT
        )',
        'CREATE TABLE IF NOT EXISTS fix_applications (
            id INTEGER PRIMARY KEY,
            finding_id INTEGER REFERENCES findings(id),
            applied_at TEXT DEFAULT CURRENT_TIMESTAMP,
            mcp_bridge TEXT,
            mcp_tool TEXT,
            request_payload_json TEXT,
            response_payload_json TEXT,
            status TEXT,
            error_message TEXT
        )',
        'CREATE TABLE IF NOT EXISTS verifications (
            id INTEGER PRIMARY KEY,
            finding_id INTEGER REFERENCES findings(id),
            scheduled_for TEXT,
            checked_at TEXT,
            result TEXT,
            evidence_json TEXT,
            notes TEXT
        )',
        'CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY,
            user_id INTEGER REFERENCES users(id),
            action TEXT,
            detail TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )',
    ];
    foreach ($ddl as $sql) {
        $db->pdo()->exec($sql);
    }
}

function seed_today_guardrail(): void
{
    $db = DB::getInstance();
    $today = date('Y-m-d');
    $existing = $db->fetchOne('SELECT id FROM guardrails WHERE date = ?', [$today]);
    if ($existing === null) {
        $db->execute(
            'INSERT INTO guardrails (date, cost_ceiling_usd, cost_used_usd, kill_switch, dry_run) VALUES (?, ?, 0, 0, 0)',
            [$today, Guardrails::DEFAULT_CEILING_USD]
        );
    }
}

function pulse_already_installed(): bool
{
    try {
        $db = DB::getInstance();
        $t = $db->fetchOne("SELECT COUNT(*) AS c FROM sqlite_master WHERE type='table' AND name='users'");
        if ($t === null || (int) $t['c'] === 0) {
            return false;
        }
        $u = $db->fetchOne('SELECT COUNT(*) AS c FROM users');
        return $u !== null && (int) $u['c'] > 0 && is_file(PULSE_CONFIG_PATH);
    } catch (Throwable $_) {
        return false;
    }
}

function handle_step1(array $post): array
{
    $email = trim((string) ($post['admin_email'] ?? ''));
    $password = (string) ($post['admin_password'] ?? '');
    $password2 = (string) ($post['admin_password_confirm'] ?? '');
    $errors = [];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Admin email is not a valid email address.';
    }
    if (strlen($password) < 10) {
        $errors[] = 'Admin password must be at least 10 characters.';
    }
    if ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    }
    if ($errors) {
        return $errors;
    }
    $_SESSION['install']['admin_email'] = $email;
    $_SESSION['install']['admin_password_hash'] = password_hash($password, PASSWORD_BCRYPT);
    return [];
}

function handle_step2(array $post): array
{
    $errors = [];
    $domain = trim((string) ($post['site_domain'] ?? ''));
    $notify = trim((string) ($post['site_notify_email'] ?? ''));
    $bridgeUrl = trim((string) ($post['site_mcp_bridge_url'] ?? ''));
    $bridgeKey = trim((string) ($post['site_mcp_bridge_key'] ?? ''));
    $gsc = trim((string) ($post['site_gsc_property'] ?? ''));
    $mailMethod = (string) ($post['mail_method'] ?? 'php_mail');
    $mailFrom = trim((string) ($post['mail_from'] ?? ''));
    $mailFromName = trim((string) ($post['mail_from_name'] ?? 'SWO Pulse'));

    if ($domain === '') {
        $errors[] = 'Site domain is required.';
    } elseif (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
        $errors[] = 'Site domain looks invalid (expected like example.com).';
    }
    if (!filter_var($notify, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Site notify email is not valid.';
    }
    if ($bridgeUrl !== '' && !filter_var($bridgeUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'MCP bridge URL is not a valid URL.';
    }
    if (!filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Mail "from" address is not a valid email.';
    }

    $smtp = null;
    if ($mailMethod === 'smtp') {
        $smtp = [
            'host' => trim((string) ($post['smtp_host'] ?? '')),
            'port' => (int) ($post['smtp_port'] ?? 587),
            'username' => trim((string) ($post['smtp_username'] ?? '')),
            'password' => (string) ($post['smtp_password'] ?? ''),
            'secure' => (string) ($post['smtp_secure'] ?? 'tls'),
        ];
        if ($smtp['host'] === '') {
            $errors[] = 'SMTP host is required when using SMTP.';
        }
    }

    if ($errors) {
        return $errors;
    }

    $_SESSION['install']['site_domain'] = $domain;
    $_SESSION['install']['site_notify_email'] = $notify;
    $_SESSION['install']['site_mcp_bridge_url'] = $bridgeUrl;
    $_SESSION['install']['site_mcp_bridge_key'] = $bridgeKey;
    $_SESSION['install']['site_gsc_property'] = $gsc;
    $_SESSION['install']['mail_method'] = $mailMethod;
    $_SESSION['install']['mail_from'] = $mailFrom;
    $_SESSION['install']['mail_from_name'] = $mailFromName;
    $_SESSION['install']['smtp'] = $smtp;

    write_config();
    upsert_admin();
    upsert_first_site();
    seed_default_job();

    return [];
}

function write_config(): void
{
    $s = $_SESSION['install'];
    $cfg = [
        'app_url' => 'https://pulse.safariweb.online',
        'timezone' => 'Africa/Johannesburg',
        'db_path' => PULSE_DB_PATH,
        'admin_email' => $s['admin_email'],
        'mail_method' => $s['mail_method'],
        'mail_from' => $s['mail_from'],
        'mail_from_name' => $s['mail_from_name'],
        'smtp' => $s['smtp'],
        'installed_at' => date('Y-m-d H:i:s P'),
    ];
    $php = "<?php\n"
         . "/**\n"
         . " * File: app/config/pulse.config.php\n"
         . " * Description: Pulse runtime config — generated by install.php. Contains secrets; do not commit.\n"
         . " * Project: Pulse v1\n"
         . " * Version: 1.0.0\n"
         . " * Created: " . date('Y-m-d H:i') . " SAST\n"
         . " * Modified: " . date('Y-m-d H:i') . " SAST\n"
         . " */\n\n"
         . "return " . var_export($cfg, true) . ";\n";
    if (file_put_contents(PULSE_CONFIG_PATH, $php, LOCK_EX) === false) {
        throw new RuntimeException('Could not write ' . PULSE_CONFIG_PATH);
    }
    @chmod(PULSE_CONFIG_PATH, 0640);
}

function upsert_admin(): void
{
    $db = DB::getInstance();
    $s = $_SESSION['install'];
    $existing = $db->fetchOne('SELECT id FROM users WHERE email = ?', [$s['admin_email']]);
    if ($existing) {
        $db->execute('UPDATE users SET password_hash = ?, role = ? WHERE id = ?', [$s['admin_password_hash'], 'admin', $existing['id']]);
    } else {
        $db->execute('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)', [$s['admin_email'], $s['admin_password_hash'], 'admin']);
    }
}

function upsert_first_site(): void
{
    $db = DB::getInstance();
    $s = $_SESSION['install'];
    $existing = $db->fetchOne('SELECT id FROM sites WHERE domain = ?', [$s['site_domain']]);
    if ($existing) {
        $db->execute(
            'UPDATE sites SET notify_email = ?, mcp_bridge_url = ?, mcp_bridge_key = ?, gsc_property = ?, enabled = 1 WHERE id = ?',
            [$s['site_notify_email'], $s['site_mcp_bridge_url'], $s['site_mcp_bridge_key'], $s['site_gsc_property'], $existing['id']]
        );
    } else {
        $db->execute(
            'INSERT INTO sites (domain, gsc_property, notify_email, mcp_bridge_url, mcp_bridge_key, enabled, auto_apply_threshold) VALUES (?, ?, ?, ?, ?, 1, ?)',
            [$s['site_domain'], $s['site_gsc_property'], $s['site_notify_email'], $s['site_mcp_bridge_url'], $s['site_mcp_bridge_key'], 'manual']
        );
    }
}

function seed_default_job(): void
{
    $db = DB::getInstance();
    $existing = $db->fetchOne("SELECT id FROM jobs WHERE name = 'TechSEOMonitor'");
    if ($existing) {
        return;
    }
    $db->execute(
        'INSERT INTO jobs (name, module, cron_schedule, enabled, config_json) VALUES (?, ?, ?, 0, ?)',
        [
            'TechSEOMonitor',
            'TechSEOMonitor',
            '0 6 * * 1',
            json_encode(['note' => 'Module lands in Week 2. Disabled at install.'], JSON_UNESCAPED_SLASHES),
        ]
    );
}

function run_gate(): array
{
    $errors = [];
    $db = DB::getInstance();

    // §A1 check (a): first site exists
    $siteCount = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM sites')['c'] ?? 0);
    if ($siteCount < 1) {
        return ['Gate failed: no site rows in DB. Go back to step 2.'];
    }

    // §A1 check (b): test tick executes successfully
    try {
        $tickBefore = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM runs')['c'] ?? 0);
        $result = Orchestrator::tick();
        $tickAfter = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM runs')['c'] ?? 0);
        if ($tickAfter <= $tickBefore) {
            $errors[] = 'Gate failed: tick did not write a row to runs.';
        }
        if (($result['status'] ?? '') === 'failed') {
            $errors[] = 'Gate failed: tick returned failed status — ' . ($result['error'] ?? 'unknown');
        }
        $_SESSION['install']['gate_tick_result'] = $result;
    } catch (Throwable $e) {
        $errors[] = 'Gate failed (tick): ' . $e->getMessage();
    }

    // §A1 check (c): test email delivered
    try {
        $admin = $_SESSION['install']['admin_email'];
        $intro = 'Pulse is installed on pulse.safariweb.online. This is the install-time test email.';
        $body = '<p>If you received this, the §A1 install gate passed:</p>'
              . '<ul>'
              . '<li>First site added to <code>sites</code> table</li>'
              . '<li>Test cron tick wrote a row to <code>runs</code></li>'
              . '<li>This email was delivered through your configured mail method</li>'
              . '</ul>'
              . '<p>Next: log in at <strong>pulse.safariweb.online/login.php</strong> and add your weekly cron schedule.</p>';
        $html = Reporter::template('Install complete', $intro, $body, 'Sent at install time.');
        $ok = Reporter::send($admin, '[Pulse] Install test email', $html);
        if (!$ok) {
            $errors[] = 'Gate failed: test email was not sent. Check mail config in app/config/pulse.config.php.';
        }
        $_SESSION['install']['gate_email_ok'] = $ok;
    } catch (Throwable $e) {
        $errors[] = 'Gate failed (email): ' . $e->getMessage();
    }

    return $errors;
}

function finalize_install(): void
{
    $src = PULSE_ROOT . '/install.php';
    $dest = PULSE_ROOT . '/install.php.done';
    if (is_file($src)) {
        @rename($src, $dest);
    }
    Logger::info('install complete', [
        'admin' => $_SESSION['install']['admin_email'] ?? '',
        'site' => $_SESSION['install']['site_domain'] ?? '',
    ]);
}

// ==========================================================================
// Rendering
// ==========================================================================

function render_status_page(): void
{
    $db = DB::getInstance();
    $sites = $db->fetchAll('SELECT id, domain, enabled FROM sites ORDER BY id ASC');
    $userCount = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    $g = Guardrails::today();
    echo install_page_header('Pulse is already installed');
    echo '<div class="card"><h2>Status</h2>';
    echo '<table>';
    echo '<tr><td>Admin users</td><td><strong>' . $userCount . '</strong></td></tr>';
    echo '<tr><td>Sites</td><td><strong>' . count($sites) . '</strong></td></tr>';
    echo '<tr><td>Today cost ceiling</td><td>$' . number_format((float) $g['cost_ceiling_usd'], 2) . '</td></tr>';
    echo '<tr><td>Kill switch</td><td>' . ((int) $g['kill_switch'] === 1 ? 'ACTIVE' : 'off') . '</td></tr>';
    echo '</table>';
    echo '<p style="margin-top:20px;">Installer is locked. To reinstall, delete <code>app/config/pulse.config.php</code> and <code>data/pulse.db</code>.</p>';
    echo '<p><a class="btn" href="/login.php">Go to login</a></p>';
    echo '</div>';
    echo install_page_footer();
}

function render_error(string $message): void
{
    echo install_page_header('Installer error');
    echo '<div class="card"><div class="error">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div></div>';
    echo install_page_footer();
}

function render_install_page(int $step, array $errors): void
{
    $csrf = htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8');
    $s = $_SESSION['install'] ?? [];
    echo install_page_header('Install Pulse v1');
    echo render_steps_nav($step);

    if ($errors) {
        echo '<div class="card"><div class="error"><strong>Please fix:</strong><ul>';
        foreach ($errors as $e) {
            echo '<li>' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul></div></div>';
    }

    if ($step === 1) {
        echo render_step1($csrf, $s);
    } elseif ($step === 2) {
        echo render_step2($csrf, $s);
    } elseif ($step === 3) {
        echo render_step3($csrf, $s);
    } elseif ($step === 4) {
        echo render_step4();
    }
    echo install_page_footer();
}

function render_steps_nav(int $step): string
{
    $labels = [1 => 'Admin user', 2 => 'First site + mail', 3 => '§A1 gate', 4 => 'Done'];
    $html = '<div class="steps">';
    foreach ($labels as $n => $label) {
        $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
        $html .= '<div class="step ' . $cls . '"><span class="n">' . $n . '</span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return $html . '</div>';
}

function render_step1(string $csrf, array $s): string
{
    $email = htmlspecialchars((string) ($s['admin_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    return <<<HTML
<div class="card">
  <h2>Step 1 — Admin user</h2>
  <p class="muted">This is the account you'll use to log into the admin dashboard at <code>/login.php</code>. Password is bcrypt-hashed.</p>
  <form method="post" action="/install.php?step=1">
    <input type="hidden" name="csrf" value="{$csrf}">
    <input type="hidden" name="step" value="1">
    <label>Admin email</label>
    <input type="email" name="admin_email" required value="{$email}">
    <label>Password (min 10 characters)</label>
    <input type="password" name="admin_password" required minlength="10">
    <label>Confirm password</label>
    <input type="password" name="admin_password_confirm" required minlength="10">
    <button type="submit">Continue &rarr;</button>
  </form>
</div>
HTML;
}

function render_step2(string $csrf, array $s): string
{
    $domain = htmlspecialchars((string) ($s['site_domain'] ?? 'aerotel.co.za'), ENT_QUOTES, 'UTF-8');
    $notify = htmlspecialchars((string) ($s['site_notify_email'] ?? ($s['admin_email'] ?? '')), ENT_QUOTES, 'UTF-8');
    $bridgeUrl = htmlspecialchars((string) ($s['site_mcp_bridge_url'] ?? 'https://aerotel.co.za/mcp/'), ENT_QUOTES, 'UTF-8');
    $bridgeKey = htmlspecialchars((string) ($s['site_mcp_bridge_key'] ?? ''), ENT_QUOTES, 'UTF-8');
    $gsc = htmlspecialchars((string) ($s['site_gsc_property'] ?? ''), ENT_QUOTES, 'UTF-8');
    $mailFrom = htmlspecialchars((string) ($s['mail_from'] ?? 'pulse@pulse.safariweb.online'), ENT_QUOTES, 'UTF-8');
    $mailFromName = htmlspecialchars((string) ($s['mail_from_name'] ?? 'SWO Pulse'), ENT_QUOTES, 'UTF-8');
    return <<<HTML
<div class="card">
  <h2>Step 2 — First site &amp; mail</h2>
  <p class="muted">Pulse v1 ships with one agent (Tech SEO Monitor + Fixer). Add the test site here. Adding more sites later is just an INSERT.</p>
  <form method="post" action="/install.php?step=2">
    <input type="hidden" name="csrf" value="{$csrf}">
    <input type="hidden" name="step" value="2">

    <h3 class="section">Site</h3>
    <label>Domain (no protocol)</label>
    <input type="text" name="site_domain" required value="{$domain}" placeholder="aerotel.co.za">
    <label>Notify email (weekly digest recipient)</label>
    <input type="email" name="site_notify_email" required value="{$notify}">
    <label>MCP bridge URL (for applying fixes)</label>
    <input type="url" name="site_mcp_bridge_url" value="{$bridgeUrl}" placeholder="https://aerotel.co.za/mcp/">
    <label>MCP bridge key (the <code>?key=</code> value)</label>
    <input type="text" name="site_mcp_bridge_key" value="{$bridgeKey}">
    <label>GSC property (optional)</label>
    <input type="text" name="site_gsc_property" value="{$gsc}" placeholder="sc-domain:aerotel.co.za">

    <h3 class="section">Mail</h3>
    <label>From address</label>
    <input type="email" name="mail_from" required value="{$mailFrom}">
    <label>From name</label>
    <input type="text" name="mail_from_name" required value="{$mailFromName}">
    <label>Method</label>
    <select name="mail_method" onchange="document.getElementById('smtp').style.display=this.value==='smtp'?'block':'none';">
      <option value="php_mail">PHP mail() — simplest, uses cPanel local sendmail</option>
      <option value="smtp">SMTP — explicit server</option>
    </select>
    <div id="smtp" style="display:none;">
      <label>SMTP host</label>
      <input type="text" name="smtp_host" placeholder="smtp.example.com">
      <label>SMTP port</label>
      <input type="number" name="smtp_port" value="587">
      <label>SMTP username</label>
      <input type="text" name="smtp_username">
      <label>SMTP password</label>
      <input type="password" name="smtp_password">
      <label>Security</label>
      <select name="smtp_secure">
        <option value="tls">STARTTLS</option>
        <option value="ssl">SSL</option>
        <option value="none">None</option>
      </select>
    </div>

    <button type="submit">Save and run §A1 gate &rarr;</button>
  </form>
</div>
HTML;
}

function render_step3(string $csrf, array $s): string
{
    $admin = htmlspecialchars((string) ($s['admin_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $domain = htmlspecialchars((string) ($s['site_domain'] ?? ''), ENT_QUOTES, 'UTF-8');
    return <<<HTML
<div class="card">
  <h2>Step 3 — §A1 install gate</h2>
  <p class="muted">Pulse will not finish installing until all three checks below pass. This enforces anti-orphan rule A1: no half-installed Pulse.</p>
  <ol class="checklist">
    <li>First site row exists in <code>sites</code> table &mdash; <strong>{$domain}</strong></li>
    <li>Test cron tick (<code>Orchestrator::tick()</code>) writes a row to <code>runs</code></li>
    <li>Test email is delivered to <strong>{$admin}</strong></li>
  </ol>
  <form method="post" action="/install.php?step=3">
    <input type="hidden" name="csrf" value="{$csrf}">
    <input type="hidden" name="step" value="3">
    <button type="submit">Run gate now</button>
  </form>
</div>
HTML;
}

function render_step4(): string
{
    return <<<HTML
<div class="card">
  <h2>Step 4 — Install complete</h2>
  <p>The §A1 gate passed. <code>install.php</code> has been renamed to <code>install.php.done</code> and will not run again.</p>
  <p><strong>Next steps:</strong></p>
  <ul>
    <li>Configure cron in cPanel:
      <pre>*/5 * * * * php /home/USER/pulse.safariweb.online/cron/tick.php &gt;&gt; /home/USER/pulse.safariweb.online/data/logs/cron.log 2&gt;&amp;1
1 0 * * * php /home/USER/pulse.safariweb.online/cron/heartbeat.php</pre>
    </li>
    <li>Log in at <a href="/login.php">/login.php</a> and verify dashboard placeholder loads.</li>
    <li>Week 2 will land the seven detection check modules.</li>
  </ul>
  <p><a class="btn" href="/login.php">Go to login</a></p>
</div>
HTML;
}

function install_page_header(string $title): string
{
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$titleEsc} — SWO Pulse</title>
<style>
  body { margin:0; background:#FFF8F0; font-family:'Poppins',-apple-system,'Segoe UI',Helvetica,Arial,sans-serif; color:#2A2A2A; }
  .wrap { max-width:680px; margin:40px auto; padding:0 16px; }
  .brand { font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#D4A24C; font-weight:600; }
  h1 { margin:8px 0 24px 0; font-size:24px; font-weight:600; border-bottom:3px solid #D4A24C; padding-bottom:16px; }
  h2 { margin:0 0 12px 0; font-size:18px; font-weight:600; }
  h3.section { margin:24px 0 8px 0; font-size:13px; letter-spacing:1.5px; text-transform:uppercase; color:#6B6B6B; font-weight:600; }
  .card { background:#FFFFFF; border:1px solid #EEE3D0; border-radius:6px; padding:28px; margin-bottom:20px; }
  .muted { color:#6B6B6B; font-size:13px; }
  label { display:block; font-size:13px; margin:14px 0 4px 0; color:#6B6B6B; }
  input[type=text], input[type=email], input[type=password], input[type=url], input[type=number], select { width:100%; padding:10px 12px; font-size:14px; border:1px solid #D9D9D9; border-radius:4px; box-sizing:border-box; font-family:inherit; }
  input:focus, select:focus { outline:none; border-color:#D4A24C; }
  button, .btn { display:inline-block; margin-top:20px; padding:11px 22px; background:#D4A24C; color:#FFFFFF; border:0; border-radius:4px; font-size:14px; font-weight:600; cursor:pointer; text-decoration:none; font-family:inherit; }
  button:hover, .btn:hover { background:#B8893E; }
  .error { padding:12px 16px; background:#FBEAEA; border-left:3px solid #B33A3A; color:#7A2424; font-size:13px; }
  .error ul { margin:6px 0 0 18px; padding:0; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  table td { padding:8px 0; border-bottom:1px solid #F5EBD6; }
  table td:first-child { color:#6B6B6B; }
  pre { background:#F5EBD6; padding:12px; border-radius:4px; font-size:12px; overflow-x:auto; }
  code { background:#F5EBD6; padding:2px 6px; border-radius:3px; font-size:12px; }
  .steps { display:flex; gap:8px; margin-bottom:24px; flex-wrap:wrap; }
  .step { background:#FFFFFF; border:1px solid #EEE3D0; padding:8px 14px; border-radius:4px; font-size:13px; color:#9A9A9A; }
  .step.active { border-color:#D4A24C; color:#2A2A2A; font-weight:600; }
  .step.done { color:#6B6B6B; }
  .step.done .n { background:#D4A24C; color:#FFFFFF; }
  .step .n { display:inline-block; width:18px; height:18px; line-height:18px; text-align:center; border-radius:50%; background:#EEE3D0; margin-right:6px; font-size:11px; }
  .step.active .n { background:#D4A24C; color:#FFFFFF; }
  .checklist { margin:14px 0 14px 18px; font-size:14px; line-height:1.8; }
</style>
</head><body>
<div class="wrap">
  <div class="brand">SWO Pulse</div>
  <h1>{$titleEsc}</h1>
HTML;
}

function install_page_footer(): string
{
    return '<p style="font-size:12px;color:#6B6B6B;text-align:center;margin-top:32px;">Pulse v1 &middot; install.php</p></div></body></html>';
}
