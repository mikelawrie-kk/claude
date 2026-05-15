<?php
/**
 * File: app/core/Reporter.php
 * Description: Email sender for Pulse. Brand-clean HTML, inline CSS, max 600px. Uses PHP mail() or SMTP per config.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 09:25 SAST
 * Modified: 2026-05-14 09:25 SAST
 * Changes:
 *   1.0.0 (2026-05-14 09:25) — initial creation
 */

class Reporter
{
    private const ACCENT = '#D4A24C';
    private const BG = '#FFF8F0';
    private const TEXT = '#2A2A2A';
    private const MUTED = '#6B6B6B';
    private const FONT = "'Poppins', -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif";

    public static function send(string $to, string $subject, string $bodyHtml, string $bodyText = ''): bool
    {
        $config = self::config();
        $fromEmail = $config['mail_from'] ?? 'pulse@pulse.safariweb.online';
        $fromName = $config['mail_from_name'] ?? 'SWO Pulse';

        if (Guardrails::dryRun()) {
            Logger::info('Reporter dry-run skip', ['to' => $to, 'subject' => $subject]);
            return true;
        }

        $method = $config['mail_method'] ?? 'php_mail';
        if ($method === 'smtp') {
            return self::sendSmtp($config, $to, $subject, $bodyHtml, $bodyText, $fromEmail, $fromName);
        }
        return self::sendPhpMail($to, $subject, $bodyHtml, $bodyText, $fromEmail, $fromName);
    }

    public static function template(string $title, string $intro, string $bodyHtml, string $footer = ''): string
    {
        $bg = self::BG;
        $accent = self::ACCENT;
        $text = self::TEXT;
        $muted = self::MUTED;
        $font = self::FONT;
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $introEsc = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
        $footerEsc = $footer === '' ? '' : htmlspecialchars($footer, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$titleEsc}</title>
</head>
<body style="margin:0;padding:0;background:{$bg};font-family:{$font};color:{$text};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$bg};padding:32px 16px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#FFFFFF;border:1px solid #EEE3D0;border-radius:6px;">
      <tr><td style="padding:32px 32px 16px 32px;border-bottom:3px solid {$accent};">
        <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;color:{$accent};font-weight:600;">SWO Pulse</div>
        <h1 style="margin:8px 0 0 0;font-size:22px;font-weight:600;color:{$text};">{$titleEsc}</h1>
      </td></tr>
      <tr><td style="padding:24px 32px 8px 32px;font-size:15px;line-height:1.6;color:{$text};">{$introEsc}</td></tr>
      <tr><td style="padding:8px 32px 24px 32px;font-size:14px;line-height:1.6;color:{$text};">{$bodyHtml}</td></tr>
      <tr><td style="padding:16px 32px;border-top:1px solid #EEE3D0;font-size:12px;color:{$muted};">
        {$footerEsc}
        <div style="margin-top:8px;">&copy; {$year} Safari Web Online &middot; Pulse v1 &middot; <a href="https://pulse.safariweb.online" style="color:{$accent};text-decoration:none;">pulse.safariweb.online</a></div>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    private static function sendPhpMail(string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
    {
        $boundary = '=_Pulse_' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . self::encodeAddr($fromName, $fromEmail),
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Pulse/1.0',
        ];
        $textPart = $text !== '' ? $text : strip_tags($html);
        $body = "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $textPart . "\r\n\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n\r\n"
            . "--$boundary--";
        $ok = @mail($to, self::encodeSubject($subject), $body, implode("\r\n", $headers));
        if (!$ok) {
            Logger::error('Reporter php_mail failed', ['to' => $to, 'subject' => $subject]);
        }
        return $ok;
    }

    private static function sendSmtp(array $config, string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
    {
        $smtp = $config['smtp'] ?? [];
        $host = $smtp['host'] ?? '';
        $port = (int) ($smtp['port'] ?? 587);
        $user = $smtp['username'] ?? '';
        $pass = $smtp['password'] ?? '';
        $secure = $smtp['secure'] ?? 'tls';
        if ($host === '') {
            Logger::error('Reporter SMTP missing host');
            return false;
        }

        $transport = $secure === 'ssl' ? 'ssl://' . $host : $host;
        $fp = @stream_socket_client("$transport:$port", $errno, $errstr, 15);
        if (!$fp) {
            Logger::error('Reporter SMTP connect failed', ['err' => $errstr]);
            return false;
        }
        stream_set_timeout($fp, 15);

        $expect = function (string $code) use ($fp): string {
            $line = '';
            while (($l = fgets($fp, 1024)) !== false) {
                $line .= $l;
                if (strlen($l) >= 4 && $l[3] === ' ') {
                    break;
                }
            }
            if (substr($line, 0, 3) !== $code) {
                throw new RuntimeException("SMTP expected $code got: " . trim($line));
            }
            return $line;
        };
        $send = function (string $cmd) use ($fp): void {
            fwrite($fp, $cmd . "\r\n");
        };

        try {
            $expect('220');
            $send('EHLO pulse.safariweb.online');
            $expect('250');
            if ($secure === 'tls') {
                $send('STARTTLS');
                $expect('220');
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $send('EHLO pulse.safariweb.online');
                $expect('250');
            }
            if ($user !== '') {
                $send('AUTH LOGIN');
                $expect('334');
                $send(base64_encode($user));
                $expect('334');
                $send(base64_encode($pass));
                $expect('235');
            }
            $send('MAIL FROM:<' . $fromEmail . '>');
            $expect('250');
            $send('RCPT TO:<' . $to . '>');
            $expect('250');
            $send('DATA');
            $expect('354');

            $boundary = '=_Pulse_' . bin2hex(random_bytes(8));
            $textPart = $text !== '' ? $text : strip_tags($html);
            $msg = 'From: ' . self::encodeAddr($fromName, $fromEmail) . "\r\n"
                . 'To: ' . $to . "\r\n"
                . 'Subject: ' . self::encodeSubject($subject) . "\r\n"
                . 'MIME-Version: 1.0' . "\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n"
                . "--$boundary\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $textPart . "\r\n"
                . "--$boundary\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $html . "\r\n"
                . "--$boundary--\r\n";
            fwrite($fp, $msg . "\r\n.\r\n");
            $expect('250');
            $send('QUIT');
            fclose($fp);
            return true;
        } catch (Throwable $e) {
            Logger::error('Reporter SMTP failed', ['err' => $e->getMessage()]);
            @fclose($fp);
            return false;
        }
    }

    private static function encodeAddr(string $name, string $email): string
    {
        return '"' . addslashes($name) . '" <' . $email . '>';
    }

    private static function encodeSubject(string $subject): string
    {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    private static function config(): array
    {
        $path = dirname(__DIR__) . '/config/pulse.config.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }
}
