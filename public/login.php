<?php
/**
 * File: public/login.php
 * Description: Admin login. Email + password, bcrypt verify, session cookie. Logout via ?logout=1.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 10:15 SAST
 * Modified: 2026-05-14 10:15 SAST
 * Changes:
 *   1.0.0 (2026-05-14 10:15) — initial creation
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!pulse_installed()) {
    header('Location: /install.php');
    exit;
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: /login.php');
    exit;
}

if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'], $csrf)) {
        $error = 'Session expired. Please try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Email and password required.';
    } else {
        $db = DB::getInstance();
        $user = $db->fetchOne('SELECT id, email, password_hash, role FROM users WHERE email = ?', [$email]);
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $db->execute(
                'INSERT INTO audit_log (user_id, action, detail) VALUES (?, ?, ?)',
                [$user['id'], 'login', 'ok']
            );
            header('Location: /dashboard.php');
            exit;
        }
        $error = 'Invalid credentials.';
        Logger::warn('login failed', ['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        $db->execute(
            'INSERT INTO audit_log (user_id, action, detail) VALUES (NULL, ?, ?)',
            ['login_failed', 'email=' . $email]
        );
    }
}

$csrfTok = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
$emailEsc = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$errorEsc = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — SWO Pulse</title>
<style>
  body { margin:0; background:#FFF8F0; font-family:'Poppins',-apple-system,'Segoe UI',Helvetica,Arial,sans-serif; color:#2A2A2A; }
  .wrap { max-width:420px; margin:80px auto; padding:0 16px; }
  .card { background:#FFFFFF; border:1px solid #EEE3D0; border-radius:6px; padding:32px; }
  .brand { font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#D4A24C; font-weight:600; }
  h1 { margin:8px 0 24px 0; font-size:22px; font-weight:600; border-bottom:3px solid #D4A24C; padding-bottom:16px; }
  label { display:block; font-size:13px; margin:14px 0 4px 0; color:#6B6B6B; }
  input[type=email], input[type=password] { width:100%; padding:10px 12px; font-size:15px; border:1px solid #D9D9D9; border-radius:4px; box-sizing:border-box; font-family:inherit; }
  input:focus { outline:none; border-color:#D4A24C; }
  button { margin-top:20px; width:100%; padding:12px; background:#D4A24C; color:#FFFFFF; border:0; border-radius:4px; font-size:15px; font-weight:600; cursor:pointer; font-family:inherit; }
  button:hover { background:#B8893E; }
  .error { margin-top:16px; padding:10px 12px; background:#FBEAEA; border-left:3px solid #B33A3A; color:#7A2424; font-size:13px; }
  .footer { margin-top:24px; font-size:12px; color:#6B6B6B; text-align:center; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="brand">SWO Pulse</div>
    <h1>Sign in</h1>
    <?php if ($error !== ''): ?><div class="error"><?= $errorEsc ?></div><?php endif; ?>
    <form method="post" action="/login.php" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= $csrfTok ?>">
      <label for="email">Email</label>
      <input type="email" name="email" id="email" value="<?= $emailEsc ?>" required autofocus>
      <label for="password">Password</label>
      <input type="password" name="password" id="password" required>
      <button type="submit">Sign in</button>
    </form>
    <div class="footer">Pulse v1 &middot; pulse.safariweb.online</div>
  </div>
</div>
</body>
</html>
