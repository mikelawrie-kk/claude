<?php
/**
 * File: app/core/APIClient.php
 * Description: Generic HTTP client wrapper (curl-based). Exponential backoff on 429/5xx, max 5 retries, cost logging hook.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 09:18 SAST
 * Modified: 2026-05-14 09:18 SAST
 * Changes:
 *   1.0.0 (2026-05-14 09:18) — initial creation (vanilla curl; Guzzle deferred until composer available on cPanel)
 */

class APIClient
{
    public const MAX_RETRIES = 5;
    public const DEFAULT_TIMEOUT = 60;
    public const BACKOFF_BASE_MS = 500;

    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = self::DEFAULT_TIMEOUT,
        float $costUsd = 0.0
    ): array {
        if (Guardrails::dryRun()) {
            Logger::info('APIClient dry-run skip', ['method' => $method, 'url' => self::redact($url)]);
            return ['status' => 0, 'body' => '', 'dry_run' => true];
        }

        $attempt = 0;
        $lastErr = null;
        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => self::flattenHeaders($headers),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Pulse/1.0 (+https://pulse.safariweb.online)',
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $respBody = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($respBody === false) {
                $lastErr = "curl error: $err";
                Logger::warn('APIClient curl error', ['attempt' => $attempt, 'url' => self::redact($url), 'err' => $err]);
                self::backoff($attempt);
                continue;
            }

            if ($status === 429 || ($status >= 500 && $status <= 599)) {
                $lastErr = "HTTP $status";
                Logger::warn('APIClient retriable status', ['attempt' => $attempt, 'status' => $status, 'url' => self::redact($url)]);
                self::backoff($attempt);
                continue;
            }

            if ($costUsd > 0) {
                Guardrails::addCost($costUsd);
            }

            return ['status' => $status, 'body' => $respBody, 'attempts' => $attempt];
        }

        throw new RuntimeException('APIClient exhausted retries: ' . $lastErr);
    }

    public static function getJson(string $url, array $headers = [], float $costUsd = 0.0): array
    {
        $h = $headers + ['Accept' => 'application/json'];
        $res = self::request('GET', $url, $h, null, self::DEFAULT_TIMEOUT, $costUsd);
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('APIClient invalid JSON from ' . self::redact($url));
        }
        return $data;
    }

    public static function postJson(string $url, array $payload, array $headers = [], float $costUsd = 0.0): array
    {
        $h = $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $res = self::request('POST', $url, $h, json_encode($payload), self::DEFAULT_TIMEOUT, $costUsd);
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('APIClient invalid JSON from ' . self::redact($url));
        }
        return $data;
    }

    private static function flattenHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = is_int($k) ? $v : ($k . ': ' . $v);
        }
        return $out;
    }

    private static function backoff(int $attempt): void
    {
        $ms = self::BACKOFF_BASE_MS * (2 ** ($attempt - 1));
        $jitter = random_int(0, (int) ($ms * 0.25));
        usleep(($ms + $jitter) * 1000);
    }

    private static function redact(string $url): string
    {
        return preg_replace('/([?&](?:key|api[_-]?key|token|secret)=)[^&#]+/i', '$1***', $url) ?? $url;
    }
}
