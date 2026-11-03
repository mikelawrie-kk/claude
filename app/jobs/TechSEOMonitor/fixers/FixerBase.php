<?php
/**
 * File: app/jobs/TechSEOMonitor/fixers/FixerBase.php
 * Description: Abstract parent for every TechSEOMonitor fixer module. A fixer takes a finding (produced by a check in Week 2) and generates a fix_payload_json — a JSON object of shape {mcp_tool, mcp_arguments} that ApplyRouter can execute verbatim via the site's MCP bridge. The fixer may need to read current site state (e.g. the existing homepage HTML to splice schema into) and the MCP bridge is already proven to work from CheckBase. Save the generated payload back to findings.fix_payload_json; status stays 'open' until Mike approves.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 14:00 SAST
 * Modified: 2026-11-03 14:00 SAST
 * Changes:
 *   1.0.0 (2026-11-03 14:00) — initial creation
 */

abstract class FixerBase
{
    protected array $site;
    protected ?array $entity;
    protected array $jobConfig;
    protected int $payloadsNew = 0;
    protected int $payloadsSkipped = 0;
    protected int $payloadsFailed = 0;
    protected static array $cache = [];

    public function __construct(array $site, ?array $entity, array $jobConfig = [])
    {
        $this->site = $site;
        $this->entity = $entity;
        $this->jobConfig = $jobConfig;
    }

    /** The check_type this fixer handles (must match a check from Week 2) */
    abstract public function handles(): string;

    /** Produce a fix_payload for the given finding, or null if this fixer can't fix it (diagnostic-only). */
    abstract public function generate(array $finding): ?array;

    public function stats(): array
    {
        return [
            'fixer' => $this->handles(),
            'payloads_new' => $this->payloadsNew,
            'payloads_skipped' => $this->payloadsSkipped,
            'payloads_failed' => $this->payloadsFailed,
        ];
    }

    /**
     * Attach the generated payload to the finding row.
     */
    public function savePayload(int $findingId, array $payload, ?int $estimatedSeconds = null): void
    {
        if (empty($payload['mcp_tool']) || !isset($payload['mcp_arguments'])) {
            $this->payloadsFailed++;
            Logger::warn('FixerBase refusing malformed payload', [
                'finding_id' => $findingId,
                'fixer' => $this->handles(),
                'payload_keys' => array_keys($payload),
            ]);
            return;
        }
        $db = DB::getInstance();
        $sql = 'UPDATE findings SET fix_payload_json = ?' .
            ($estimatedSeconds !== null ? ', fix_estimated_seconds = ?' : '') .
            ' WHERE id = ?';
        $params = $estimatedSeconds !== null
            ? [json_encode($payload, JSON_UNESCAPED_SLASHES), $estimatedSeconds, $findingId]
            : [json_encode($payload, JSON_UNESCAPED_SLASHES), $findingId];
        $db->execute($sql, $params);
        $this->payloadsNew++;
    }

    public function skip(int $findingId, string $reason): void
    {
        $this->payloadsSkipped++;
        Logger::info('FixerBase skipped finding', [
            'finding_id' => $findingId,
            'fixer' => $this->handles(),
            'reason' => $reason,
        ]);
    }

    protected function siteUrl(string $path = '/'): string
    {
        $domain = (string) $this->site['domain'];
        $path = '/' . ltrim($path, '/');
        return 'https://' . $domain . $path;
    }

    /**
     * Fetch current file content via the site's MCP bridge. Cached per-run.
     */
    protected function readSiteFile(string $relativePath): ?string
    {
        $key = $this->site['id'] . '|' . $relativePath;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        if (empty($this->site['mcp_bridge_url'])) {
            return null;
        }
        try {
            $args = ['path' => ltrim($relativePath, '/')];
            $project = (string) ($this->site['mcp_project'] ?? '');
            if ($project !== '') {
                $args['project'] = $project;
            }
            $result = MCPClient::call(
                (string) $this->site['mcp_bridge_url'],
                (string) ($this->site['mcp_bridge_key'] ?? ''),
                'cpanel_read_file',
                $args
            );
            $text = MCPClient::extractText($result);
            // bridge wraps the file content; a JSON envelope has a 'content' field
            $decoded = json_decode($text, true);
            if (is_array($decoded) && isset($decoded['content'])) {
                $content = (string) $decoded['content'];
            } else {
                $content = $text;
            }
            self::$cache[$key] = $content;
            return $content;
        } catch (Throwable $e) {
            Logger::warn('FixerBase readSiteFile failed', [
                'path' => $relativePath,
                'err' => $e->getMessage(),
            ]);
            self::$cache[$key] = null;
            return null;
        }
    }

    /** The MCP write payload every fixer builds for a whole-file replacement. */
    protected function writeFilePayload(string $relativePath, string $content): array
    {
        $args = [
            'path' => ltrim($relativePath, '/'),
            'content' => $content,
            'create_backup' => true,
        ];
        $project = (string) ($this->site['mcp_project'] ?? '');
        if ($project !== '') {
            $args['project'] = $project;
        }
        return [
            'mcp_tool' => 'cpanel_write_file',
            'mcp_arguments' => $args,
        ];
    }

    /** The MCP append payload for additive changes. */
    protected function appendFilePayload(string $relativePath, string $suffix): array
    {
        $args = [
            'path' => ltrim($relativePath, '/'),
            'content' => $suffix,
            'append' => true,
            'create_backup' => true,
        ];
        $project = (string) ($this->site['mcp_project'] ?? '');
        if ($project !== '') {
            $args['project'] = $project;
        }
        return [
            'mcp_tool' => 'cpanel_write_file',
            'mcp_arguments' => $args,
        ];
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
