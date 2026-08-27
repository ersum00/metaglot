<?php

declare(strict_types=1);

namespace Metaglot\Tests\Auth;

use Metaglot\Auth\TokenProvider;
use Metaglot\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TokenProviderTest extends TestCase
{
    public function testInvalidGrantExplainsTestingModeTokenExpiry(): void
    {
        // Regression: the invalid_grant message must tell the user that a
        // consent screen left in "Testing" mode kills refresh tokens after
        // 7 days and that it must be switched to "In production".
        $http = $this->createMock(Http::class);
        $http->method('request')->willReturn([400, ['error' => 'invalid_grant']]);

        $provider = new TokenProvider($http);

        try {
            $provider->accessToken([
                'id'            => 1,
                'label'         => 'acme',
                'client_id'     => 'dummy',
                'client_secret' => 'dummy',
                'refresh_token' => 'dummy',
            ]);
            $this->fail('Expected a RuntimeException for invalid_grant');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("'Testing' mode", $e->getMessage());
            $this->assertStringContainsString('7 days', $e->getMessage());
            $this->assertStringContainsString("'In production'", $e->getMessage());
        }
    }
}
