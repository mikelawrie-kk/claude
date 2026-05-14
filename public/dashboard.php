<?php
/**
 * File: public/dashboard.php
 * Description: Week-1 placeholder dashboard. Shows today's cost vs ceiling, kill switch toggle, last run timestamp. Full admin UI lands in Week 4.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 10:22 SAST
 * Modified: 2026-05-14 10:22 SAST
 * Changes:
 *   1.0.0 (2026-05-14 10:22) — initial creation
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!pulse_installed()) {
    header('Location: /install.php');
    exit;
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$db = DB::getInstance();
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'], $csrf)) {
        $flash = 'Session expired. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'toggle_kill_switch') {
            $current = (int) Guardrails::today()['kill_switch'] === 1;
            Guardrails::setKillSwitch(!$current);
            $db->execute(
                'INSERT INTO audit_log (user_id, action, detail) VALUES (?, ?, ?)',
                [$_SESSION['user_id'], 'kill_switch_toggle', $current ? 'off' : 'on']
            );
            $flash = 'Kill switch ' . ($current ? 'disabled' : 'enabled') . '.';
        } elseif ($action === 'toggle_dry_run') {
            $current = (int) Guardrails::today()['dry_run'] === 1;
            Guardrails::setDryRun(!$current);
            $db->execute(
                'INSERT INTO audit_log (user_id, action, detail) VALUES (?, ?, ?)',
                [$_SESSION['user_id'], 'dry_run_toggle', $current ? 'off' : 'on']
            );
            $flash = 'Dry-run mode ' . ($current ? 'disabled' : 'enabled') . '.';
        }
    }
}

$g = Guardrails::today();
$lastRun = $db->fetchOne('SELECT id, started_at, ended_at, status, job_id FROM runs ORDER BY id DESC LIMIT 1');
$sitesCount = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM sites WHERE enabled = 1')['c'] ?? 0);
$jobsCount = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM jobs WHERE enabled = 1')['c'] ?? 0);
$openFindings = (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM findings WHERE status = 'open'")['c'] ?? 0);
$pendingApprovals = (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM findings WHERE status = 'open' AND fix_payload_json IS NOT NULL")['c'] ?? 0);

$pct = (float) $g['cost_ceiling_usd'] > 0
    ? min(100, round(((float) $g['cost_used_usd'] / (float) $g['cost_ceiling_usd']) * 100))
    : 0;
$killOn = (int) $g['kill_switch'] === 1;
$dryOn = (int) $g['dry_run'] === 1;

$csrfTok = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
$flashEsc = htmlspecialchars($flash, ENT_QUOTES, 'UTF-8');
$userEsc = htmlspecialchars((string) ($_SESSION['user_email'] ?? ''), ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — SWO Pulse</title>
<style>
  body { margin:0; background:#FFF8F0; font-family:'Poppins',-apple-system,'Segoe UI',Helvetica,Arial,sans-serif; color:#2A2A2A; }
  .topbar { background:#FFFFFF; border-bottom:3px solid #D4A24C; padding:16px 32px; display:flex; align-items:center; justify-content:space-between; }
  .brand { font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#D4A24C; font-weight:600; }
  .topbar h1 { margin:0; font-size:20px; font-weight:600; }
  .topbar a { color:#6B6B6B; font-size:13px; text-decoration:none; margin-left:16px; }
  .topbar a:hover { color:#D4A24C; }
  .wrap { max-width:1000px; margin:32px auto; padding:0 32px; }
  .flash { margin-bottom:24px; padding:12px 16px; background:#FFF1D6; border-left:3px solid #D4A24C; color:#7A5A1F; font-size:14px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; margin-bottom:24px; }
  .card { background:#FFFFFF; border:1px solid #EEE3D0; border-radius:6px; padding:20px; }
  .card .lbl { font-size:11px; letter-spacing:1.5px; text-transform:uppercase; color:#6B6B6B; font-weight:600; }
  .card .val { font-size:26px; font-weight:600; margin-top:8px; color:#2A2A2A; }
  .card .sub { font-size:12px; color:#6B6B6B; margin-top:6px; }
  .bar { margin-top:10px; height:6px; background:#F0E4CC; border-radius:3px; overflow:hidden; }
  .bar > div { height:100%; background:#D4A24C; }
  .row { display:flex; gap:12px; align-items:center; }
  form.inline { display:inline; }
  .btn { padding:8px 14px; border:1px solid #D4A24C; background:#FFFFFF; color:#D4A24C; border-radius:4px; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
  .btn.on { background:#D4A24C; color:#FFFFFF; }
  .btn.danger { border-color:#B33A3A; color:#B33A3A; }
  .btn.danger.on { background:#B33A3A; color:#FFFFFF; }
  .panel { background:#FFFFFF; border:1px solid #EEE3D0; border-radius:6px; padding:20px; margin-bottom:16px; }
  .panel h2 { margin:0 0 12px 0; font-size:14px; letter-spacing:1.5px; text-transform:uppercase; color:#6B6B6B; font-weight:600; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  table td { padding:8px 0; border-bottom:1px solid #F5EBD6; }
  table td:first-child { color:#6B6B6B; width:200px; }
  .placeholder { color:#9A9A9A; font-style:italic; }
</style>
</head>
<body>
<div class="topbar">
  <div>
    <div class="brand">SWO Pulse</div>
    <h1>Dashboard</h1>
  </div>
  <div>
    <span style="color:#6B6B6B;font-size:13px;"><?= $userEsc ?></span>
    <a href="/login.php?logout=1">Sign out</a>
  </div>
</div>

<div class="wrap">
  <?php if ($flash !== ''): ?><div class="flash"><?= $flashEsc ?></div><?php endif; ?>

  <div class="grid">
    <div class="card">
      <div class="lbl">Today cost</div>
      <div class="val">$<?= number_format((float) $g['cost_used_usd'], 2) ?></div>
      <div class="sub">of $<?= number_format((float) $g['cost_ceiling_usd'], 2) ?> ceiling (<?= $pct ?>%)</div>
      <div class="bar"><div style="width:<?= $pct ?>%"></div></div>
    </div>
    <div class="card">
      <div class="lbl">Sites enabled</div>
      <div class="val"><?= $sitesCount ?></div>
      <div class="sub">jobs enabled: <?= $jobsCount ?></div>
    </div>
    <div class="card">
      <div class="lbl">Open findings</div>
      <div class="val"><?= $openFindings ?></div>
      <div class="sub">pending approval: <?= $pendingApprovals ?></div>
    </div>
    <div class="card">
      <div class="lbl">Last run</div>
      <div class="val" style="font-size:16px;">
        <?php if ($lastRun): ?>
          <?= htmlspecialchars($lastRun['started_at'], ENT_QUOTES, 'UTF-8') ?>
        <?php else: ?>
          <span class="placeholder">none yet</span>
        <?php endif; ?>
      </div>
      <div class="sub">
        <?php if ($lastRun): ?>
          status: <?= htmlspecialchars($lastRun['status'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="panel">
    <h2>Controls</h2>
    <div class="row">
      <form class="inline" method="post" action="/dashboard.php">
        <input type="hidden" name="csrf" value="<?= $csrfTok ?>">
        <input type="hidden" name="action" value="toggle_kill_switch">
        <button type="submit" class="btn danger <?= $killOn ? 'on' : '' ?>">
          Kill switch: <?= $killOn ? 'ACTIVE' : 'off' ?>
        </button>
      </form>
      <form class="inline" method="post" action="/dashboard.php">
        <input type="hidden" name="csrf" value="<?= $csrfTok ?>">
        <input type="hidden" name="action" value="toggle_dry_run">
        <button type="submit" class="btn <?= $dryOn ? 'on' : '' ?>">
          Dry-run: <?= $dryOn ? 'ON' : 'off' ?>
        </button>
      </form>
    </div>
    <p style="color:#6B6B6B;font-size:12px;margin-top:14px;">
      Kill switch stops all dispatch immediately. Dry-run logs intended actions but calls no external APIs and writes no findings.
    </p>
  </div>

  <div class="panel">
    <h2>Placeholder — full admin UI lands in Week 4</h2>
    <table>
      <tr><td>Approvals queue</td><td><span class="placeholder">Week 4</span></td></tr>
      <tr><td>Site detail</td><td><span class="placeholder">Week 4</span></td></tr>
      <tr><td>Audit log viewer</td><td><span class="placeholder">Week 4</span></td></tr>
      <tr><td>Weekly digest preview</td><td><span class="placeholder">Week 4</span></td></tr>
    </table>
  </div>
</div>
</body>
</html>
