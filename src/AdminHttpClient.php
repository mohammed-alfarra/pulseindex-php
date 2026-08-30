<?php

declare(strict_types=1);

namespace PulseIndex;

use PulseIndex\Exception\PulseIndexException;

/**
 * Minimal client for the engine's admin HTTP port (separate from gRPC).
 *
 * Only the operations the SDK needs — currently `POST /recovery/reindex-complete`,
 * used by `php artisan pulse:reindex --recovery` once a full re-index is done.
 * Uses ext-curl directly to avoid pulling an HTTP client dependency.
 *
 * Not `final` so it can be mocked / swapped in the container for tests.
 */
class AdminHttpClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $internalToken = null,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * Derive the admin base URL from a gRPC `host:port` by swapping the port,
     * unless an explicit URL is given.
     */
    public static function fromConfig(?string $adminUrl, string $grpcHost, int $adminPort, ?string $token): self
    {
        if (is_string($adminUrl) && $adminUrl !== '') {
            return new self(rtrim($adminUrl, '/'), $token);
        }

        $hostname = str_contains($grpcHost, ':') ? explode(':', $grpcHost, 2)[0] : $grpcHost;
        $scheme = $adminPort === 443 ? 'https' : 'http';

        return new self("{$scheme}://{$hostname}:{$adminPort}", $token);
    }

    /**
     * Clear the engine's degraded-recovery flag after a completed full re-index.
     *
     * @throws PulseIndexException on a non-2xx response or transport failure.
     */
    public function markReindexComplete(): void
    {
        [$status, $body] = $this->post('/recovery/reindex-complete');

        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new PulseIndexException(match ($status) {
            401 => 'engine rejected the internal token (set PULSEINDEX_ENGINE_INTERNAL_TOKEN)',
            404 => 'engine admin endpoint not found — control-plane auth is likely disabled on the engine',
            409 => 'engine reports the index is still empty; nothing was indexed',
            0 => "could not reach the engine admin endpoint: {$body}",
            default => "engine returned HTTP {$status}: {$body}",
        });
    }

    /**
     * @return array{0: int, 1: string} status code (0 on transport failure) and body
     */
    private function post(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            return [0, 'curl_init failed'];
        }

        $headers = ['Content-Length: 0'];
        if (is_string($this->internalToken) && $this->internalToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->internalToken;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [0, $error !== '' ? $error : 'transport failure'];
        }

        return [$status, trim((string) $body)];
    }
}
