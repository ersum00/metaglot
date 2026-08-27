<?php

declare(strict_types=1);

namespace Metaglot;

use Closure;
use RuntimeException;

/**
 * Thin cURL wrapper with retry.
 *
 * 429 and 5xx responses are retried with exponential backoff (3 retries,
 * waiting 1s/4s/16s). Other 4xx responses are permanent failures and are
 * returned to the caller immediately.
 */
class Http
{
    private const RETRY_DELAYS = [1, 4, 16];

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param (Closure(int): void)|null $sleep injectable for tests; defaults to sleep()
     */
    public function __construct(?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<string> $headers
     *
     * @return array{int, mixed} [HTTP status, decoded JSON body]
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $attempt = 0;
        while (true) {
            $response = $this->send($method, $url, $headers, $body);
            if (!self::isRetryable($response[0]) || $attempt >= count(self::RETRY_DELAYS)) {
                return $response;
            }
            ($this->sleep)(self::RETRY_DELAYS[$attempt]);
            $attempt++;
        }
    }

    private static function isRetryable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Performs a single HTTP exchange.
     *
     * @param array<string> $headers
     *
     * @return array{int, mixed} [HTTP status, decoded JSON body]
     */
    protected function send(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Could not initialise cURL for $url");
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);

        if (!is_string($raw)) {
            throw new RuntimeException("cURL error: $err");
        }

        return [$status, json_decode($raw, true) ?? ['raw' => $raw]];
    }
}
