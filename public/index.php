<?php
/**
 * File: public/index.php
 * Description: Public entry. Redirects to install if Pulse is not yet installed, login if no session, dashboard otherwise.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 10:12 SAST
 * Modified: 2026-05-14 10:12 SAST
 * Changes:
 *   1.0.0 (2026-05-14 10:12) — initial creation
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!pulse_installed()) {
    header('Location: /install.php');
    exit;
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
} else {
    header('Location: /login.php');
}
exit;
