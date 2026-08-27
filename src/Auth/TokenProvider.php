<?php

declare(strict_types=1);

namespace Metaglot\Auth;

use Metaglot\Http;
use RuntimeException;

/**
 * Exchanges a channel's OAuth refresh token for an access token,
 * cached per channel for the lifetime of the process.
 *
 * WARNING: if the OAuth consent screen is left in "Testing" mode, Google
 * invalidates the refresh token after 7 days and you get invalid_grant.
 * When setting up a client, have the consent screen switched to
 * "In production".
 */
class TokenProvider
{
    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';

    /** @var array<int, string> */
    private array $cache = [];

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * @param array<string, mixed> $channel
     */
    public function accessToken(array $channel): string
    {
        $id = (int) $channel['id'];
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        [$status, $res] = $this->http->request(
            'POST',
            self::OAUTH_URL,
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'client_id'     => $channel['client_id'],
                'client_secret' => $channel['client_secret'],
                'refresh_token' => $channel['refresh_token'],
                'grant_type'    => 'refresh_token',
            ]),
        );

        if ($status !== 200 || empty($res['access_token'])) {
            $error = $res['error'] ?? 'unknown';
            $label = (string) $channel['label'];
            if ($error === 'invalid_grant') {
                throw new RuntimeException(
                    "Refresh token is invalid (channel $label). " .
                    "If the OAuth consent screen is in 'Testing' mode the token expires after 7 days — " .
                    "re-authorize the channel and switch the consent screen to 'In production'."
                );
            }
            throw new RuntimeException('Could not refresh access token: ' . (string) json_encode($error));
        }

        return $this->cache[$id] = (string) $res['access_token'];
    }
}
