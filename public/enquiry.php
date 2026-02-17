<?php
/**
 * Filename: enquiry.php
 * Description: Enquiry form handler with validation, notification, and thank-you page
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
// Route: Thank-you page (GET ?success=1&property_id=X)
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['success']) && $_GET['success'] === '1') {
    showThankYouPage();
    exit;
}

// ---------------------------------------------------------------------------
// Only accept POST requests; redirect GET to homepage
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /public/');
    exit;
}

// ---------------------------------------------------------------------------
// Process the enquiry form submission
// ---------------------------------------------------------------------------

handleEnquirySubmission();

// ===========================================================================
// Handler functions
// ===========================================================================

/**
 * Handle the POST enquiry form submission.
 *
 * @return void
 */
function handleEnquirySubmission(): void
{
    $propertyId   = (int) ($_POST['property_id'] ?? 0);
    $visitorName  = trim($_POST['visitor_name'] ?? '');
    $visitorEmail = trim($_POST['visitor_email'] ?? '');
    $visitorPhone = trim($_POST['visitor_phone'] ?? '');
    $message      = trim($_POST['message'] ?? '');
    $errors       = [];

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    // Property ID is required and must exist
    if ($propertyId <= 0) {
        $errors[] = 'Invalid property.';
    } else {
        try {
            $property = Database::fetch(
                "SELECT p.id, p.name, p.slug, p.email AS property_email,
                        c.name AS country_name, c.iso_code AS country_iso,
                        p.country_id, p.reserve_park_id
                 FROM properties p
                 LEFT JOIN countries c ON p.country_id = c.id
                 WHERE p.id = ?",
                [$propertyId]
            );

            if (!$property) {
                $errors[] = 'Property not found.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not verify property. Please try again.';
            $property = null;
        }
    }

    // Visitor name is required, max 255 chars
    if ($visitorName === '') {
        $errors[] = 'Please enter your name.';
    } elseif (mb_strlen($visitorName) > 255) {
        $errors[] = 'Name must be 255 characters or fewer.';
    }

    // Visitor email is required and must be valid
    if ($visitorEmail === '') {
        $errors[] = 'Please enter your email address.';
    } elseif (!filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Visitor phone is optional (no validation beyond trimming)

    // Message is required, max 5000 chars
    if ($message === '') {
        $errors[] = 'Please enter your message.';
    } elseif (mb_strlen($message) > 5000) {
        $errors[] = 'Message must be 5,000 characters or fewer.';
    }

    // -----------------------------------------------------------------------
    // Handle validation failure
    // -----------------------------------------------------------------------

    if (!empty($errors)) {
        $_SESSION['enquiry_errors'] = $errors;
        $_SESSION['enquiry_form']   = [
            'visitor_name'  => $visitorName,
            'visitor_email' => $visitorEmail,
            'visitor_phone' => $visitorPhone,
            'message'       => $message,
        ];

        // Redirect back to property page
        $redirectSlug = $property['slug'] ?? '';
        if ($redirectSlug !== '') {
            header('Location: property.php?slug=' . urlencode($redirectSlug) . '#enquiry-form');
        } else {
            header('Location: property.php?id=' . $propertyId . '#enquiry-form');
        }
        exit;
    }

    // -----------------------------------------------------------------------
    // Insert enquiry into database
    // -----------------------------------------------------------------------

    try {
        $enquiryId = Database::insert('enquiries', [
            'property_id'   => $propertyId,
            'visitor_name'  => $visitorName,
            'visitor_email' => $visitorEmail,
            'visitor_phone' => $visitorPhone !== '' ? $visitorPhone : null,
            'message'       => $message,
            'status'        => 'new',
        ]);
    } catch (Throwable $e) {
        Logger::error('enquiry', 'Failed to insert enquiry', [
            'error_message' => $e->getMessage(),
            'metadata'      => ['property_id' => $propertyId],
        ]);

        $_SESSION['enquiry_errors'] = ['An error occurred while sending your enquiry. Please try again.'];
        $redirectSlug = $property['slug'] ?? '';
        if ($redirectSlug !== '') {
            header('Location: property.php?slug=' . urlencode($redirectSlug) . '#enquiry-form');
        } else {
            header('Location: property.php?id=' . $propertyId . '#enquiry-form');
        }
        exit;
    }

    // -----------------------------------------------------------------------
    // Increment enquiry_count in listings table
    // -----------------------------------------------------------------------

    try {
        Database::query(
            "UPDATE listings SET enquiry_count = enquiry_count + 1 WHERE property_id = ?",
            [$propertyId]
        );
    } catch (Throwable $e) {
        // Non-critical: log but continue
        Logger::error('enquiry', 'Failed to increment enquiry_count', [
            'error_message' => $e->getMessage(),
            'metadata'      => ['property_id' => $propertyId],
        ]);
    }

    // -----------------------------------------------------------------------
    // Send notification email to property contact
    // -----------------------------------------------------------------------

    $recipientEmail = findPropertyContactEmail($propertyId, $property);

    if ($recipientEmail !== '') {
        sendPropertyNotification(
            $recipientEmail,
            $property['name'] ?? 'Unknown Property',
            $visitorName,
            $visitorEmail,
            $visitorPhone,
            $message
        );
    }

    // -----------------------------------------------------------------------
    // Send copy to admin email
    // -----------------------------------------------------------------------

    $adminEmail = getAdminEmail();
    if ($adminEmail !== '' && $adminEmail !== $recipientEmail) {
        sendAdminNotification(
            $adminEmail,
            $property['name'] ?? 'Unknown Property',
            $propertyId,
            $visitorName,
            $visitorEmail,
            $visitorPhone,
            $message
        );
    }

    // -----------------------------------------------------------------------
    // Log the enquiry
    // -----------------------------------------------------------------------

    Logger::info('enquiry', 'New enquiry submitted', [
        'metadata' => [
            'enquiry_id'    => $enquiryId,
            'property_id'   => $propertyId,
            'property_name' => $property['name'] ?? '',
            'visitor_email' => $visitorEmail,
        ],
    ]);

    // -----------------------------------------------------------------------
    // Store visitor email in session for thank-you page
    // -----------------------------------------------------------------------

    $_SESSION['enquiry_visitor_email'] = $visitorEmail;

    // -----------------------------------------------------------------------
    // Redirect to thank-you page
    // -----------------------------------------------------------------------

    header('Location: enquiry.php?success=1&property_id=' . $propertyId);
    exit;
}

/**
 * Display the thank-you page after a successful enquiry submission.
 *
 * @return void
 */
function showThankYouPage(): void
{
    $propertyId   = (int) ($_GET['property_id'] ?? 0);
    $visitorEmail = $_SESSION['enquiry_visitor_email'] ?? '';
    unset($_SESSION['enquiry_visitor_email']);

    // Look up the property
    $property = null;
    if ($propertyId > 0) {
        try {
            $property = Database::fetch(
                "SELECT p.id, p.name, p.slug, p.country_id, p.reserve_park_id,
                        c.name AS country_name
                 FROM properties p
                 LEFT JOIN countries c ON p.country_id = c.id
                 WHERE p.id = ?",
                [$propertyId]
            );
        } catch (Throwable $e) {
            $property = null;
        }
    }

    $propertyName = $property ? htmlspecialchars($property['name']) : 'the property';

    // Fetch related properties in the same country or reserve
    $relatedProperties = [];
    if ($property) {
        try {
            $relatedSQL    = "SELECT p.id, p.name, p.slug, p.ai_readiness_score, p.google_rating,
                                     c.name AS country_name
                              FROM properties p
                              LEFT JOIN countries c ON p.country_id = c.id
                              WHERE p.status = 'published'
                                AND p.id != ?
                                AND (p.country_id = ? OR p.reserve_park_id = ?)
                              ORDER BY p.ai_readiness_score DESC
                              LIMIT 6";
            $relatedParams = [
                $property['id'],
                $property['country_id'] ?? 0,
                $property['reserve_park_id'] ?? 0,
            ];
            $relatedProperties = Database::fetchAll($relatedSQL, $relatedParams);
        } catch (Throwable $e) {
            $relatedProperties = [];
        }
    }

    renderHeader('Thank You');
    ?>

    <style>
    .thankyou-container {
        max-width: 600px;
        margin: 2rem auto;
        text-align: center;
    }

    .thankyou-icon {
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

    .thankyou-container h1 {
        font-size: 1.4rem;
        font-weight: 700;
        color: #2C2C2C;
        margin-bottom: 0.5rem;
    }

    .thankyou-container .subtitle {
        color: #6B6B6B;
        font-size: 0.92rem;
        margin-bottom: 0.5rem;
    }

    .thankyou-container .email-note {
        color: #6B6B6B;
        font-size: 0.88rem;
        margin-bottom: 1.5rem;
    }

    .thankyou-container .email-note strong {
        color: #2C2C2C;
    }

    .related-section {
        max-width: 800px;
        margin: 2.5rem auto 0;
        text-align: left;
    }

    .related-section h2 {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: #2C2C2C;
    }

    .related-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
    }

    .related-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        padding: 1rem;
        text-decoration: none;
        color: inherit;
        transition: box-shadow 0.2s, transform 0.2s;
        display: block;
    }

    .related-card:hover {
        box-shadow: 0 4px 18px rgba(0,0,0,0.1);
        transform: translateY(-2px);
        text-decoration: none;
        color: inherit;
    }

    .related-card h3 {
        font-size: 0.88rem;
        font-weight: 600;
        color: #2C2C2C;
        margin-bottom: 0.2rem;
    }

    .related-card p {
        font-size: 0.75rem;
        color: #6B6B6B;
    }

    .related-card .score-badge {
        display: inline-block;
        padding: 0.15rem 0.5rem;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        margin-top: 0.5rem;
    }

    .score-badge.high { background: #EAF5EB; color: #3A7D44; }
    .score-badge.mid { background: rgba(212,168,75,0.12); color: #8D6E00; }
    .score-badge.low { background: #FDEDEB; color: #C0392B; }

    .cta-row {
        margin-top: 1.5rem;
        display: flex;
        gap: 0.75rem;
        justify-content: center;
        flex-wrap: wrap;
    }

    .cta-row .btn {
        padding: 0.65rem 1.25rem;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.2s;
        display: inline-block;
    }

    .cta-row .btn-primary {
        background: #D4A84B;
        color: #fff;
    }

    .cta-row .btn-primary:hover {
        background: #c49a3a;
        text-decoration: none;
        color: #fff;
    }

    .cta-row .btn-secondary {
        background: #E8E8E3;
        color: #2C2C2C;
    }

    .cta-row .btn-secondary:hover {
        background: #ddd;
        text-decoration: none;
        color: #2C2C2C;
    }

    @media (max-width: 768px) {
        .related-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 480px) {
        .related-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>

    <div class="thankyou-container">
        <div class="thankyou-icon">&#10003;</div>
        <h1>Your Enquiry Has Been Sent</h1>
        <p class="subtitle">Your enquiry has been sent to <strong><?= $propertyName ?></strong>.</p>
        <?php if ($visitorEmail !== ''): ?>
            <p class="email-note">They will respond to <strong><?= htmlspecialchars($visitorEmail) ?></strong> shortly.</p>
        <?php else: ?>
            <p class="email-note">They will respond to your email address shortly.</p>
        <?php endif; ?>

        <div class="cta-row">
            <a href="search.php" class="btn btn-primary">Browse More Properties</a>
            <?php if ($property && !empty($property['slug'])): ?>
                <a href="property.php?slug=<?= htmlspecialchars($property['slug']) ?>" class="btn btn-secondary">Back to <?= $propertyName ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($relatedProperties)): ?>
        <div class="related-section">
            <h2>More Properties in <?= htmlspecialchars($property['country_name'] ?? 'This Region') ?></h2>
            <div class="related-grid">
                <?php foreach ($relatedProperties as $related): ?>
                    <?php
                    $relScore = (int) $related['ai_readiness_score'];
                    $relScoreClass = 'low';
                    if ($relScore >= 70) {
                        $relScoreClass = 'high';
                    } elseif ($relScore >= 40) {
                        $relScoreClass = 'mid';
                    }
                    $relRating = $related['google_rating'] !== null ? number_format((float) $related['google_rating'], 1) : null;
                    ?>
                    <a href="property.php?slug=<?= htmlspecialchars($related['slug'] ?? '') ?>" class="related-card">
                        <h3><?= htmlspecialchars($related['name']) ?></h3>
                        <p>
                            <?= htmlspecialchars($related['country_name'] ?? '') ?>
                            <?php if ($relRating !== null): ?>
                                &nbsp;&mdash;&nbsp; <?= $relRating ?> &#9733;
                            <?php endif; ?>
                        </p>
                        <span class="score-badge <?= $relScoreClass ?>">AI Score: <?= $relScore ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php
    renderFooter();
}

// ===========================================================================
// Utility functions
// ===========================================================================

/**
 * Find the best email address for a property's contact.
 * Checks the property_contacts table first (primary contact), then property email.
 *
 * @param int        $propertyId
 * @param array|null $property
 * @return string Email address or empty string
 */
function findPropertyContactEmail(int $propertyId, ?array $property): string
{
    // First, check property_contacts table for primary contact
    try {
        $contact = Database::fetch(
            "SELECT contact_email FROM property_contacts
             WHERE property_id = ? AND contact_email IS NOT NULL AND contact_email != ''
             ORDER BY is_primary DESC, id ASC
             LIMIT 1",
            [$propertyId]
        );

        if ($contact && !empty($contact['contact_email'])) {
            return $contact['contact_email'];
        }
    } catch (Throwable $e) {
        // Fall through to property email
    }

    // Fall back to property email
    if ($property && !empty($property['property_email'])) {
        return $property['property_email'];
    }

    return '';
}

/**
 * Get the admin email from settings.
 *
 * @return string
 */
function getAdminEmail(): string
{
    try {
        $row = Database::fetch(
            "SELECT setting_value FROM settings WHERE setting_key = 'admin_email'"
        );
        if ($row && !empty($row['setting_value'])) {
            return $row['setting_value'];
        }
    } catch (Throwable $e) {
        // Fall through
    }
    return '';
}

/**
 * Send an enquiry notification email to the property contact.
 *
 * @param string $recipientEmail
 * @param string $propertyName
 * @param string $visitorName
 * @param string $visitorEmail
 * @param string $visitorPhone
 * @param string $message
 * @return void
 */
function sendPropertyNotification(
    string $recipientEmail,
    string $propertyName,
    string $visitorName,
    string $visitorEmail,
    string $visitorPhone,
    string $message
): void {
    $propNameEsc     = htmlspecialchars($propertyName);
    $visNameEsc      = htmlspecialchars($visitorName);
    $visEmailEsc     = htmlspecialchars($visitorEmail);
    $visPhoneEsc     = htmlspecialchars($visitorPhone);
    $messageEsc      = nl2br(htmlspecialchars($message));

    $body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: 'Helvetica Neue', Arial, sans-serif; background: #FAFAF5; padding: 2rem; color: #2C2C2C;">
    <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 2rem; box-shadow: 0 2px 12px rgba(0,0,0,0.06);">
        <h1 style="font-size: 1.15rem; color: #2C2C2C; margin-bottom: 0.75rem;">New Enquiry for {$propNameEsc}</h1>
        <p style="color: #6B6B6B; font-size: 0.9rem; margin-bottom: 1rem;">
            A traveller has submitted an enquiry for your property through Safari Traveller.
        </p>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
            <tr>
                <td style="padding: 0.5rem 0; color: #6B6B6B; width: 100px; vertical-align: top;">Name:</td>
                <td style="padding: 0.5rem 0; color: #2C2C2C; font-weight: 500;">{$visNameEsc}</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; color: #6B6B6B; vertical-align: top;">Email:</td>
                <td style="padding: 0.5rem 0;"><a href="mailto:{$visEmailEsc}" style="color: #D4A84B;">{$visEmailEsc}</a></td>
            </tr>
HTML;

    if ($visitorPhone !== '') {
        $body .= <<<HTML
            <tr>
                <td style="padding: 0.5rem 0; color: #6B6B6B; vertical-align: top;">Phone:</td>
                <td style="padding: 0.5rem 0; color: #2C2C2C;">{$visPhoneEsc}</td>
            </tr>
HTML;
    }

    $body .= <<<HTML
        </table>
        <div style="margin-top: 1rem; padding: 1rem; background: #FAFAF5; border-radius: 8px;">
            <p style="font-size: 0.82rem; color: #6B6B6B; margin-bottom: 0.35rem;">Message:</p>
            <p style="font-size: 0.88rem; color: #2C2C2C; line-height: 1.6;">{$messageEsc}</p>
        </div>
        <p style="margin-top: 1.25rem; font-size: 0.82rem; color: #6B6B6B;">
            Please reply directly to <a href="mailto:{$visEmailEsc}" style="color: #D4A84B;">{$visEmailEsc}</a> to respond to this enquiry.
        </p>
        <hr style="border: none; border-top: 1px solid #E8E8E3; margin: 1.5rem 0;">
        <p style="color: #aaa; font-size: 0.75rem;">
            Sent via Safari Traveller &mdash; Africa's AI-ready safari discovery platform
        </p>
    </div>
</body>
</html>
HTML;

    $subject = 'New enquiry for ' . $propertyName . ' via Safari Traveller';

    try {
        if (file_exists(__DIR__ . '/../lib/email-sender.php')) {
            require_once __DIR__ . '/../lib/email-sender.php';
            if (class_exists('EmailSender')) {
                EmailSender::send($recipientEmail, $subject, $body);
                return;
            }
        }

        // Fallback to PHP mail()
        $headers  = "From: Safari Traveller <noreply@safari-traveller.com>\r\n";
        $headers .= "Reply-To: {$visitorEmail}\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        mail($recipientEmail, $subject, $body, $headers);
    } catch (Throwable $e) {
        Logger::error('enquiry', 'Failed to send property notification', [
            'error_message' => $e->getMessage(),
            'metadata'      => ['recipient' => $recipientEmail],
        ]);
    }
}

/**
 * Send an enquiry copy to the admin email.
 *
 * @param string $adminEmail
 * @param string $propertyName
 * @param int    $propertyId
 * @param string $visitorName
 * @param string $visitorEmail
 * @param string $visitorPhone
 * @param string $message
 * @return void
 */
function sendAdminNotification(
    string $adminEmail,
    string $propertyName,
    int    $propertyId,
    string $visitorName,
    string $visitorEmail,
    string $visitorPhone,
    string $message
): void {
    $propNameEsc = htmlspecialchars($propertyName);
    $visNameEsc  = htmlspecialchars($visitorName);
    $visEmailEsc = htmlspecialchars($visitorEmail);
    $visPhoneEsc = htmlspecialchars($visitorPhone);
    $messageEsc  = nl2br(htmlspecialchars($message));

    $body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: 'Helvetica Neue', Arial, sans-serif; background: #FAFAF5; padding: 2rem; color: #2C2C2C;">
    <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 2rem; box-shadow: 0 2px 12px rgba(0,0,0,0.06);">
        <h1 style="font-size: 1.15rem; color: #2C2C2C; margin-bottom: 0.5rem;">[Admin Copy] Enquiry for {$propNameEsc}</h1>
        <p style="color: #6B6B6B; font-size: 0.85rem; margin-bottom: 1rem;">Property ID: {$propertyId}</p>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
            <tr>
                <td style="padding: 0.4rem 0; color: #6B6B6B; width: 100px;">From:</td>
                <td style="padding: 0.4rem 0; color: #2C2C2C;">{$visNameEsc} &lt;{$visEmailEsc}&gt;</td>
            </tr>
HTML;

    if ($visitorPhone !== '') {
        $body .= <<<HTML
            <tr>
                <td style="padding: 0.4rem 0; color: #6B6B6B;">Phone:</td>
                <td style="padding: 0.4rem 0; color: #2C2C2C;">{$visPhoneEsc}</td>
            </tr>
HTML;
    }

    $body .= <<<HTML
        </table>
        <div style="margin-top: 1rem; padding: 1rem; background: #FAFAF5; border-radius: 8px;">
            <p style="font-size: 0.88rem; color: #2C2C2C; line-height: 1.6;">{$messageEsc}</p>
        </div>
        <hr style="border: none; border-top: 1px solid #E8E8E3; margin: 1.5rem 0;">
        <p style="color: #aaa; font-size: 0.75rem;">
            Admin notification from Safari Traveller
        </p>
    </div>
</body>
</html>
HTML;

    $subject = '[Admin] Enquiry for ' . $propertyName . ' (ID: ' . $propertyId . ')';

    try {
        if (file_exists(__DIR__ . '/../lib/email-sender.php')) {
            require_once __DIR__ . '/../lib/email-sender.php';
            if (class_exists('EmailSender')) {
                EmailSender::send($adminEmail, $subject, $body);
                return;
            }
        }

        // Fallback to PHP mail()
        $headers  = "From: Safari Traveller <noreply@safari-traveller.com>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        mail($adminEmail, $subject, $body, $headers);
    } catch (Throwable $e) {
        Logger::error('enquiry', 'Failed to send admin notification', [
            'error_message' => $e->getMessage(),
            'metadata'      => ['admin_email' => $adminEmail],
        ]);
    }
}
