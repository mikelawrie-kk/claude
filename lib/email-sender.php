<?php
/**
 * Filename: email-sender.php
 * Description: Email sending utility for outreach, enquiry notifications, and claim verification
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/db.php';

/**
 * Send an email using PHP mail() or SMTP if configured in the settings table.
 *
 * @param string $to       Recipient email address
 * @param string $subject  Email subject line
 * @param string $htmlBody HTML body content
 * @param string $textBody Plain-text fallback body (auto-generated from HTML if empty)
 * @return bool            True on success, false on failure
 */
function sendEmail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    // Validate recipient
    $to = filter_var(trim($to), FILTER_VALIDATE_EMAIL);
    if (!$to) {
        error_log('[Safari Traveller] sendEmail: Invalid recipient address.');
        return false;
    }

    // Generate plain-text version if not provided
    if (empty($textBody)) {
        $textBody = htmlToPlainText($htmlBody);
    }

    // Load SMTP settings from the database
    $smtp = getSmtpSettings();

    // Build common headers
    $fromName  = $smtp['from_name'] ?: 'Safari Traveller';
    $fromEmail = $smtp['from_email'] ?: 'noreply@safari-traveller.com';
    $replyTo   = $smtp['reply_to'] ?: $fromEmail;

    // Wrap HTML body in a styled email template
    $htmlBody = wrapEmailTemplate($htmlBody);

    // Generate a unique boundary for multipart messages
    $boundary = md5(uniqid(time()));

    // Attempt SMTP if configured, otherwise fall back to PHP mail()
    if (!empty($smtp['smtp_host']) && !empty($smtp['smtp_port'])) {
        return sendViaSmtp($to, $subject, $htmlBody, $textBody, $smtp, $boundary);
    }

    return sendViaPhpMail($to, $subject, $htmlBody, $textBody, $fromName, $fromEmail, $replyTo, $boundary);
}

/**
 * Send email via PHP's built-in mail() function.
 *
 * @param string $to        Recipient
 * @param string $subject   Subject
 * @param string $htmlBody  HTML body
 * @param string $textBody  Plain-text body
 * @param string $fromName  Sender display name
 * @param string $fromEmail Sender email
 * @param string $replyTo   Reply-to address
 * @param string $boundary  MIME boundary
 * @return bool
 */
function sendViaPhpMail(
    string $to,
    string $subject,
    string $htmlBody,
    string $textBody,
    string $fromName,
    string $fromEmail,
    string $replyTo,
    string $boundary
): bool {
    $headers = [];
    $headers[] = 'From: ' . formatEmailAddress($fromName, $fromEmail);
    $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'X-Mailer: SafariTraveller/1.0';
    $headers[] = 'X-Priority: 3';

    $body = buildMultipartBody($textBody, $htmlBody, $boundary);

    $result = @mail($to, $subject, $body, implode("\r\n", $headers));

    if (!$result) {
        error_log('[Safari Traveller] sendEmail (mail): Failed to send to ' . $to . ' — Subject: ' . $subject);
    }

    return $result;
}

/**
 * Send email via SMTP using fsockopen.
 *
 * This is a lightweight SMTP client that handles AUTH LOGIN, STARTTLS (when
 * port 587 is used), and basic error reporting. For production deployments
 * with complex SMTP requirements, consider replacing this with PHPMailer.
 *
 * @param string $to       Recipient
 * @param string $subject  Subject
 * @param string $htmlBody HTML body
 * @param string $textBody Plain-text body
 * @param array  $smtp     SMTP settings array from getSmtpSettings()
 * @param string $boundary MIME boundary
 * @return bool
 */
