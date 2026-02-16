<?php
/**
 * Filename: install.php
 * Description: Single-file installer for Market Demand Tracker. Creates database, config file, and admin user.
 * Version: 1.0.0
 * Created: 2026-02-16 09:00:00 SAST
 * Modified: 2026-02-16 09:00:00 SAST
 */

// Prevent re-running if already installed
if (file_exists(__DIR__ . '/config.php')) {
    die('Installation already completed. Delete config.php to reinstall (WARNING: This will reset all data).');
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate inputs
    $admin_username = trim($_POST['admin_username'] ?? '');
    $admin_password = $_POST['admin_password'] ?? '';
    $dataforseo_username = trim($_POST['dataforseo_username'] ?? '');
    $dataforseo_password = trim($_POST['dataforseo_password'] ?? '');
    $our_property_name = trim($_POST['our_property_name'] ?? 'Aerotel Hoedspruit');

    if (empty($admin_username) || strlen($admin_username) < 3) {
        $errors[] = 'Admin username must be at least 3 characters';
    }
    if (empty($admin_password) || strlen($admin_password) < 6) {
        $errors[] = 'Admin password must be at least 6 characters';
    }
    if (empty($dataforseo_username)) {
        $errors[] = 'DataForSEO username is required';
    }
    if (empty($dataforseo_password)) {
        $errors[] = 'DataForSEO password is required';
    }

    if (empty($errors)) {
        try {
            // Create database
            $db_path = __DIR__ . '/data/market_tracker.db';
            $db = new PDO('sqlite:' . $db_path);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create tables
            $db->exec("
                CREATE TABLE IF NOT EXISTS properties (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    hotel_identifier TEXT,
                    is_ours INTEGER DEFAULT 0,
                    property_type TEXT,
                    star_rating REAL,
                    active INTEGER DEFAULT 1,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $db->exec("
                CREATE TABLE IF NOT EXISTS price_snapshots (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    property_id INTEGER NOT NULL,
                    check_in TEXT NOT NULL,
                    check_out TEXT NOT NULL,
                    ota_source TEXT,
                    price_zar REAL,
                    room_type TEXT,
                    link TEXT,
                    scraped_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (property_id) REFERENCES properties(id)
                )
            ");

            $db->exec("
                CREATE INDEX IF NOT EXISTS idx_price_snapshots_property
                ON price_snapshots(property_id, check_in)
            ");

            $db->exec("
                CREATE TABLE IF NOT EXISTS position_snapshots (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    property_id INTEGER NOT NULL,
                    search_keyword TEXT NOT NULL,
                    position INTEGER,
                    total_results INTEGER,
                    displayed_price REAL,
                    rating REAL,
                    reviews_count INTEGER,
                    scraped_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (property_id) REFERENCES properties(id)
                )
            ");

            $db->exec("
                CREATE INDEX IF NOT EXISTS idx_position_snapshots_property
                ON position_snapshots(property_id, scraped_at)
            ");

            $db->exec("
                CREATE TABLE IF NOT EXISTS demand_scores (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    check_date TEXT UNIQUE NOT NULL,
                    avg_market_price REAL,
                    our_price REAL,
                    demand_score INTEGER,
                    demand_signal TEXT,
                    price_change_pct REAL,
                    calculated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $db->exec("
                CREATE TABLE IF NOT EXISTS cron_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    run_type TEXT NOT NULL,
                    status TEXT NOT NULL,
                    api_calls_made INTEGER DEFAULT 0,
                    api_cost_estimate REAL DEFAULT 0,
                    errors TEXT,
                    started_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT
                )
            ");

            $db->exec("
                CREATE TABLE IF NOT EXISTS settings (
                    key TEXT PRIMARY KEY,
                    value TEXT
                )
            ");

            // Insert default settings
            $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
            $default_settings = [
                ['check_dates', '1,7,14,21'],
                ['months_ahead', '6'],
                ['search_keyword', 'hotels in Hoedspruit'],
                ['location_name', 'South Africa'],
                ['language_name', 'English'],
                ['default_adults', '2'],
                ['currency', 'ZAR'],
                ['admin_username', $admin_username],
                ['admin_password', password_hash($admin_password, PASSWORD_BCRYPT)],
                ['session_secret', bin2hex(random_bytes(32))],
                ['our_property_name', $our_property_name]
            ];

            foreach ($default_settings as $setting) {
                $stmt->execute($setting);
            }

            // Insert default competitors
            $default_competitors = [
                ['Aerotel Hoedspruit', 1],
                ['Phelwana', 0],
                ['Camp Jabulani', 0],
                ['Ezulwini', 0],
                ['Blyde Canyon Forever Resort', 0],
                ['Khaya Ndlovu', 0],
                ['Tremisana', 0],
                ['Raptors Lodge', 0],
                ['Perry\'s Bridge Hollow', 0],
                ['Blue Cottages', 0]
            ];

            $prop_stmt = $db->prepare("INSERT INTO properties (name, is_ours, active) VALUES (?, ?, 1)");
            foreach ($default_competitors as $competitor) {
                $prop_stmt->execute($competitor);
            }

            // Create config.php
            $config_content = "<?php\n";
            $config_content .= "/**\n";
            $config_content .= " * Filename: config.php\n";
            $config_content .= " * Description: Auto-generated configuration file (DO NOT EDIT MANUALLY)\n";
            $config_content .= " * Version: 1.0.0\n";
            $config_content .= " * Created: " . date('Y-m-d H:i:s') . " SAST\n";
            $config_content .= " * Modified: " . date('Y-m-d H:i:s') . " SAST\n";
            $config_content .= " */\n\n";
            $config_content .= "define('DB_PATH', __DIR__ . '/data/market_tracker.db');\n";
            $config_content .= "define('DATAFORSEO_USERNAME', '" . addslashes($dataforseo_username) . "');\n";
            $config_content .= "define('DATAFORSEO_PASSWORD', '" . addslashes($dataforseo_password) . "');\n";
            $config_content .= "define('SESSION_SECRET', '" . bin2hex(random_bytes(32)) . "');\n";
            $config_content .= "define('ALLOWED_WIDGET_DOMAINS', 'aerotel.co.za');\n";
            $config_content .= "define('TIMEZONE', 'Africa/Johannesburg');\n";
            $config_content .= "\ndate_default_timezone_set(TIMEZONE);\n";

            file_put_contents(__DIR__ . '/config.php', $config_content);

            // Create .htaccess for data directory protection
            $htaccess_content = "Order Deny,Allow\nDeny from all\n";
            file_put_contents(__DIR__ . '/data/.htaccess', $htaccess_content);

            // Create .gitignore
            $gitignore_content = "config.php\ndata/*.db\ndata/*.db-journal\n*.bak\n";
            file_put_contents(__DIR__ . '/.gitignore', $gitignore_content);

            $success = true;

            // Lock installer by renaming
            rename(__FILE__, __DIR__ . '/install.php.bak');

        } catch (Exception $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Market Demand Tracker - Installation</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #FFFDF7 0%, #FFF8E7 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .install-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(212, 168, 67, 0.15);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            color: #333;
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .logo p {
            color: #D4A843;
            font-size: 14px;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            color: #333;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #f0f0f0;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #D4A843;
        }

        .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .btn-install {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #D4A843 0%, #C49835 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-install:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(212, 168, 67, 0.3);
        }

        .error {
            background: #fee;
            border-left: 4px solid #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #c33;
            font-size: 14px;
        }

        .success {
            background: #efe;
            border-left: 4px solid #3c3;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #363;
        }

        .success h3 {
            margin-bottom: 10px;
            color: #363;
        }

        .success a {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 20px;
            background: #3c3;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
        }

        .divider {
            margin: 30px 0;
            border-top: 1px solid #f0f0f0;
        }

        .section-title {
            color: #D4A843;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="logo">
            <h1>Market Demand Tracker</h1>
            <p>Installation Wizard</p>
        </div>

        <?php if ($success): ?>
            <div class="success">
                <h3>Installation Successful!</h3>
                <p>Your Market Demand Tracker has been installed successfully. The installer has been locked to prevent re-installation.</p>
                <p><strong>Important:</strong> Keep your admin credentials safe. You can now log in to the dashboard.</p>
                <a href="index.php">Go to Dashboard</a>
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="section-title">Admin Account</div>

                <div class="form-group">
                    <label for="admin_username">Admin Username</label>
                    <input type="text" id="admin_username" name="admin_username"
                           value="<?php echo htmlspecialchars($_POST['admin_username'] ?? ''); ?>" required>
                    <div class="help-text">Minimum 3 characters</div>
                </div>

                <div class="form-group">
                    <label for="admin_password">Admin Password</label>
                    <input type="password" id="admin_password" name="admin_password" required>
                    <div class="help-text">Minimum 6 characters</div>
                </div>

                <div class="divider"></div>
                <div class="section-title">DataForSEO API Credentials</div>

                <div class="form-group">
                    <label for="dataforseo_username">DataForSEO Username</label>
                    <input type="text" id="dataforseo_username" name="dataforseo_username"
                           value="<?php echo htmlspecialchars($_POST['dataforseo_username'] ?? ''); ?>" required>
                    <div class="help-text">Your DataForSEO API username</div>
                </div>

                <div class="form-group">
                    <label for="dataforseo_password">DataForSEO Password</label>
                    <input type="password" id="dataforseo_password" name="dataforseo_password" required>
                    <div class="help-text">Your DataForSEO API password</div>
                </div>

                <div class="divider"></div>
                <div class="section-title">Property Settings</div>

                <div class="form-group">
                    <label for="our_property_name">Your Property Name</label>
                    <input type="text" id="our_property_name" name="our_property_name"
                           value="<?php echo htmlspecialchars($_POST['our_property_name'] ?? 'Aerotel Hoedspruit'); ?>" required>
                    <div class="help-text">The name of your property to track</div>
                </div>

                <button type="submit" class="btn-install">Install Market Demand Tracker</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
