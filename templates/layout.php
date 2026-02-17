<?php
/**
 * Filename: layout.php
 * Description: Shared HTML layout template for public and admin pages
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.1.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Complete rewrite with updated CSS class names, SVG icons, improved navigation structure
 */

/**
 * Render the public page header (everything from <!DOCTYPE> through opening <main>).
 *
 * @param string $title       Page title (appears in <title> tag)
 * @param string $description Meta description for SEO
 * @param string $extraCss    Additional CSS (raw <link> or <style> tags)
 */
function renderHeader(string $title = '', string $description = '', string $extraCss = ''): void
{
    $siteTitle = 'Safari Traveller';
    $fullTitle = $title
        ? htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' | ' . $siteTitle
        : $siteTitle . ' — Africa\'s AI-Ready Safari Discovery Platform';
    $metaDesc = $description
        ? htmlspecialchars($description, ENT_QUOTES, 'UTF-8')
        : 'Discover Africa\'s finest safari lodges, private guides, and tour operators. AI-ready property listings with entity authority audits.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= $fullTitle ?></title>
    <meta name="description" content="<?= $metaDesc ?>">

    <!-- Favicon placeholder -->
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">

    <!-- Preconnect to Google Fonts for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Main stylesheet (includes Poppins @import) -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <?php if (!empty($extraCss)): ?>
    <?= $extraCss ?>
    <?php endif; ?>
</head>
<body>

    <!-- Public Navigation -->
    <nav class="navbar">
        <div class="navbar-inner">
            <a href="/" class="navbar-logo">
                Safari <span class="logo-accent">Traveller</span>
            </a>

            <div class="navbar-nav">
                <a href="/public/search.php">Browse</a>
                <a href="/public/country.php">Countries</a>
                <a href="/public/about.php">About</a>
                <button class="navbar-search-icon" aria-label="Search" onclick="document.querySelector('.hero-search-input')?.focus()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </button>
            </div>

            <button class="navbar-toggle" aria-label="Toggle navigation" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>

        <div class="navbar-mobile">
            <a href="/public/search.php">Browse Properties</a>
            <a href="/public/country.php">Countries</a>
            <a href="/public/about.php">About Safari Traveller</a>
            <a href="/public/search.php">Search</a>
        </div>
    </nav>

    <main>
<?php
}

/**
 * Render the public page footer (closing </main> through </html>).
 *
 * @param string $extraJs Additional JavaScript (raw <script> tags or inline JS)
 */
function renderFooter(string $extraJs = ''): void
{
    $year = date('Y');
?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <!-- About -->
                <div>
                    <h4>Safari Traveller</h4>
                    <p>
                        Africa's AI-ready safari discovery platform. We help travellers find the perfect safari lodge,
                        private guide, or tour operator across every African country. Each listing is enriched with
                        entity authority audits to ensure you connect with verified, reputable properties.
                    </p>
                </div>

                <!-- Quick Links -->
                <div>
                    <h4>Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="/public/search.php">Browse Properties</a></li>
                        <li><a href="/public/country.php">Safari by Country</a></li>
                        <li><a href="/public/about.php">About Us</a></li>
                        <li><a href="/public/claim.php">Claim Your Listing</a></li>
                    </ul>
                </div>

                <!-- Property Owners -->
                <div>
                    <h4>Property Owners</h4>
                    <ul class="footer-links">
                        <li><a href="/public/claim.php">Claim Your Listing</a></li>
                        <li><a href="/public/about.php#entity-audit">Entity Authority Audit</a></li>
                        <li><a href="/public/about.php#ai-readiness">AI Readiness Score</a></li>
                        <li><a href="/public/about.php#faq">FAQ</a></li>
                    </ul>
                </div>

                <!-- Contact -->
                <div>
                    <h4>Contact</h4>
                    <ul class="footer-contact">
                        <li>Hoedspruit, Limpopo, South Africa</li>
                        <li>info@safari-traveller.com</li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; <?= $year ?> Safari Traveller. All rights reserved.</p>
                <p>Powered by <a href="https://safariweb.online" target="_blank" rel="noopener">Safari Web Online</a></p>
            </div>
        </div>
    </footer>

    <!-- Main JavaScript -->
    <script src="/assets/js/app.js"></script>

    <?php if (!empty($extraJs)): ?>
    <?= $extraJs ?>
    <?php endif; ?>

</body>
</html>
<?php
}

/**
 * Render the admin page header (<!DOCTYPE> through opening content area).
 *
 * @param string $title Page title for the admin area
 */
function renderAdminHeader(string $title): void
{
    $siteTitle = 'Safari Traveller Admin';
    $fullTitle = $title
        ? htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' | ' . $siteTitle
        : $siteTitle;

    // Determine the current page for active sidebar link highlighting
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '', '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $fullTitle ?></title>

    <!-- Favicon placeholder -->
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">

    <!-- Preconnect to Google Fonts for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Main stylesheet (includes Poppins @import) -->
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

    <div class="admin-layout">

        <!-- Admin Sidebar Overlay (mobile) -->
        <div class="admin-sidebar-overlay"></div>

        <!-- Admin Sidebar -->
        <aside class="admin-sidebar">
            <div class="admin-sidebar-header">
                <h2>Safari Traveller</h2>
                <span>Admin Panel</span>
            </div>

            <nav class="admin-sidebar-nav">
                <a href="/admin/index.php" class="<?= $currentPage === 'index' ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="nav-icon"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Dashboard
                </a>
                <a href="/admin/properties.php" class="<?= $currentPage === 'properties' ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="nav-icon"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    Properties
                </a>
                <a href="/admin/queue.php" class="<?= $currentPage === 'queue' ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="nav-icon"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    Queue
                </a>
                <a href="/admin/audits.php" class="<?= $currentPage === 'audits' ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="nav-icon"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Audits
                </a>
                <a href="/admin/outreach.php" class="<?= $currentPage === 'outreach' ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="nav-icon"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    Outreach
                </a>
                <a href="/admin/settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="nav-icon"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    Settings
                </a>
            </nav>

            <div class="admin-sidebar-footer">
                <a href="/" target="_blank" rel="noopener">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="nav-icon" style="width:16px;height:16px;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                    View Site
                </a>
            </div>
        </aside>

        <!-- Admin Content Area -->
        <div class="admin-content">

            <!-- Mobile sidebar toggle + page title -->
            <div class="admin-content-header">
                <button class="admin-sidebar-toggle" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
                <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            </div>

<?php
}

/**
 * Render the admin page footer (closing admin-content, admin-layout, body, html).
 *
 * @param string $extraJs Additional JavaScript (raw <script> tags or inline JS)
 */
function renderAdminFooter(string $extraJs = ''): void
{
?>
        </div><!-- /.admin-content -->
    </div><!-- /.admin-layout -->

    <!-- Main JavaScript -->
    <script src="/assets/js/app.js"></script>

    <?php if (!empty($extraJs)): ?>
    <?= $extraJs ?>
    <?php endif; ?>

</body>
</html>
<?php
}
