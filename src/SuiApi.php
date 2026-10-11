<?php
/**
 * S-UI REST API (v2) client.
 *
 * Every call returns the decoded "obj" payload (array) on success, or null on
 * failure. On failure, $lastError / $lastHttpCode describe what went wrong.
 *
 * Authentication: the panel's API token is sent in the "Token" HTTP header.
 */

declare(strict_types=1);

namespace SuiMonitor;

final class SuiApi
{
    private string $baseUrl;
    private string $token;
    private bool   $verifySsl;
    private int    $timeout;
    private int    $maxRetries;
    private string $serverName;

    public ?string $lastError    = null;
    public ?int    $lastHttpCode = null;

    /**
     * @param array{base_url?:string,token?:string,verify_ssl?:bool} $cfg
     */
    public function __construct(string $name, array $cfg, int $timeout = 10, int $maxRetries = 2)
    {
        $this->serverName = $name;
        $this->baseUrl    = rtrim((string)($cfg['base_url'] ?? ''), '/');
        $this->token      = (string)($cfg['token'] ?? '');
        $this->verifySsl  = (bool)($cfg['verify_ssl'] ?? true);
        $this->timeout    = max(3, $timeout);
        $this->maxRetries = max(0, $maxRetries);
    }

    // ── Public endpoints ───────────────────────────────────

    /** @return array<string,mixed>|null */
    public function status(): ?array
    {
        return $this->request('status', ['r' => 'cpu,mem,swp,dsk,dio,net,sys,sbd,db']);
    }

    /** @return array<mixed>|null */
    public function onlines(): ?array   { return $this->request('onlines'); }
    /** @return array<mixed>|null */
    public function sessions(): ?array  { return $this->request('sessions'); }
    /** @return array<mixed>|null */
    public function inbounds(): ?array  { return $this->request('inbounds'); }
    /** @return array<mixed>|null */
    public function outbounds(): ?array { return $this->request('outbounds'); }
    /** @return array<mixed>|null */
    public function endpoints(): ?array { return $this->request('endpoints'); }
    /** @return array<mixed>|null */
    public function services(): ?array  { return $this->request('services'); }
    /** @return array<mixed>|null */
    public function clients(): ?array   { return $this->request('clients'); }

    // ── Request helper ─────────────────────────────────────

    /**
     * @param  array<string,scalar> $query
     * @return array<mixed>|null
     */
    private function request(string $endpoint, array $query = []): ?array
    {
        $this->lastError    = null;
        $this->lastHttpCode = null;

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $attempt = 0;
        while ($attempt <= $this->maxRetries) {
            $attempt++;
            $canRetry = $attempt <= $this->maxRetries;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Token: ' . $this->token, 'Accept: application/json'],
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
                CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
                CURLOPT_USERAGENT      => 'SUI-Monitoring-Panel/' . App::version(),
            ]);

            $raw   = curl_exec($ch);
            $err   = curl_error($ch);
            $errNo = curl_errno($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);

            $this->lastHttpCode = $code;

            // Transport-level failure (DNS, TCP, TLS, timeout): retry.
            if ($raw === false || $err !== '') {
                $this->lastError = "cURL ({$errNo}): {$err}";
                Logger::warning('API cURL error', [
                    'server' => $this->serverName, 'endpoint' => $endpoint,
                    'errno'  => $errNo, 'error' => $err, 'attempt' => $attempt,
                ]);
                if ($canRetry) {
                    usleep(300_000 * $attempt);
                    continue;
                }
                return null;
            }

            // Server-side failure (5xx): retry. Client errors (401, 404, ...): do not.
            if ($code >= 500) {
                $this->lastError = "HTTP {$code}";
                Logger::warning('API HTTP error', ['server' => $this->serverName, 'endpoint' => $endpoint, 'code' => $code]);
                if ($canRetry) {
                    usleep(300_000 * $attempt);
                    continue;
                }
                return null;
            }

            if ($code !== 200) {
                $this->lastError = "HTTP {$code}";
                Logger::warning('API HTTP error', ['server' => $this->serverName, 'endpoint' => $endpoint, 'code' => $code]);
                return null;
            }

            $json = json_decode((string)$raw, true);
            if (!is_array($json)) {
                $this->lastError = 'Invalid JSON';
                Logger::warning('API invalid JSON', ['server' => $this->serverName, 'endpoint' => $endpoint]);
                return null;
            }

            if (empty($json['success'])) {
                $this->lastError = 'API: ' . (string)($json['msg'] ?? 'unknown');
                Logger::warning('API returned success=false', [
                    'server' => $this->serverName, 'endpoint' => $endpoint, 'msg' => (string)($json['msg'] ?? ''),
                ]);
                return null;
            }

            Logger::debug('API OK', ['server' => $this->serverName, 'endpoint' => $endpoint]);

            return is_array($json['obj'] ?? null) ? $json['obj'] : [];
        }

        return null;
    }
}
