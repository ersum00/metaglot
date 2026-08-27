<?php

declare(strict_types=1);

namespace Metaglot\Tests\YouTube;

use Metaglot\Auth\TokenProvider;
use Metaglot\Database;
use Metaglot\Http;
use Metaglot\Quota\QuotaExhaustedException;
use Metaglot\Quota\QuotaMeter;
use Metaglot\YouTube\ApiClient;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sentBody = [];

    protected function setUp(): void
    {
        $this->sentBody = [];
    }

    private function client(): ApiClient
    {
        $http = $this->createMock(Http::class);
        $http->method('request')->willReturnCallback(
            function (string $method, string $url, array $headers = [], ?string $body = null): array {
                $this->sentBody = $body === null ? [] : (array) json_decode($body, true);

                return [200, []];
            }
        );
        $tokens = $this->createMock(TokenProvider::class);
        $tokens->method('accessToken')->willReturn('test-token');

        return new ApiClient(
            $http,
            $tokens,
            $this->createMock(QuotaMeter::class),
            $this->createMock(Database::class),
        );
    }

    /** @return array<string, mixed> */
    private static function channel(): array
    {
        return ['id' => 1, 'label' => 'acme', 'source_lang' => 'tr', 'daily_quota' => 10000];
    }

    /** @return array<string, array{title: string, description: string}> */
    private static function localizations(): array
    {
        return ['en' => ['title' => 'T', 'description' => 'D']];
    }

    public function testSnippetIsSentCompleteWithCategoryIdAndTags(): void
    {
        // Regression: videos.update with part=snippet must always carry
        // categoryId and tags — YouTube deletes any field missing from the request.
        $video = [
            'id'      => 'vid1',
            'snippet' => [
                'title'       => 'Title',
                'description' => 'Description',
                'categoryId'  => '22',
                'tags'        => ['one', 'two'],
            ],
        ];

        $this->client()->pushLocalizations(self::channel(), $video, self::localizations());

        $snippet = $this->sentBody['snippet'] ?? [];
        $this->assertSame('22', $snippet['categoryId'] ?? null);
        $this->assertSame(['one', 'two'], $snippet['tags'] ?? null);
    }

    public function testTagsAreSentAsEmptyArrayWhenSourceHasNone(): void
    {
        $video = [
            'id'      => 'vid1',
            'snippet' => ['title' => 'T', 'description' => 'D', 'categoryId' => '22'],
        ];

        $this->client()->pushLocalizations(self::channel(), $video, self::localizations());

        $this->assertArrayHasKey('tags', $this->sentBody['snippet']);
        $this->assertSame([], $this->sentBody['snippet']['tags']);
    }

    public function testMissingDefaultLanguageFallsBackToChannelSourceLang(): void
    {
        // Regression: without defaultLanguage, YouTube silently ignores localizations.
        $video = [
            'id'      => 'vid1',
            'snippet' => ['title' => 'T', 'description' => 'D', 'categoryId' => '22'],
        ];

        $this->client()->pushLocalizations(self::channel(), $video, self::localizations());

        $this->assertSame('tr', $this->sentBody['snippet']['defaultLanguage']);
    }

    public function testEmptyDefaultLanguageFallsBackToChannelSourceLang(): void
    {
        $video = [
            'id'      => 'vid1',
            'snippet' => ['title' => 'T', 'description' => 'D', 'categoryId' => '22', 'defaultLanguage' => ''],
        ];

        $this->client()->pushLocalizations(self::channel(), $video, self::localizations());

        $this->assertSame('tr', $this->sentBody['snippet']['defaultLanguage']);
    }

    public function testExistingDefaultLanguageIsPreserved(): void
    {
        $video = [
            'id'      => 'vid1',
            'snippet' => ['title' => 'T', 'description' => 'D', 'categoryId' => '22', 'defaultLanguage' => 'en'],
        ];

        $this->client()->pushLocalizations(self::channel(), $video, self::localizations());

        $this->assertSame('en', $this->sentBody['snippet']['defaultLanguage']);
    }

    public function testExhaustedQuotaBlocksTheRequestBeforeAnyHttpCall(): void
    {
        // Quota exhaustion: the meter throws before a single byte goes out.
        $http = $this->createMock(Http::class);
        $http->expects($this->never())->method('request');

        $quota = $this->createMock(QuotaMeter::class);
        $quota->method('spend')->willThrowException(new QuotaExhaustedException('Daily quota exhausted'));

        $client = new ApiClient(
            $http,
            $this->createMock(TokenProvider::class),
            $quota,
            $this->createMock(Database::class),
        );

        $video = [
            'id'      => 'vid1',
            'snippet' => ['title' => 'T', 'description' => 'D', 'categoryId' => '22'],
        ];

        $this->expectException(QuotaExhaustedException::class);
        $client->pushLocalizations(self::channel(), $video, self::localizations());
    }

    public function testYouTubeQuotaRejectionRaisesQuotaExhausted(): void
    {
        // Even when the local meter allowed it, a quotaExceeded rejection from
        // YouTube must surface as QuotaExhaustedException, not a generic error.
        $http = $this->createMock(Http::class);
        $http->method('request')->willReturn([403, [
            'error' => ['errors' => [['reason' => 'quotaExceeded']]],
        ]]);

        $tokens = $this->createMock(TokenProvider::class);
        $tokens->method('accessToken')->willReturn('test-token');

        $client = new ApiClient(
            $http,
            $tokens,
            $this->createMock(QuotaMeter::class),
            $this->createMock(Database::class),
        );

        $video = [
            'id'      => 'vid1',
            'snippet' => ['title' => 'T', 'description' => 'D', 'categoryId' => '22'],
        ];

        $this->expectException(QuotaExhaustedException::class);
        $client->pushLocalizations(self::channel(), $video, self::localizations());
    }
}
