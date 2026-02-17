<?php
/**
 * Filename: claim.php
 * Description: Claim listing page with email domain verification and token-based confirmation
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../templates/layout.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$verificationExpiryHours = 48;

// ---------------------------------------------------------------------------
// Route: Handle verification token (GET ?token=X)
// ---------------------------------------------------------------------------

if (isset($_GET['token']) && $_GET['token'] !== '') {
    handleVerification(trim($_GET['token']), $verificationExpiryHours);
    exit;
}

// ---------------------------------------------------------------------------
// Route: Handle claim form POST submission
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_submit'])) {
    handleClaimSubmission($verificationExpiryHours);
    exit;
}

// ---------------------------------------------------------------------------
// Route: Display claim page (GET)
// ---------------------------------------------------------------------------

$propertyId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($propertyId > 0) {
    showClaimForm($propertyId);
} else {
    showGeneralClaimPage();
}

// ===========================================================================
// Handler functions
// ===========================================================================

/**
 * Display the general claim page with a search form to find your property.
 *
 * @return void
 */
function showGeneralClaimPage(): void
{
    // Search for a property by name
    $searchQuery  = trim($_GET['q'] ?? '');
    $searchResults = [];

    if ($searchQuery !== '') {
        try {
            $searchResults = Database::fetchAll(
                "SELECT p.id, p.name, p.formatted_address, p.ai_readiness_score,
                        c.name AS country_name
                 FROM properties p
                 LEFT JOIN countries c ON p.country_id = c.id
                 WHERE p.status = 'published' AND p.name LIKE ?
                 ORDER BY p.name ASC
                 LIMIT 20",
                ['%' . $searchQuery . '%']
            );
        } catch (Throwable $e) {
            $searchResults = [];
        }
    }

    renderHeader('Claim Your Listing');
    ?>

    <style>
    .claim-hero {
        text-align: center;
        padding: 2rem 0 1.5rem;
    }

    .claim-hero h1 {
        font-size: 1.75rem;
        font-weight: 700;
        color: #2C2C2C;
        margin-bottom: 0.5rem;
    }

    .claim-hero p {
        color: #6B6B6B;
        font-size: 0.95rem;
        max-width: 600px;
        margin: 0 auto 1.5rem;
    }

    .claim-search-form {
        max-width: 500px;
        margin: 0 auto 2rem;
        display: flex;
        gap: 0.5rem;
    }

    .claim-search-form input[type="text"] {
        flex: 1;
        padding: 0.7rem 1rem;
        border: 1px solid #E8E8E3;
        border-radius: 8px;
        font-family: 'Poppins', sans-serif;
        font-size: 0.9rem;
    }

    .claim-search-form input[type="text"]:focus {
        outline: none;
        border-color: #D4A84B;
        box-shadow: 0 0 0 3px rgba(212,168,75,0.15);
    }

    .claim-search-form button {
        padding: 0.7rem 1.5rem;
        background: #D4A84B;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-family: 'Poppins', sans-serif;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }

    .claim-search-form button:hover {
        background: #c49a3a;
    }

    .search-results-list {
        max-width: 600px;
        margin: 0 auto 2rem;
    }

    .search-result-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.85rem 1rem;
        background: #fff;
        border-radius: 8px;
        margin-bottom: 0.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: box-shadow 0.2s;
    }

    .search-result-item:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    }

    .search-result-info h3 {
        font-size: 0.92rem;
        font-weight: 600;
        color: #2C2C2C;
        margin-bottom: 0.15rem;
    }

    .search-result-info p {
        font-size: 0.78rem;
        color: #6B6B6B;
    }

    .search-result-claim {
        padding: 0.4rem 0.85rem;
        background: #D4A84B;
        color: #fff;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        transition: background 0.2s;
    }

    .search-result-claim:hover {
        background: #c49a3a;
        text-decoration: none;
        color: #fff;
    }

    .benefits-section {
        max-width: 700px;
        margin: 2rem auto;
    }

    .benefits-section h2 {
        text-align: center;
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 1.25rem;
    }

    .benefits-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }

    .benefit-card {
        background: #fff;
        padding: 1.25rem;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    }

    .benefit-card h3 {
        font-size: 0.92rem;
        font-weight: 600;
        color: #2C2C2C;
        margin-bottom: 0.3rem;
    }

    .benefit-card p {
        font-size: 0.82rem;
        color: #6B6B6B;
        line-height: 1.55;
    }

    .benefit-icon {
        width: 36px;
        height: 36px;
        background: rgba(212,168,75,0.12);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        margin-bottom: 0.65rem;
        color: #D4A84B;
    }

    @media (max-width: 600px) {
        .benefits-grid {
            grid-template-columns: 1fr;
        }

        .claim-search-form {
            flex-direction: column;
        }
    }
    </style>

    <div class="claim-hero">
        <h1>Claim Your Listing</h1>
        <p>Find your safari property on Safari Traveller and take control of your listing. Update details, view your full AI audit report, and respond to traveller enquiries.</p>
    </div>

    <!-- Search form -->
    <form method="get" action="claim.php" class="claim-search-form">
        <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search for your property name..." required>
        <button type="submit">Search</button>
    </form>

    <?php if ($searchQuery !== '' && !empty($searchResults)): ?>
        <div class="search-results-list">
            <?php foreach ($searchResults as $result): ?>
                <div class="search-result-item">
                    <div class="search-result-info">
                        <h3><?= htmlspecialchars($result['name']) ?></h3>
                        <p>
                            <?= htmlspecialchars($result['country_name'] ?? '') ?>
                            <?php if (!empty($result['formatted_address'])): ?>
                                &mdash; <?= htmlspecialchars($result['formatted_address']) ?>
                            <?php endif; ?>
                            &nbsp; AI Score: <?= (int) $result['ai_readiness_score'] ?>/100
                        </p>
                    </div>
                    <a href="claim.php?id=<?= (int) $result['id'] ?>" class="search-result-claim">Claim</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($searchQuery !== '' && empty($searchResults)): ?>
        <div style="text-align:center;margin-bottom:2rem;">
            <p style="color:#6B6B6B;">No properties found matching "<strong><?= htmlspecialchars($searchQuery) ?></strong>". Try a different name or <a href="search.php">browse all properties</a>.</p>
        </div>
    <?php endif; ?>

    <!-- Benefits of claiming -->
    <div class="benefits-section">
        <h2>Why Claim Your Listing?</h2>
        <div class="benefits-grid">
            <div class="benefit-card">
                <div class="benefit-icon">&#9998;</div>
                <h3>Update Your Details</h3>
                <p>Keep your property information accurate and up to date. Add photos, descriptions, and contact details to attract more travellers.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">&#9776;</div>
                <h3>Full Audit Report</h3>
                <p>Access your complete Entity Authority Audit showing exactly how AI systems see your property and what to improve.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">&#9733;</div>
                <h3>AI Recommendations</h3>
                <p>Get personalised recommendations to improve your AI readiness score and increase visibility in AI-powered search.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">&#9993;</div>
                <h3>Receive Enquiries</h3>
                <p>Travellers can contact you directly through Safari Traveller. Get notified of every enquiry in real time.</p>
            </div>
        </div>
    </div>

    <?php
    renderFooter();
}

