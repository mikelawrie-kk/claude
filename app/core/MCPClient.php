<?php
/**
 * File: app/core/MCPClient.php
 * Description: JSON-RPC 2.0 client for MCP bridges (Aerotel, SWO Web, WAH). Builds tools/call requests, parses results, throws on error.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 09:35 SAST
 * Modified: 2026-05-14 09:35 SAST
 * Changes:
 *   1.0.0 (2026-05-14 09:35) — initial creation
 */

class MCPClient
{
    public static function call(string $bridgeUrl, string $bridgeKey, string $toolName, array $arguments = []): array
    {
        $url = self::buildUrl($bridgeUrl, $bridgeKey);
        $payload = [
            'jsonrpc' => '2.0',
            'id' => (int) (microtime(true) * 1000),
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => (object) $arguments,
            ],
        ];

        if (Guardrails::dryRun()) {
            Logger::info('MCPClient dry-run skip', [
                'bridge' => self::redact($bridgeUrl),
                'tool' => $toolName,
            ]);
            return ['dry_run' => true, 'tool' => $toolName, 'arguments' => $arguments];
        }

        Logger::info('MCPClient call', [
            'bridge' => self::redact($bridgeUrl),
            'tool' => $toolName,
        ]);

        $res = APIClient::request(
            'POST',
            $url,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );

        $status = $res['status'] ?? 0;
        $body = $res['body'] ?? '';
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("MCP HTTP $status from $toolName: " . substr($body, 0, 500));
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException("MCP invalid JSON from $toolName: " . substr($body, 0, 300));
        }
        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? json_encode($data['error']) : (string) $data['error'];
            throw new RuntimeException("MCP error for $toolName: $msg");
        }
        if (!isset($data['result'])) {
            throw new RuntimeException("MCP missing result for $toolName: " . substr($body, 0, 300));
        }

        $result = $data['result'];
        if (!empty($result['isError'])) {
            $msg = '';
            if (isset($result['content']) && is_array($result['content'])) {
                foreach ($result['content'] as $c) {
                    if (isset($c['text'])) {
                        $msg .= $c['text'] . "\n";
                    }
                }
            }
            throw new RuntimeException("MCP tool $toolName returned isError: " . trim($msg));
        }

        return $result;
    }

    public static function extractText(array $result): string
    {
        if (!isset($result['content']) || !is_array($result['content'])) {
            return '';
        }
        $out = '';
        foreach ($result['content'] as $c) {
            if (isset($c['type']) && $c['type'] === 'text' && isset($c['text'])) {
                $out .= $c['text'];
            }
        }
        return $out;
    }

    private static function buildUrl(string $bridgeUrl, string $bridgeKey): string
    {
        if ($bridgeKey === '') {
            return $bridgeUrl;
        }
        $sep = strpos($bridgeUrl, '?') === false ? '?' : '&';
        return $bridgeUrl . $sep . 'key=' . urlencode($bridgeKey);
    }

    private static function redact(string $url): string
    {
        return preg_replace('/([?&]key=)[^&#]+/i', '$1***', $url) ?? $url;
    }
}
