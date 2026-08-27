<?php

declare(strict_types=1);

namespace Metaglot\Tests;

use Closure;
use Metaglot\Tests\Support\ScriptedHttp;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->sleeps = [];
    }

    private function sleeper(): Closure
    {
        return function (int $seconds): void {
            $this->sleeps[] = $seconds;
        };
    }

    public function testRetriesServerErrorsWithExponentialBackoff(): void
    {
        $http = new ScriptedHttp([500, 502, 200], $this->sleeper());
        [$status] = $http->request('GET', 'http://example.test');

        $this->assertSame(200, $status);
        $this->assertSame(3, $http->calls);
        $this->assertSame([1, 4], $this->sleeps);
    }

    public function testGivesUpAfterThreeRetries(): void
    {
        $http = new ScriptedHttp([500, 500, 500, 500, 500], $this->sleeper());
        [$status] = $http->request('GET', 'http://example.test');

        $this->assertSame(500, $status);
        $this->assertSame(4, $http->calls); // 1 initial attempt + 3 retries
        $this->assertSame([1, 4, 16], $this->sleeps);
    }

    public function testRetriesRateLimiting(): void
    {
        $http = new ScriptedHttp([429, 200], $this->sleeper());
        [$status] = $http->request('GET', 'http://example.test');

        $this->assertSame(200, $status);
        $this->assertSame(2, $http->calls);
        $this->assertSame([1], $this->sleeps);
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $http = new ScriptedHttp([404], $this->sleeper());
        [$status] = $http->request('GET', 'http://example.test');

        $this->assertSame(404, $status);
        $this->assertSame(1, $http->calls);
        $this->assertSame([], $this->sleeps);
    }
}
