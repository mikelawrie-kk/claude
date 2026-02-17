<?php
/**
 * Filename: layout.php
 * Description: Shared HTML layout template providing renderHeader and renderFooter functions
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Added renderAdminHeader and renderAdminFooter functions
 */

/**
 * Render the HTML document header including doctype, head, navigation bar.
 *
 * @param string $title       Page title
 * @param string $description Meta description for SEO
 * @param string $extraHead   Additional markup to inject into <head>
 * @return void               Outputs HTML directly
 */
function renderHeader(string $title = 'Safari Traveller', string $description = 'Discover Africa\'s safari lodges rated for AI visibility. Entity authority audits, structured data analysis, and AI readiness scores for every safari property in Africa.', string $extraHead = ''): void
{
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $descEsc  = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $titleEsc ?></title>
    <meta name="description" content="<?= $descEsc ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <?= $extraHead ?>
</head>
<body>
    <nav class="site-nav">
        <div class="nav-container">
            <a href="/public/" class="nav-logo">Safari <span>Traveller</span></a>
            <button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
                <span class="nav-toggle-bar"></span>
                <span class="nav-toggle-bar"></span>
                <span class="nav-toggle-bar"></span>
            </button>
            <ul class="nav-links">
                <li><a href="/public/">Home</a></li>
                <li><a href="/public/search.php">Browse</a></li>
                <li><a href="/public/search.php?sort=score">Top Rated</a></li>
                <li><a href="/public/claim.php" class="nav-cta">Claim Your Lodge</a></li>
            </ul>
        </div>
    </nav>
    <main class="site-main">
    <?php
}

/**
 * Render the HTML document footer with site links and closing tags.
 *
 * @param string $extraScripts Additional <script> tags to include before </body>
 * @return void                Outputs HTML directly
 */
function renderFooter(string $extraScripts = ''): void
{
    ?>
    </main>
    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-grid">
                <div class="footer-col">
                    <h4>Safari <span>Traveller</span></h4>
                    <p>Africa's AI-ready safari discovery platform. We audit every safari lodge for entity authority and AI visibility so travellers find the best -- and lodges get found.</p>
                </div>
                <div class="footer-col">
                    <h5>Explore</h5>
                    <ul>
                        <li><a href="/public/">Home</a></li>
                        <li><a href="/public/search.php">Browse Properties</a></li>
                        <li><a href="/public/search.php?sort=score">Top AI Scores</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h5>For Lodges</h5>
                    <ul>
                        <li><a href="/public/claim.php">Claim Your Listing</a></li>
                        <li><a href="https://safariwebonline.com" target="_blank" rel="noopener">Safari Web Online</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h5>Top Countries</h5>
                    <ul>
                        <li><a href="/public/country.php?slug=ZA">South Africa</a></li>
                        <li><a href="/public/country.php?slug=KE">Kenya</a></li>
                        <li><a href="/public/country.php?slug=TZ">Tanzania</a></li>
                        <li><a href="/public/country.php?slug=BW">Botswana</a></li>
                        <li><a href="/public/country.php?slug=NA">Namibia</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> Safari Traveller. Built by <a href="https://safariwebonline.com" target="_blank" rel="noopener">Safari Web Online</a>, Hoedspruit, South Africa.</p>
            </div>
        </div>
    </footer>
    <script src="/assets/js/app.js"></script>
    <?= $extraScripts ?>
</body>
</html>
    <?php
}

/**
 * Render the admin HTML header with sidebar navigation.
 *
 * @param string $pageTitle  The page title shown in the browser and header
 * @param string $extraHead  Additional markup to inject into <head>
 * @return void              Outputs HTML directly
 */
function renderAdminHeader(string $pageTitle = 'Dashboard', string $extraHead = ''): void
{
    $titleEsc = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    $currentPage = basename($_SERVER['PHP_SELF']);
    $adminName = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
    $adminEmail = htmlspecialchars($_SESSION['admin_email'] ?? '', ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $titleEsc ?> - Safari Traveller Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <?= $extraHead ?>
</head>
<body>
    <div class="admin-layout">
        <div class="admin-sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="admin-sidebar-header">
                <h2>Safari <span>Traveller</span></h2>
                <span>Admin Panel</span>
            </div>
            <nav class="admin-sidebar-nav">
                <a href="index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9632;</span> Dashboard
                </a>
                <a href="properties.php" class="<?= $currentPage === 'properties.php' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9632;</span> Properties
                </a>
                <a href="queue.php" class="<?= $currentPage === 'queue.php' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9632;</span> Queue
                </a>
                <a href="audits.php" class="<?= $currentPage === 'audits.php' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9632;</span> Audits
                </a>
                <a href="outreach.php" class="<?= $currentPage === 'outreach.php' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9632;</span> Outreach
                </a>
                <a href="settings.php" class="<?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9632;</span> Settings
                </a>
            </nav>
            <div class="admin-sidebar-footer">
                <div style="margin-bottom: 8px; font-size: 0.8rem;">
                    <strong style="color: #fff;"><?= $adminName ?></strong><br>
                    <span style="font-size: 0.7rem;"><?= $adminEmail ?></span>
                </div>
                <a href="login.php?logout=1">Logout</a>
            </div>
        </aside>
        <div class="admin-content">
            <div class="admin-content-header">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <button class="admin-sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                        <span style="font-size:1.5rem;">&#9776;</span>
                    </button>
                    <h1><?= $titleEsc ?></h1>
                </div>
            </div>
    <?php
}

/**
 * Render the admin HTML footer with closing tags and sidebar toggle script.
 *
 * @param string $extraScripts Additional <script> tags to include before </body>
 * @return void                Outputs HTML directly
 */
function renderAdminFooter(string $extraScripts = ''): void
{
    ?>
        </div><!-- /.admin-content -->
    </div><!-- /.admin-layout -->
    <script>
    function toggleSidebar() {
        var sidebar = document.getElementById('adminSidebar');
        var overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
    }
    </script>
    <script src="/assets/js/app.js"></script>
    <?= $extraScripts ?>
</body>
</html>
    <?php
}
