<?php
/**
 * Filename: index.php
 * Description: Admin dashboard with tabbed interface for Market Demand Tracker
 * Version: 1.0.0
 * Created: 2026-02-16 10:30:00 SAST
 * Modified: 2026-02-16 10:30:00 SAST
 */

session_start();

require_once __DIR__ . '/config.php';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");

    $stmt->execute(['admin_username']);
    $stored_username = $stmt->fetchColumn();

    $stmt->execute(['admin_password']);
    $stored_password = $stmt->fetchColumn();

    if ($username === $stored_username && password_verify($password, $stored_password)) {
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: index.php');
        exit;
    } else {
        $login_error = 'Invalid credentials';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Check authentication
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - Market Demand Tracker</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
        <div class="login-container">
            <div class="login-box">
                <h1>Market Demand Tracker</h1>
                <p class="subtitle">Admin Login</p>

                <?php if (isset($login_error)): ?>
                    <div class="error-message"><?php echo htmlspecialchars($login_error); ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button type="submit" name="login" class="btn-primary">Login</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Market Demand Tracker - Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <header class="dashboard-header">
            <div class="header-left">
                <h1>Market Demand Tracker</h1>
                <p class="header-subtitle">Hotel Price Intelligence Platform</p>
            </div>
            <div class="header-right">
                <span class="user-info">Welcome, Admin</span>
                <a href="?logout" class="btn-logout">Logout</a>
            </div>
        </header>

        <!-- Navigation Tabs -->
        <nav class="tab-nav">
            <button class="tab-btn active" data-tab="dashboard">Dashboard</button>
            <button class="tab-btn" data-tab="competitors">Competitors</button>
            <button class="tab-btn" data-tab="price-explorer">Price Explorer</button>
            <button class="tab-btn" data-tab="trends">Trends</button>
            <button class="tab-btn" data-tab="audit">Run Audit</button>
            <button class="tab-btn" data-tab="settings">Settings</button>
        </nav>

        <!-- Main Content Area -->
        <main class="dashboard-content">

            <!-- Dashboard Tab -->
            <div class="tab-content active" id="tab-dashboard">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon position-icon"></div>
                        <div class="stat-info">
                            <h3>Current Position</h3>
                            <div class="stat-value" id="stat-position">-</div>
                            <div class="stat-label">in search results</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon demand-icon"></div>
                        <div class="stat-info">
                            <h3>High Demand Days</h3>
                            <div class="stat-value" id="stat-high-demand">-</div>
                            <div class="stat-label">next 30 days</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon price-icon"></div>
                        <div class="stat-info">
                            <h3>Price Position</h3>
                            <div class="stat-value" id="stat-price-gap">-</div>
                            <div class="stat-label">vs market average</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon sync-icon"></div>
                        <div class="stat-info">
                            <h3>Last Update</h3>
                            <div class="stat-value" id="stat-last-update">-</div>
                            <div class="stat-label">data collection</div>
                        </div>
                    </div>
                </div>

                <div class="section-card">
                    <h2>Demand Calendar</h2>
                    <p class="section-description">6-month demand forecast based on pricing signals and market data</p>
                    <div id="demand-calendar"></div>
                </div>
            </div>

            <!-- Competitors Tab -->
            <div class="tab-content" id="tab-competitors">
                <div class="section-card">
                    <div class="section-header">
                        <h2>Tracked Properties</h2>
                        <button class="btn-primary" id="btn-add-competitor">Add Competitor</button>
                    </div>

                    <div id="competitors-table"></div>
                </div>

                <!-- Add Competitor Modal -->
                <div id="add-competitor-modal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Add Competitor</h3>
                            <button class="modal-close">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="competitor-name">Property Name</label>
                                <input type="text" id="competitor-name" placeholder="e.g., Phelwana Game Lodge">
                                <p class="help-text">Enter the exact name as it appears in Google Hotel search</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn-secondary modal-close">Cancel</button>
                            <button class="btn-primary" id="btn-save-competitor">Add Property</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Price Explorer Tab -->
            <div class="tab-content" id="tab-price-explorer">
                <div class="section-card">
                    <div class="section-header">
                        <h2>Price Matrix</h2>
                        <div>
                            <label for="price-check-in">Check-in Date:</label>
                            <input type="date" id="price-check-in" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                            <button class="btn-primary" id="btn-load-prices">Load Prices</button>
                        </div>
                    </div>

                    <div id="price-matrix"></div>
                </div>
            </div>

            <!-- Trends Tab -->
            <div class="tab-content" id="tab-trends">
                <div class="section-card">
                    <h2>Position Trend</h2>
                    <p class="section-description">Your property's position in search results over time</p>
                    <canvas id="chart-position" height="80"></canvas>
                </div>

                <div class="section-card">
                    <h2>Price Comparison Trend</h2>
                    <p class="section-description">Your pricing vs market average (last 30 days)</p>
                    <canvas id="chart-price" height="80"></canvas>
                </div>

                <div class="section-card">
                    <h2>Demand Score Forecast</h2>
                    <p class="section-description">Predicted demand for the next 60 days</p>
                    <canvas id="chart-demand" height="80"></canvas>
                </div>
            </div>

            <!-- Run Audit Tab -->
            <div class="tab-content" id="tab-audit">
                <div class="section-card">
                    <h2>Manual Audit</h2>
                    <p class="section-description">Run an immediate price audit for specific dates</p>

                    <div class="audit-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="audit-check-in">Check-in Date</label>
                                <input type="date" id="audit-check-in" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="audit-check-out">Check-out Date</label>
                                <input type="date" id="audit-check-out" value="<?php echo date('Y-m-d', strtotime('+8 days')); ?>">
                            </div>
                        </div>

                        <div class="audit-info">
                            <p><strong>Cost Estimate:</strong> <span id="audit-cost-estimate">~R0.20</span></p>
                            <p class="help-text">Manual audits use the same API as scheduled cron jobs</p>
                        </div>

                        <button class="btn-primary btn-large" id="btn-run-audit">Run Audit Now</button>

                        <div id="audit-progress" class="audit-progress" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill"></div>
                            </div>
                            <p class="progress-text">Running audit...</p>
                        </div>

                        <div id="audit-results" class="audit-results" style="display: none;"></div>
                    </div>
                </div>

                <div class="section-card">
                    <h2>Recent Cron Runs</h2>
                    <div id="cron-log-table"></div>
                </div>
            </div>

            <!-- Settings Tab -->
            <div class="tab-content" id="tab-settings">
                <div class="section-card">
                    <h2>API Configuration</h2>

                    <div class="form-group">
                        <label>DataForSEO Status</label>
                        <button class="btn-secondary" id="btn-test-api">Test API Connection</button>
                        <div id="api-test-result"></div>
                    </div>
                </div>

                <div class="section-card">
                    <h2>Tracking Settings</h2>

                    <form id="settings-form">
                        <div class="form-group">
                            <label for="settings-check-dates">Check Dates (days of month)</label>
                            <input type="text" id="settings-check-dates" value="1,7,14,21">
                            <p class="help-text">Comma-separated days (1-28)</p>
                        </div>

                        <div class="form-group">
                            <label for="settings-months-ahead">Months Ahead</label>
                            <input type="number" id="settings-months-ahead" value="6" min="1" max="12">
                            <p class="help-text">How many months to forecast</p>
                        </div>

                        <div class="form-group">
                            <label for="settings-search-keyword">Search Keyword</label>
                            <input type="text" id="settings-search-keyword" value="hotels in Hoedspruit">
                        </div>

                        <div class="form-group">
                            <label for="settings-location">Location</label>
                            <input type="text" id="settings-location" value="South Africa">
                        </div>

                        <div class="form-group">
                            <label for="settings-adults">Default Adults</label>
                            <input type="number" id="settings-adults" value="2" min="1" max="10">
                        </div>

                        <button type="submit" class="btn-primary">Save Settings</button>
                    </form>
                </div>

                <div class="section-card">
                    <h2>Data Export</h2>

                    <div class="export-buttons">
                        <a href="api/ajax-handlers.php?action=export_csv&type=prices" class="btn-secondary">Export Price Data (CSV)</a>
                        <a href="api/ajax-handlers.php?action=export_csv&type=demand" class="btn-secondary">Export Demand Scores (CSV)</a>
                    </div>
                </div>

                <div class="section-card">
                    <h2>Cron Configuration</h2>

                    <div class="cron-info">
                        <p><strong>Cron Command for cPanel:</strong></p>
                        <code class="cron-command">
                            0 2 * * * /usr/bin/php <?php echo __DIR__; ?>/cron-runner.php >> <?php echo dirname(__DIR__); ?>/logs/market-tracker.log 2>&1
                        </code>
                        <p class="help-text">Run daily at 02:00 SAST. Create logs directory if needed.</p>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script>
        // Pass CSRF token to JavaScript
        window.CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
    </script>
    <script src="assets/dashboard.js"></script>
</body>
</html>
