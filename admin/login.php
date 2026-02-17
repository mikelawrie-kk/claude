<?php
/**
 * Filename: login.php
 * Description: Admin login page with authentication and logout handler
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/logger.php';

session_start();

// ---------------------------------------------------------------------------
// Logout handler
// ---------------------------------------------------------------------------
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

// ---------------------------------------------------------------------------
// If already logged in, redirect to dashboard
// ---------------------------------------------------------------------------
if (isset($_SESSION['admin_user_id'])) {
    header('Location: index.php');
    exit;
}

// ---------------------------------------------------------------------------
// Handle POST login
// ---------------------------------------------------------------------------
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        try {
            $user = Database::fetch(
                "SELECT `id`, `email`, `password_hash`, `name`, `role` FROM `admin_users` WHERE `email` = ?",
                [$email]
            );

            if ($user && password_verify($password, $user['password_hash'])) {
                // Authentication success
                session_regenerate_id(true);
                $_SESSION['admin_user_id'] = (int) $user['id'];
                $_SESSION['admin_email']   = $user['email'];
                $_SESSION['admin_name']    = $user['name'];
                $_SESSION['admin_role']    = $user['role'];

                // Update last_login timestamp
                Database::update('admin_users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

                Logger::info('admin', 'Admin login successful', ['metadata' => ['email' => $user['email']]]);

                header('Location: index.php');
                exit;
            } else {
                $error = 'Invalid email or password.';
                Logger::error('admin', 'Failed admin login attempt', ['metadata' => ['email' => $email]]);
            }
        } catch (\Throwable $e) {
            $error = 'A system error occurred. Please try again.';
            Logger::error('admin', 'Login exception: ' . $e->getMessage());
        }
    }
}

$emailValue = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Safari Traveller Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --gold: #D4A84B;
            --gold-hover: #B8912E;
            --gold-bg: rgba(212, 168, 75, 0.1);
            --cream: #FAFAF5;
            --dark: #2C2C2C;
            --grey: #6B6B6B;
            --light-grey: #E0DDD5;
            --success: #2E7D4F;
            --error: #C0392B;
            --error-bg: #FDEDEB;
            --white: #FFFFFF;
            --shadow: 0 8px 24px rgba(0, 0, 0, 0.10);
            --radius: 8px;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--dark);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
        }

        .login-logo {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-logo h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--white);
        }

        .login-logo h1 span {
            color: var(--gold);
        }

        .login-logo p {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 300;
            margin-top: 0.25rem;
        }

        .login-card {
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow);
            padding: 2.5rem 2rem;
        }

        .login-card h2 {
            font-size: 1.15rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--dark);
            text-align: center;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.4rem;
            color: var(--dark);
        }

        .form-group input {
            width: 100%;
            padding: 0.7rem 0.9rem;
            border: 1px solid var(--light-grey);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.9rem;
            color: var(--dark);
            background: var(--cream);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px var(--gold-bg);
        }

        .btn-login {
            display: block;
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            background-color: var(--gold);
            color: var(--white);
            transition: background-color 0.2s, transform 0.1s;
            margin-top: 0.5rem;
        }

        .btn-login:hover {
            background-color: var(--gold-hover);
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        .alert-error {
            background: var(--error-bg);
            color: var(--error);
            border: 1px solid rgba(192, 57, 43, 0.2);
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
            font-size: 0.85rem;
            margin-bottom: 1.25rem;
            text-align: center;
        }

        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
        }

        .login-footer a {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.4);
            text-decoration: none;
            transition: color 0.2s;
        }

        .login-footer a:hover {
            color: var(--gold);
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-logo">
            <h1>Safari <span>Traveller</span></h1>
            <p>Admin Panel</p>
        </div>

        <div class="login-card">
            <h2>Sign In</h2>

            <?php if ($error !== ''): ?>
                <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="login.php" autocomplete="on">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= $emailValue ?>" required autofocus autocomplete="email" placeholder="admin@example.com">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                </div>

                <button type="submit" class="btn-login">Sign In</button>
            </form>
        </div>

        <div class="login-footer">
            <a href="/public/">Back to Safari Traveller</a>
        </div>
    </div>
</body>
</html>