/**
 * Display the claim form for a specific property.
 *
 * @param int $propertyId
 * @return void
 */
function showClaimForm(int $propertyId): void
{
    // Look up the property
    try {
        $property = Database::fetch(
            "SELECT p.id, p.name, p.slug, p.formatted_address, p.ai_readiness_score,
                    p.website, p.property_type, p.google_rating,
                    c.name AS country_name, c.iso_code AS country_iso,
                    rp.name AS reserve_name
             FROM properties p
             LEFT JOIN countries c ON p.country_id = c.id
             LEFT JOIN reserves_parks rp ON p.reserve_park_id = rp.id
             WHERE p.id = ?",
            [$propertyId]
        );
    } catch (Throwable $e) {
        $property = false;
    }

    if (!$property) {
        renderHeader('Claim Your Listing');
        echo '<div class="alert alert-error">Property not found. Please <a href="claim.php">search for your property</a> again.</div>';
        renderFooter();
        return;
    }

    // Check if already claimed
    try {
        $listing = Database::fetch(
            "SELECT is_claimed, claimed_by_email FROM listings WHERE property_id = ?",
            [$propertyId]
        );
    } catch (Throwable $e) {
        $listing = false;
    }

    if ($listing && (int) $listing['is_claimed'] === 1) {
        renderHeader('Listing Already Claimed');
        echo '<div class="alert alert-info">This listing has already been claimed. If you believe this is an error, please contact us.</div>';
        echo '<p style="text-align:center;margin-top:1rem;"><a href="search.php" class="btn btn-primary">Browse Properties</a></p>';
        renderFooter();
        return;
    }

    // Pull session flash errors
    $errors = $_SESSION['claim_errors'] ?? [];
    unset($_SESSION['claim_errors']);
    $formEmail   = $_SESSION['claim_form_email'] ?? '';
    $formWebsite = $_SESSION['claim_form_website'] ?? '';
    unset($_SESSION['claim_form_email'], $_SESSION['claim_form_website']);

    $propName     = htmlspecialchars($property['name']);
    $propAddress  = htmlspecialchars($property['formatted_address'] ?? '');
    $propCountry  = htmlspecialchars($property['country_name'] ?? '');
    $propReserve  = htmlspecialchars($property['reserve_name'] ?? '');
    $propScore    = (int) $property['ai_readiness_score'];
    $propWebsite  = htmlspecialchars($property['website'] ?? '');

    renderHeader('Claim ' . $property['name']);
    ?>

    <style>
    .claim-container {
        max-width: 640px;
        margin: 0 auto;
    }

    .claim-heading {
        font-size: 1.4rem;
        font-weight: 700;
        color: #2C2C2C;
        margin-bottom: 0.35rem;
    }

    .claim-subheading {
        color: #6B6B6B;
        font-size: 0.9rem;
        margin-bottom: 1.5rem;
    }

    .property-summary {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .property-summary-score {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
    }

    .property-summary-score.high { background: #3A7D44; }
    .property-summary-score.mid { background: #D4A84B; }
    .property-summary-score.low { background: #C0392B; }

    .property-summary-info h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #2C2C2C;
        margin-bottom: 0.2rem;
    }

    .property-summary-info p {
        font-size: 0.82rem;
        color: #6B6B6B;
    }

    .claim-benefits-compact {
        background: rgba(212,168,75,0.08);
        border-radius: 8px;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
    }

    .claim-benefits-compact h4 {
        font-size: 0.88rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #2C2C2C;
    }

    .claim-benefits-compact ul {
        list-style: none;
        padding: 0;
    }

    .claim-benefits-compact ul li {
        font-size: 0.82rem;
        color: #6B6B6B;
        padding: 0.2rem 0;
        padding-left: 1.1rem;
        position: relative;
    }

    .claim-benefits-compact ul li::before {
        content: "\2713";
        position: absolute;
        left: 0;
        color: #3A7D44;
        font-weight: 700;
    }

    .claim-form-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        padding: 1.5rem;
    }

    .claim-form-card h3 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #E8E8E3;
    }

    .domain-hint {
        font-size: 0.75rem;
        color: #6B6B6B;
        margin-top: 0.25rem;
    }

    .domain-mismatch {
        display: none;
        font-size: 0.75rem;
        color: #C0392B;
        margin-top: 0.25rem;
    }

    .claim-form-card .btn-submit {
        width: 100%;
        padding: 0.75rem 1.5rem;
        background: #D4A84B;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-family: 'Poppins', sans-serif;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
        margin-top: 0.5rem;
    }

    .claim-form-card .btn-submit:hover {
        background: #c49a3a;
    }
    </style>

    <div class="claim-container">
        <h1 class="claim-heading">Claim <?= $propName ?> on Safari Traveller</h1>
        <p class="claim-subheading">Verify your ownership to manage this listing.</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Property summary card -->
        <div class="property-summary">
            <?php
            $scoreClass = 'low';
            if ($propScore >= 70) {
                $scoreClass = 'high';
            } elseif ($propScore >= 40) {
                $scoreClass = 'mid';
            }
            ?>
            <div class="property-summary-score <?= $scoreClass ?>"><?= $propScore ?></div>
            <div class="property-summary-info">
                <h3><?= $propName ?></h3>
                <p>
                    <?php
                    $locParts = [];
                    if ($propReserve !== '') {
                        $locParts[] = $propReserve;
                    }
                    if ($propCountry !== '') {
                        $locParts[] = $propCountry;
                    }
                    echo !empty($locParts) ? implode(', ', $locParts) : $propAddress;
                    ?>
                    &nbsp;&mdash;&nbsp; AI Score: <?= $propScore ?>/100
                </p>
            </div>
        </div>

        <!-- Benefits compact -->
        <div class="claim-benefits-compact">
            <h4>Benefits of claiming your listing</h4>
            <ul>
                <li>Update your property details, photos, and contact information</li>
                <li>Access your full Entity Authority Audit report</li>
                <li>Get personalised AI readiness recommendations</li>
                <li>Receive and respond to traveller enquiries directly</li>
            </ul>
        </div>

        <!-- Claim form -->
        <div class="claim-form-card">
            <h3>Verify Your Ownership</h3>
            <form method="post" action="claim.php" id="claimForm">
                <input type="hidden" name="claim_submit" value="1">
                <input type="hidden" name="property_id" value="<?= $propertyId ?>">

                <div class="form-group">
                    <label for="claim-email">Your Email Address</label>
                    <input type="email" id="claim-email" name="email" required
                           value="<?= htmlspecialchars($formEmail) ?>"
                           placeholder="you@yourproperty.com">
                    <div class="domain-hint">Use your property's email domain for verification (e.g., name@<?= $propWebsite !== '' ? parse_url('https://' . str_replace(['http://', 'https://'], '', $propWebsite), PHP_URL_HOST) ?? 'yourproperty.com' : 'yourproperty.com' ?>)</div>
                    <div class="domain-mismatch" id="domainMismatch">Email domain does not match the property website domain. Please use an email address from the same domain as your property website.</div>
                </div>

                <div class="form-group">
                    <label for="claim-website">Property Website URL</label>
                    <input type="url" id="claim-website" name="website" required
                           value="<?= htmlspecialchars($formWebsite !== '' ? $formWebsite : ($property['website'] ?? '')) ?>"
                           placeholder="https://www.yourproperty.com">
                    <div class="domain-hint">We will check that your email domain matches this website.</div>
                </div>

                <button type="submit" class="btn-submit" id="claimSubmitBtn">Send Verification Email</button>
            </form>
        </div>
    </div>

    <!-- Client-side domain matching -->
    <script>
    (function() {
        var emailInput   = document.getElementById('claim-email');
        var websiteInput = document.getElementById('claim-website');
        var mismatchMsg  = document.getElementById('domainMismatch');
        var submitBtn    = document.getElementById('claimSubmitBtn');
        var form         = document.getElementById('claimForm');

        function extractDomain(url) {
            try {
                if (url.indexOf('://') === -1) {
                    url = 'https://' + url;
                }
                var hostname = new URL(url).hostname.toLowerCase();
                // Remove www. prefix
                return hostname.replace(/^www\./, '');
            } catch (e) {
                return '';
            }
        }

        function getEmailDomain(email) {
            var parts = email.split('@');
            if (parts.length === 2) {
                return parts[1].toLowerCase().replace(/^www\./, '');
            }
            return '';
        }

        function checkDomains() {
            var email   = emailInput.value.trim();
            var website = websiteInput.value.trim();

            if (email === '' || website === '') {
                mismatchMsg.style.display = 'none';
                return true;
            }

            var emailDomain   = getEmailDomain(email);
            var websiteDomain = extractDomain(website);

            if (emailDomain === '' || websiteDomain === '') {
                mismatchMsg.style.display = 'none';
                return true;
            }

            // Check if the email domain matches or is a subdomain of the website domain
            if (emailDomain === websiteDomain || emailDomain.endsWith('.' + websiteDomain) || websiteDomain.endsWith('.' + emailDomain)) {
                mismatchMsg.style.display = 'none';
                return true;
            } else {
                mismatchMsg.style.display = 'block';
                return false;
            }
        }

        emailInput.addEventListener('input', checkDomains);
        websiteInput.addEventListener('input', checkDomains);

        form.addEventListener('submit', function(e) {
            if (!checkDomains()) {
                e.preventDefault();
                emailInput.focus();
            }
        });
    })();
    </script>

    <?php
    renderFooter();
}

/**
 * Handle the POST submission of the claim form.
 *
 * @param int $verificationExpiryHours
 * @return void
 */
function handleClaimSubmission(int $verificationExpiryHours): void
{
    $propertyId = (int) ($_POST['property_id'] ?? 0);
    $email      = trim($_POST['email'] ?? '');
    $website    = trim($_POST['website'] ?? '');
    $errors     = [];

    // Store form values for repopulation on error
    $_SESSION['claim_form_email']   = $email;
    $_SESSION['claim_form_website'] = $website;

    // Validate property exists
    if ($propertyId <= 0) {
        $errors[] = 'Invalid property.';
    } else {
        try {
            $property = Database::fetch("SELECT id, name FROM properties WHERE id = ?", [$propertyId]);
            if (!$property) {
                $errors[] = 'Property not found.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not verify property. Please try again.';
        }
    }

    // Validate email
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }

    // Validate website
    if ($website === '') {
        $errors[] = 'Please provide your property website URL.';
    }

    // Verify email domain matches website domain
    if ($email !== '' && $website !== '') {
        $emailParts = explode('@', $email);
        $emailDomain = strtolower($emailParts[1] ?? '');

        // Parse website domain
        $websiteUrl = $website;
        if (strpos($websiteUrl, '://') === false) {
            $websiteUrl = 'https://' . $websiteUrl;
        }
        $parsedHost = parse_url($websiteUrl, PHP_URL_HOST);
        $websiteDomain = strtolower($parsedHost ?? '');

        // Remove www. prefix
        $emailDomain   = preg_replace('/^www\./', '', $emailDomain);
        $websiteDomain = preg_replace('/^www\./', '', $websiteDomain);

        if ($emailDomain === '' || $websiteDomain === '') {
            $errors[] = 'Could not parse the email or website domain.';
        } elseif ($emailDomain !== $websiteDomain
                  && !str_ends_with($emailDomain, '.' . $websiteDomain)
                  && !str_ends_with($websiteDomain, '.' . $emailDomain)) {
            $errors[] = 'Your email domain (' . htmlspecialchars($emailDomain) . ') does not match the property website domain (' . htmlspecialchars($websiteDomain) . '). Please use an email address from the same domain.';
        }
    }

    // Check if already claimed
    if (empty($errors) && $propertyId > 0) {
        try {
            $listing = Database::fetch(
                "SELECT is_claimed FROM listings WHERE property_id = ?",
                [$propertyId]
            );
            if ($listing && (int) $listing['is_claimed'] === 1) {
                $errors[] = 'This listing has already been claimed.';
            }
        } catch (Throwable $e) {
            // Continue -- will handle below
        }
    }

    // If errors, redirect back
    if (!empty($errors)) {
        $_SESSION['claim_errors'] = $errors;
        header('Location: claim.php?id=' . $propertyId);
        exit;
    }

    // Generate verification token
    $token = bin2hex(random_bytes(32)); // 64-char hex string
    $now   = date('Y-m-d H:i:s');

    try {
        // Check if a listings row already exists
        $existingListing = Database::fetch(
            "SELECT id FROM listings WHERE property_id = ?",
            [$propertyId]
        );

        if ($existingListing) {
            Database::update(
                'listings',
                [
                    'verification_token'   => $token,
                    'verification_sent_at' => $now,
                    'claimed_by_email'     => $email,
                ],
                'property_id = ?',
                [$propertyId]
            );
        } else {
            Database::insert('listings', [
                'property_id'          => $propertyId,
                'verification_token'   => $token,
                'verification_sent_at' => $now,
                'claimed_by_email'     => $email,
            ]);
        }
    } catch (Throwable $e) {
        Logger::error('claim', 'Failed to store verification token', [
            'error_message' => $e->getMessage(),
            'metadata'      => ['property_id' => $propertyId, 'email' => $email],
        ]);
        $_SESSION['claim_errors'] = ['An error occurred. Please try again.'];
        header('Location: claim.php?id=' . $propertyId);
        exit;
    }

    // Send verification email
    try {
        $verificationUrl = getBaseUrl() . '/public/claim.php?token=' . urlencode($token);
        $propertyName    = $property['name'] ?? 'your property';

        // Try to use the email sender if available
        if (file_exists(__DIR__ . '/../lib/email-sender.php')) {
            require_once __DIR__ . '/../lib/email-sender.php';

            if (class_exists('EmailSender')) {
                EmailSender::send($email, 'Verify your listing claim on Safari Traveller', buildVerificationEmailBody($propertyName, $verificationUrl, $verificationExpiryHours));
            }
        } else {
            // Fallback to PHP mail()
            $subject = 'Verify your listing claim on Safari Traveller';
            $headers = "From: Safari Traveller <noreply@safari-traveller.com>\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body = buildVerificationEmailBody($propertyName, $verificationUrl, $verificationExpiryHours);
            mail($email, $subject, $body, $headers);
        }

        Logger::info('claim', 'Verification email sent', [
            'metadata' => ['property_id' => $propertyId, 'email' => $email],
        ]);
    } catch (Throwable $e) {
        Logger::error('claim', 'Failed to send verification email', [
            'error_message' => $e->getMessage(),
            'metadata'      => ['property_id' => $propertyId, 'email' => $email],
        ]);
        // Still show the confirmation page -- the token is stored
    }

    // Clear form data from session
    unset($_SESSION['claim_form_email'], $_SESSION['claim_form_website']);

    // Show "check your email" confirmation page
    renderHeader('Check Your Email');
    ?>

    <style>
    .confirm-container {
        max-width: 520px;
        margin: 2rem auto;
        text-align: center;
    }

    .confirm-icon {
        width: 72px;
        height: 72px;
        background: #EAF5EB;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 2rem;
        color: #3A7D44;
    }

    .confirm-container h1 {
        font-size: 1.35rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .confirm-container p {
        color: #6B6B6B;
        font-size: 0.9rem;
        margin-bottom: 0.75rem;
    }

    .confirm-container .email-highlight {
        font-weight: 600;
        color: #2C2C2C;
    }

    .confirm-note {
        background: #FFF8E1;
        border: 1px solid #FFE082;
        color: #8D6E00;
        padding: 0.85rem 1rem;
        border-radius: 8px;
        font-size: 0.82rem;
        margin-top: 1.5rem;
        text-align: left;
    }
    </style>

    <div class="confirm-container">
        <div class="confirm-icon">&#9993;</div>
        <h1>Check Your Email</h1>
        <p>We have sent a verification email to <span class="email-highlight"><?= htmlspecialchars($email) ?></span>.</p>
        <p>Click the link in the email to verify your ownership of <strong><?= htmlspecialchars($property['name'] ?? 'this property') ?></strong>.</p>
        <div class="confirm-note">
            <strong>Note:</strong> The verification link will expire in <?= $verificationExpiryHours ?> hours. If you don't see the email, please check your spam or junk folder.
        </div>
        <p style="margin-top: 1.5rem;">
            <a href="search.php" class="btn btn-secondary">Browse Properties</a>
        </p>
    </div>

    <?php
    renderFooter();
}

/**
 * Handle verification when a user clicks the token link.
 *
 * @param string $token
 * @param int    $expiryHours
 * @return void
 */
function handleVerification(string $token, int $expiryHours): void
{
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        renderHeader('Invalid Verification Link');
        echo '<div class="alert alert-error">The verification link is invalid. Please try claiming your listing again.</div>';
        echo '<p style="text-align:center;margin-top:1rem;"><a href="claim.php" class="btn btn-primary">Claim a Listing</a></p>';
        renderFooter();
        return;
    }

    try {
        $listing = Database::fetch(
            "SELECT l.id, l.property_id, l.verification_sent_at, l.is_claimed, l.claimed_by_email,
                    p.name AS property_name, p.slug AS property_slug
             FROM listings l
             JOIN properties p ON l.property_id = p.id
             WHERE l.verification_token = ?",
            [$token]
        );
    } catch (Throwable $e) {
        renderHeader('Verification Error');
        echo '<div class="alert alert-error">An error occurred while verifying your listing. Please try again later.</div>';
        renderFooter();
        return;
    }

    // Token not found
    if (!$listing) {
        renderHeader('Invalid Verification Link');
        echo '<div class="alert alert-error">The verification link is invalid or has already been used. Please try claiming your listing again.</div>';
        echo '<p style="text-align:center;margin-top:1rem;"><a href="claim.php" class="btn btn-primary">Claim a Listing</a></p>';
        renderFooter();
        return;
    }

    // Already claimed
    if ((int) $listing['is_claimed'] === 1) {
        renderHeader('Already Claimed');
        echo '<div class="alert alert-info">This listing has already been claimed and verified.</div>';
        echo '<p style="text-align:center;margin-top:1rem;"><a href="property.php?slug=' . htmlspecialchars($listing['property_slug'] ?? '') . '" class="btn btn-primary">View Your Listing</a></p>';
        renderFooter();
        return;
    }

    // Check expiry
    $sentAt     = strtotime($listing['verification_sent_at']);
    $expiresAt  = $sentAt + ($expiryHours * 3600);
    $now        = time();

    if ($now > $expiresAt) {
        renderHeader('Verification Link Expired');
        echo '<div class="alert alert-error">This verification link has expired. Please submit a new claim request.</div>';
        echo '<p style="text-align:center;margin-top:1rem;"><a href="claim.php?id=' . (int) $listing['property_id'] . '" class="btn btn-primary">Claim Again</a></p>';
        renderFooter();
        return;
    }

    // Mark as claimed
    try {
        Database::update(
            'listings',
            [
                'is_claimed'         => 1,
                'claimed_at'         => date('Y-m-d H:i:s'),
                'verification_token' => null, // Clear the token after use
            ],
            'id = ?',
            [$listing['id']]
        );

        Logger::info('claim', 'Listing claimed successfully', [
            'metadata' => [
                'property_id' => $listing['property_id'],
                'email'       => $listing['claimed_by_email'],
            ],
        ]);
    } catch (Throwable $e) {
        Logger::error('claim', 'Failed to mark listing as claimed', [
            'error_message' => $e->getMessage(),
            'metadata'      => ['listing_id' => $listing['id']],
        ]);
        renderHeader('Verification Error');
        echo '<div class="alert alert-error">An error occurred while verifying your listing. Please try again later.</div>';
        renderFooter();
        return;
    }

    $propertyName = htmlspecialchars($listing['property_name'] ?? 'your property');
    $propertySlug = htmlspecialchars($listing['property_slug'] ?? '');

    renderHeader('Listing Claimed Successfully');
    ?>

    <style>
    .success-container {
        max-width: 520px;
        margin: 2rem auto;
        text-align: center;
    }

    .success-icon-lg {
        width: 80px;
        height: 80px;
        background: #EAF5EB;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 2.5rem;
        color: #3A7D44;
    }

    .success-container h1 {
        font-size: 1.4rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .success-container p {
        color: #6B6B6B;
        font-size: 0.9rem;
        margin-bottom: 0.75rem;
    }

    .success-actions {
        display: flex;
        gap: 0.75rem;
        justify-content: center;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }

    .success-actions .btn {
        padding: 0.65rem 1.25rem;
        font-size: 0.9rem;
    }
    </style>

    <div class="success-container">
        <div class="success-icon-lg">&#10003;</div>
        <h1>You've Claimed <?= $propertyName ?>!</h1>
        <p>Your ownership has been verified. You can now manage your listing, update details, view your full AI audit report, and respond to enquiries.</p>
        <div class="success-actions">
            <?php if ($propertySlug !== ''): ?>
                <a href="property.php?slug=<?= $propertySlug ?>" class="btn btn-primary">View Your Listing</a>
            <?php endif; ?>
            <a href="search.php" class="btn btn-secondary">Browse Properties</a>
        </div>
    </div>

    <?php
    renderFooter();
}

// ===========================================================================
// Utility functions
// ===========================================================================

/**
 * Get the base URL for the site.
 *
 * @return string
 */
function getBaseUrl(): string
{
    try {
        $row = Database::fetch(
            "SELECT setting_value FROM settings WHERE setting_key = 'site_url'"
        );
        if ($row && !empty($row['setting_value'])) {
            return rtrim($row['setting_value'], '/');
        }
    } catch (Throwable $e) {
        // Fall through
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'safari-traveller.com';

    return $protocol . '://' . $host;
}

/**
 * Build the HTML body for the verification email.
 *
 * @param string $propertyName
 * @param string $verificationUrl
 * @param int    $expiryHours
 * @return string HTML email body
 */
function buildVerificationEmailBody(string $propertyName, string $verificationUrl, int $expiryHours): string
{
    $propNameEsc = htmlspecialchars($propertyName);
    $urlEsc      = htmlspecialchars($verificationUrl);

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: 'Helvetica Neue', Arial, sans-serif; background: #FAFAF5; padding: 2rem; color: #2C2C2C;">
    <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 2rem; box-shadow: 0 2px 12px rgba(0,0,0,0.06);">
        <h1 style="font-size: 1.25rem; color: #2C2C2C; margin-bottom: 1rem;">Verify Your Listing Claim</h1>
        <p style="color: #6B6B6B; font-size: 0.95rem; line-height: 1.6;">
            You requested to claim <strong>{$propNameEsc}</strong> on Safari Traveller.
            Click the button below to verify your ownership.
        </p>
        <div style="text-align: center; margin: 1.75rem 0;">
            <a href="{$urlEsc}"
               style="display: inline-block; padding: 0.75rem 2rem; background: #D4A84B; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.95rem;">
                Verify My Listing
            </a>
        </div>
        <p style="color: #6B6B6B; font-size: 0.82rem; line-height: 1.6;">
            This link will expire in {$expiryHours} hours. If you did not request this, you can safely ignore this email.
        </p>
        <hr style="border: none; border-top: 1px solid #E8E8E3; margin: 1.5rem 0;">
        <p style="color: #aaa; font-size: 0.75rem;">
            Safari Traveller &mdash; Africa's AI-ready safari discovery platform
        </p>
    </div>
</body>
</html>
HTML;
}
