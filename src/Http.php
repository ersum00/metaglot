<?php

declare(strict_types=1);

namespace Metaglot;

use RuntimeException;

/**
 * Thin cURL wrapper.
 */
final class Http
{
    /**
     * @param array<string> $headers
     *
     * @return array{int, mixed} [HTTP status, decoded JSON body]
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
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
        curl_close($ch);

        if (!is_string($raw)) {
            throw new RuntimeException("cURL error: $err");
        }

        return [$status, json_decode($raw, true) ?? ['raw' => $raw]];
    }
}