function sendViaSmtp(
    string $to,
    string $subject,
    string $htmlBody,
    string $textBody,
    array $smtp,
    string $boundary
): bool {
    $host     = $smtp['smtp_host'];
    $port     = (int) $smtp['smtp_port'];
    $user     = $smtp['smtp_user'] ?? '';
    $pass     = $smtp['smtp_pass'] ?? '';
    $security = $smtp['smtp_security'] ?? ''; // 'tls' or 'ssl'
    $fromName  = $smtp['from_name'] ?: 'Safari Traveller';
    $fromEmail = $smtp['from_email'] ?: 'noreply@safari-traveller.com';
    $replyTo   = $smtp['reply_to'] ?: $fromEmail;

    // Use ssl:// prefix for port 465
    $protocol = '';
    if ($security === 'ssl' || $port === 465) {
        $protocol = 'ssl://';
    }

    $errno  = 0;
    $errstr = '';
    $socket = @fsockopen($protocol . $host, $port, $errno, $errstr, 30);

    if (!$socket) {
        error_log('[Safari Traveller] SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    // Helper to read a response from the server
    $readResponse = function () use ($socket): string {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            // Last line of multi-line response has space after code
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };

    // Helper to send a command and check response code
    $sendCommand = function (string $command, int $expectedCode) use ($socket, $readResponse): bool {
        fwrite($socket, $command . "\r\n");
        $response = $readResponse();
        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            error_log('[Safari Traveller] SMTP error: Expected ' . $expectedCode . ', got ' . $code . ' — ' . trim($response));
            return false;
        }
        return true;
    };

    try {
        // Read greeting
        $readResponse();

        // EHLO
        if (!$sendCommand('EHLO ' . gethostname(), 250)) {
            fclose($socket);
            return false;
        }

        // STARTTLS for port 587
        if (($security === 'tls' || $port === 587) && $security !== 'ssl') {
            if (!$sendCommand('STARTTLS', 220)) {
                fclose($socket);
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('[Safari Traveller] SMTP STARTTLS crypto failed.');
                fclose($socket);
                return false;
            }
            // Re-EHLO after STARTTLS
            if (!$sendCommand('EHLO ' . gethostname(), 250)) {
                fclose($socket);
                return false;
            }
        }

        // AUTH LOGIN
        if (!empty($user) && !empty($pass)) {
            if (!$sendCommand('AUTH LOGIN', 334)) {
                fclose($socket);
                return false;
            }
            if (!$sendCommand(base64_encode($user), 334)) {
                fclose($socket);
                return false;
            }
            if (!$sendCommand(base64_encode($pass), 235)) {
                fclose($socket);
                return false;
            }
        }

        // MAIL FROM
        if (!$sendCommand('MAIL FROM:<' . $fromEmail . '>', 250)) {
            fclose($socket);
            return false;
        }

        // RCPT TO
        if (!$sendCommand('RCPT TO:<' . $to . '>', 250)) {
            fclose($socket);
            return false;
        }

        // DATA
        if (!$sendCommand('DATA', 354)) {
            fclose($socket);
            return false;
        }

        // Build message
        $message = '';
        $message .= 'From: ' . formatEmailAddress($fromName, $fromEmail) . "\r\n";
        $message .= 'To: ' . $to . "\r\n";
        $message .= 'Reply-To: ' . $replyTo . "\r\n";
        $message .= 'Subject: ' . $subject . "\r\n";
        $message .= 'MIME-Version: 1.0' . "\r\n";
        $message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";
        $message .= 'X-Mailer: SafariTraveller/1.0' . "\r\n";
        $message .= "\r\n";
        $message .= buildMultipartBody($textBody, $htmlBody, $boundary);
        $message .= "\r\n.";

        if (!$sendCommand($message, 250)) {
            fclose($socket);
            return false;
        }

        // QUIT
        $sendCommand('QUIT', 221);
        fclose($socket);

        return true;

    } catch (\Exception $e) {
        error_log('[Safari Traveller] SMTP exception: ' . $e->getMessage());
        if (is_resource($socket)) {
            fclose($socket);
        }
        return false;
    }
}

/**
 * Send a templated outreach email to a property with audit data merged in.
 *
 * @param array  $property Property record from the properties table
 * @param array  $audit    Audit record from the property_audit table
 * @param string $template Email template body with {variable} placeholders
 * @return bool            True on success, false on failure
 */
function sendOutreachEmail(array $property, array $audit, string $template): bool
{
    // Determine recipient: use primary contact email
    $contactEmail = '';
    if (!empty($property['contact_email'])) {
        $contactEmail = $property['contact_email'];
    } else {
        // Look up from property_contacts table
        $contact = Database::fetch(
            'SELECT email FROM property_contacts WHERE property_id = ? AND is_primary = 1 LIMIT 1',
            [$property['id'] ?? 0]
        );
        if ($contact && !empty($contact['email'])) {
            $contactEmail = $contact['email'];
        }
    }

    if (empty($contactEmail)) {
        error_log('[Safari Traveller] sendOutreachEmail: No contact email for property #' . ($property['id'] ?? 'unknown'));
        return false;
    }

    // Prepare template variables
    $totalScore = (int) ($audit['total_score'] ?? 0);
    $maxScore   = 100;
    $scoreLabel = $totalScore >= 70 ? 'Strong' : ($totalScore >= 40 ? 'Moderate' : 'Needs Work');

    $vars = [
        'property_name'     => $property['name'] ?? '',
        'property_url'      => $property['website_url'] ?? '',
        'property_location' => $property['location'] ?? '',
        'property_country'  => $property['country'] ?? '',
        'property_type'     => $property['property_type'] ?? 'Lodge',
        'contact_name'      => $property['contact_name'] ?? 'Property Manager',
        'contact_email'     => $contactEmail,
        'total_score'       => $totalScore,
        'max_score'         => $maxScore,
        'score_label'       => $scoreLabel,
        'schema_score'      => $audit['schema_score'] ?? 0,
        'entity_score'      => $audit['entity_score'] ?? 0,
        'ai_score'          => $audit['ai_score'] ?? 0,
        'technical_score'   => $audit['technical_score'] ?? 0,
        'booking_score'     => $audit['booking_score'] ?? 0,
        'audit_date'        => $audit['created_at'] ?? date('Y-m-d'),
        'report_url'        => 'https://safari-traveller.com/public/audit-report.php?id=' . ($property['id'] ?? ''),
        'claim_url'         => 'https://safari-traveller.com/public/claim.php?id=' . ($property['id'] ?? ''),
        'listing_url'       => 'https://safari-traveller.com/public/property.php?id=' . ($property['id'] ?? ''),
        'site_name'         => 'Safari Traveller',
        'site_url'          => 'https://safari-traveller.com',
        'year'              => date('Y'),
    ];

    $subject  = replaceTemplateVars('{property_name} — Your AI Readiness Score: {total_score}/100', $vars);
    $htmlBody = replaceTemplateVars($template, $vars);

    $result = sendEmail($contactEmail, $subject, $htmlBody);

    // Log the outreach attempt
    try {
        Database::insert('outreach_queue', [
            'property_id' => $property['id'] ?? 0,
            'email'       => $contactEmail,
            'subject'     => $subject,
            'status'      => $result ? 'sent' : 'failed',
            'sent_at'     => $result ? date('Y-m-d H:i:s') : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {
        error_log('[Safari Traveller] sendOutreachEmail: Failed to log outreach — ' . $e->getMessage());
    }

    return $result;
}

/**
 * Send an enquiry notification email to the property contact.
 *
 * @param array $enquiry  Enquiry record from the enquiries table
 * @param array $property Property record from the properties table
 * @return bool           True on success, false on failure
 */
function sendEnquiryNotification(array $enquiry, array $property): bool
{
    // Find the property contact email
    $contactEmail = '';
    if (!empty($property['contact_email'])) {
        $contactEmail = $property['contact_email'];
    } else {
        $contact = Database::fetch(
            'SELECT email FROM property_contacts WHERE property_id = ? AND is_primary = 1 LIMIT 1',
            [$property['id'] ?? 0]
        );
        if ($contact && !empty($contact['email'])) {
            $contactEmail = $contact['email'];
        }
    }

    if (empty($contactEmail)) {
        error_log('[Safari Traveller] sendEnquiryNotification: No contact email for property #' . ($property['id'] ?? 'unknown'));
        return false;
    }

    $guestName  = htmlspecialchars($enquiry['name'] ?? 'A visitor', ENT_QUOTES, 'UTF-8');
    $guestEmail = htmlspecialchars($enquiry['email'] ?? '', ENT_QUOTES, 'UTF-8');
    $guestPhone = htmlspecialchars($enquiry['phone'] ?? '', ENT_QUOTES, 'UTF-8');
    $message    = nl2br(htmlspecialchars($enquiry['message'] ?? '', ENT_QUOTES, 'UTF-8'));
    $travelDate = htmlspecialchars($enquiry['travel_date'] ?? '', ENT_QUOTES, 'UTF-8');
    $guests     = htmlspecialchars($enquiry['guests'] ?? '', ENT_QUOTES, 'UTF-8');
    $propertyName = htmlspecialchars($property['name'] ?? 'Your Property', ENT_QUOTES, 'UTF-8');

    $subject = 'New Enquiry for ' . ($property['name'] ?? 'Your Property') . ' via Safari Traveller';

    $htmlBody = '
    <h2 style="color: #2C2C2C; margin-bottom: 8px;">New Guest Enquiry</h2>
    <p style="color: #6B6B6B; margin-bottom: 24px;">You have received a new enquiry for <strong>' . $propertyName . '</strong> via Safari Traveller.</p>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
        <tr>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; font-weight: 600; color: #6B6B6B; width: 140px;">Guest Name</td>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; color: #2C2C2C;">' . $guestName . '</td>
        </tr>
        <tr>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; font-weight: 600; color: #6B6B6B;">Email</td>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; color: #2C2C2C;"><a href="mailto:' . $guestEmail . '" style="color: #D4A84B;">' . $guestEmail . '</a></td>
        </tr>';

    if (!empty($enquiry['phone'])) {
        $htmlBody .= '
        <tr>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; font-weight: 600; color: #6B6B6B;">Phone</td>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; color: #2C2C2C;">' . $guestPhone . '</td>
        </tr>';
    }

    if (!empty($enquiry['travel_date'])) {
        $htmlBody .= '
        <tr>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; font-weight: 600; color: #6B6B6B;">Travel Dates</td>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; color: #2C2C2C;">' . $travelDate . '</td>
        </tr>';
    }

    if (!empty($enquiry['guests'])) {
        $htmlBody .= '
        <tr>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; font-weight: 600; color: #6B6B6B;">Guests</td>
            <td style="padding: 10px 12px; border-bottom: 1px solid #E0DDD5; color: #2C2C2C;">' . $guests . '</td>
        </tr>';
    }

    $htmlBody .= '
        <tr>
            <td style="padding: 10px 12px; font-weight: 600; color: #6B6B6B; vertical-align: top;">Message</td>
            <td style="padding: 10px 12px; color: #2C2C2C;">' . $message . '</td>
        </tr>
    </table>

    <p style="color: #6B6B6B; font-size: 14px;">Please reply directly to the guest at <a href="mailto:' . $guestEmail . '" style="color: #D4A84B;">' . $guestEmail . '</a>.</p>

    <hr style="border: none; border-top: 1px solid #E0DDD5; margin: 24px 0;">

    <p style="color: #999; font-size: 12px;">
        This enquiry was submitted via your listing on
        <a href="https://safari-traveller.com/public/property.php?id=' . ($property['id'] ?? '') . '" style="color: #D4A84B;">Safari Traveller</a>.
        If you believe this is spam, please ignore it.
    </p>';

    return sendEmail($contactEmail, $subject, $htmlBody);
}

/**
 * Send a verification email for the claim listing process.
 *
 * @param string $email    Recipient email address (must match property domain)
 * @param string $token    Unique verification token
 * @param array  $property Property record from the properties table
 * @return bool            True on success, false on failure
 */
function sendVerificationEmail(string $email, string $token, array $property): bool
{
    $propertyName = htmlspecialchars($property['name'] ?? 'Your Property', ENT_QUOTES, 'UTF-8');
    $verifyUrl    = 'https://safari-traveller.com/public/claim.php?verify=' . urlencode($token) . '&email=' . urlencode($email);

    $subject = 'Verify Your Claim for ' . ($property['name'] ?? 'Your Property') . ' on Safari Traveller';

    $htmlBody = '
    <h2 style="color: #2C2C2C; margin-bottom: 8px;">Verify Your Listing Claim</h2>
    <p style="color: #6B6B6B; margin-bottom: 24px;">
        You (or someone using this email address) requested to claim the listing for
        <strong>' . $propertyName . '</strong> on Safari Traveller.
    </p>

    <p style="color: #2C2C2C; margin-bottom: 24px;">
        Click the button below to verify your ownership and gain access to manage your listing,
        respond to enquiries, and improve your AI readiness score.
    </p>

    <div style="text-align: center; margin: 32px 0;">
        <a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '"
           style="display: inline-block; padding: 14px 32px; background-color: #D4A84B; color: #FFFFFF;
                  font-family: Poppins, sans-serif; font-size: 16px; font-weight: 600;
                  text-decoration: none; border-radius: 8px;">
            Verify My Claim
        </a>
    </div>

    <p style="color: #6B6B6B; font-size: 14px; margin-bottom: 16px;">
        Or copy and paste this URL into your browser:
    </p>
    <p style="color: #D4A84B; font-size: 13px; word-break: break-all; margin-bottom: 24px;">
        ' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '
    </p>

    <hr style="border: none; border-top: 1px solid #E0DDD5; margin: 24px 0;">

    <p style="color: #999; font-size: 12px;">
        This verification link will expire in 48 hours. If you did not request this, you can safely ignore this email.
    </p>';

    return sendEmail($email, $subject, $htmlBody);
}

/**
 * Read SMTP settings from the settings table in the database.
 *
 * Expected setting keys:
 *   smtp_host, smtp_port, smtp_user, smtp_pass, smtp_security,
 *   from_email, from_name, reply_to
 *
 * @return array Associative array of SMTP settings
 */
function getSmtpSettings(): array
{
    $defaults = [
        'smtp_host'     => '',
        'smtp_port'     => '',
        'smtp_user'     => '',
        'smtp_pass'     => '',
        'smtp_security' => '',
        'from_email'    => 'noreply@safari-traveller.com',
        'from_name'     => 'Safari Traveller',
        'reply_to'      => 'info@safari-traveller.com',
    ];

    try {
        $keys = array_keys($defaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        $rows = Database::fetchAll(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ({$placeholders})",
            $keys
        );

        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $defaults)) {
                $defaults[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (\Exception $e) {
        error_log('[Safari Traveller] getSmtpSettings: ' . $e->getMessage());
    }

    return $defaults;
}

/**
 * Replace {variable} placeholders in a template string with actual values.
 *
 * @param string $template Template string containing {variable} placeholders
 * @param array  $vars     Associative array of variable_name => value pairs
 * @return string          Template with placeholders replaced
 */
function replaceTemplateVars(string $template, array $vars): string
{
    foreach ($vars as $key => $value) {
        $template = str_replace('{' . $key . '}', (string) $value, $template);
    }
    return $template;
}

/**
 * Format an email address with a display name for the From: header.
 *
 * @param string $name  Display name
 * @param string $email Email address
 * @return string       Formatted email string, e.g. "Safari Traveller <noreply@safari-traveller.com>"
 */
function formatEmailAddress(string $name, string $email): string
{
    if (empty($name)) {
        return $email;
    }
    // Encode name if it contains special characters
    $name = str_replace(['"', '\\'], ['', ''], $name);
    return '"' . $name . '" <' . $email . '>';
}

/**
 * Build a multipart/alternative email body with plain-text and HTML parts.
 *
 * @param string $textBody Plain-text content
 * @param string $htmlBody HTML content
 * @param string $boundary MIME boundary string
 * @return string          Formatted multipart message body
 */
function buildMultipartBody(string $textBody, string $htmlBody, string $boundary): string
{
    $body = '';
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $body .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
    $body .= quoted_printable_encode($textBody) . "\r\n\r\n";

    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $body .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
    $body .= quoted_printable_encode($htmlBody) . "\r\n\r\n";

    $body .= '--' . $boundary . '--' . "\r\n";

    return $body;
}

/**
 * Wrap HTML email content in a styled email template with header and footer.
 *
 * @param string $content Inner HTML content
 * @return string         Complete HTML email template
 */
function wrapEmailTemplate(string $content): string
{
    $year = date('Y');

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safari Traveller</title>
</head>
<body style="margin: 0; padding: 0; background-color: #FAFAF5; font-family: Poppins, -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="width: 100%; background-color: #FAFAF5;">
        <tr>
            <td style="padding: 32px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%; margin: 0 auto;">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 24px 32px; background-color: #2C2C2C; border-radius: 12px 12px 0 0; text-align: center;">
                            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #FFFFFF; font-family: Poppins, sans-serif;">
                                Safari <span style="color: #D4A84B;">Traveller</span>
                            </h1>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 32px; background-color: #FFFFFF; font-size: 15px; line-height: 1.6; color: #2C2C2C;">
                            ' . $content . '
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 32px; background-color: #F5F5F0; border-radius: 0 0 12px 12px; text-align: center;">
                            <p style="margin: 0 0 8px 0; font-size: 12px; color: #999999;">
                                &copy; ' . $year . ' Safari Traveller. All rights reserved.
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #BBBBBB;">
                                Powered by <a href="https://safariweb.online" style="color: #D4A84B; text-decoration: none;">Safari Web Online</a>
                                &middot; Hoedspruit, South Africa
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}

/**
 * Convert HTML content to plain text for the text/plain email part.
 *
 * @param string $html HTML content
 * @return string      Plain-text version
 */
function htmlToPlainText(string $html): string
{
    // Replace common block-level tags with newlines
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/?(p|div|h[1-6]|li|tr|table|thead|tbody)[^>]*>/i', "\n", $text);
    $text = preg_replace('/<hr[^>]*>/i', "\n" . str_repeat('-', 50) . "\n", $text);

    // Convert links to [text](url) format
    $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', '$2 ($1)', $text);

    // Strip remaining HTML tags
    $text = strip_tags($text);

    // Decode HTML entities
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    // Collapse multiple blank lines into two
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    // Trim each line
    $lines = explode("\n", $text);
    $lines = array_map('trim', $lines);
    $text  = implode("\n", $lines);

    return trim($text);
}
