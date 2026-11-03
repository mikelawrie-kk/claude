<?php
/**
 * File: app/jobs/TechSEOMonitor/checks/CheckBase.php
 * Description: Abstract parent for every TechSEOMonitor check module. Provides upsertFinding() (dedupes by site+check+url+issue), fetchUrl() (HEAD/GET via APIClient with cache), html parser helpers, and a per-run stats accumulator.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 09:15 SAST
 * Modified: 2026-11-03 09:15 SAST
 * Changes:
 *   1.0.0 (2026-11-03 09:15) — initial creation
 */

abstract class CheckBase
{
    protected int $runId;
    protected int $siteId;
    protected array $site;
    protected ?array $entity;
    protected array $jobConfig;
    protected int $findingsNew = 0;
    protected int $findingsUpdated = 0;
    protected int $findingsFailed = 0;
    protected float $costUsd = 0.0;
    protected static array $urlCache = [];

    public function __construct(int $runId, int $siteId, array $site, ?array $entity, array $jobConfig = [])
    {
        $this->runId = $runId;
        $this->siteId = $siteId;
        $this->site = $site;
        $this->entity = $entity;
        $this->jobConfig = $jobConfig;
    }

    abstract public function checkName(): string;
    abstract public function run(): array;

    public function stats(): array
    {
        return [
            'check' => $this->checkName(),
            'findings_new' => $this->findingsNew,
            'findings_updated' => $this->findingsUpdated,
            'findings_failed' => $this->findingsFailed,
            'cost_usd' => $this->costUsd,
        ];
    }

    /**
     * Insert or refresh a finding. Dedupes by (site, check_type, url, issue).
     */
    protected function upsertFinding(array $f): void
    {
        if (empty($f['recommendation'])) {
            Logger::warn('CheckBase refusing finding without recommendation (A2)', [
                'check' => $this->checkName(),
                'issue' => $f['issue'] ?? '',
            ]);
            $this->findingsFailed++;
            return;
        }

        $db = DB::getInstance();
        $url = (string) ($f['url'] ?? $this->site['domain']);
        $issue = (string) $f['issue'];
        $existing = $db->fetchOne(
            'SELECT id, status FROM findings WHERE site_id = ? AND check_type = ? AND url = ? AND issue = ?',
            [$this->siteId, $this->checkName(), $url, $issue]
        );

        if ($existing) {
            $db->execute(
                'UPDATE findings SET last_seen = CURRENT_TIMESTAMP, severity = ?, recommendation = ?, fix_target_path = ?, run_id = ? WHERE id = ?',
                [
                    $f['severity'] ?? 'info',
                    $f['recommendation'],
                    $f['fix_target_path'] ?? null,
                    $this->runId,
                    $existing['id'],
                ]
            );
            $this->findingsUpdated++;
            return;
        }

        $db->execute(
            'INSERT INTO findings
                (site_id, run_id, check_type, url, severity, issue, recommendation,
                 fix_payload_json, fix_target_path, fix_estimated_seconds, status, last_seen)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [
                $this->siteId,
                $this->runId,
                $this->checkName(),
                $url,
                $f['severity'] ?? 'info',
                $issue,
                $f['recommendation'],
                isset($f['fix_payload']) ? json_encode($f['fix_payload'], JSON_UNESCAPED_SLASHES) : null,
                $f['fix_target_path'] ?? null,
                $f['fix_estimated_seconds'] ?? null,
                'open',
            ]
        );
        $this->findingsNew++;
    }

    /**
     * Fetch a URL. Returns ['status' => int, 'body' => string, 'headers' => array, 'final_url' => string]
     * or null on hard failure. Results are cached per-run for identical URLs.
     */
    protected function fetchUrl(string $url, string $method = 'GET', int $timeout = 20): ?array
    {
        $key = $method . ' ' . $url;
        if (isset(self::$urlCache[$key])) {
            return self::$urlCache[$key];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'PulseBot/1.0 (+https://pulse.safariweb.online)',
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            Logger::warn('CheckBase fetchUrl failed', ['url' => $url, 'err' => $err]);
            self::$urlCache[$key] = null;
            return null;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = [];
        foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }

        $result = [
            'status' => $status,
            'body' => $body,
            'headers' => $headers,
            'final_url' => $finalUrl,
        ];
        self::$urlCache[$key] = $result;
        return $result;
    }

    protected function siteUrl(string $path = '/'): string
    {
        $domain = (string) $this->site['domain'];
        $path = '/' . ltrim($path, '/');
        return 'https://' . $domain . $path;
    }

    protected function isSameHost(string $url): bool
    {
        $domain = strtolower((string) $this->site['domain']);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return true;
        }
        return $host === $domain || $host === 'www.' . $domain || 'www.' . $host === $domain;
    }

    protected function parseHtml(string $html): ?DOMDocument
    {
        if ($html === '') {
            return null;
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $dom;
    }

    protected function entity(string $key, $default = null)
    {
        if (!is_array($this->entity)) {
            return $default;
        }
        return $this->entity[$key] ?? $default;
    }

    protected function entityJson(string $key, $default = null)
    {
        $raw = $this->entity($key . '_json');
        if (!is_string($raw) || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return $decoded ?? $default;
    }
}
