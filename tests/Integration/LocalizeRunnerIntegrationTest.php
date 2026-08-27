<?php

declare(strict_types=1);

namespace Metaglot\Tests\Integration;

use Metaglot\Pipeline\LocalizeRunner;
use Metaglot\Quota\QuotaMeter;
use Metaglot\Translate\TranslatorInterface;
use Metaglot\YouTube\ApiClient;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Exercises the real SQL of the pipeline — upsert with ON CONFLICT, the
 * done/pending transitions and the idempotent second run — against PostgreSQL.
 * YouTube and the LLM stay mocked; no network is involved.
 */
final class LocalizeRunnerIntegrationTest extends IntegrationTestCase
{
    private int $translatorCalls = 0;

    /**
     * @param array<string, array<string, string>> $localizations
     *
     * @return ApiClient&MockObject
     */
    private function youtube(array $localizations = []): ApiClient
    {
        $video = [
            'id'      => 'vid1',
            'snippet' => [
                'title'       => 'Title',
                'description' => 'Desc',
                'publishedAt' => '2026-08-27T00:00:00+00:00',
                'categoryId'  => '22',
            ],
            'localizations' => $localizations,
        ];

        $youtube = $this->createMock(ApiClient::class);
        $youtube->method('recentVideoIds')->willReturn(['vid1']);
        $youtube->method('fetchVideos')->willReturn(['vid1' => $video]);

        return $youtube;
    }

    /**
     * @param array<string, array{title: string, description: string}> $result
     */
    private function translator(array $result): TranslatorInterface
    {
        $this->translatorCalls = 0;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('localize')->willReturnCallback(
            function () use ($result): array {
                $this->translatorCalls++;

                return $result;
            }
        );

        return $translator;
    }

    /**
     * @param array<string, mixed> $channel
     *
     * @return array<string, mixed>
     */
    private function videoRow(array $channel): array
    {
        $st = $this->db->pdo()->prepare(
            'SELECT status, localized_langs, last_error FROM videos WHERE channel_id = :c AND video_id = :v'
        );
        $st->execute([':c' => $channel['id'], ':v' => 'vid1']);

        return (array) $st->fetch();
    }

    private function runner(ApiClient $youtube, TranslatorInterface $translator): LocalizeRunner
    {
        $quota = new QuotaMeter($this->db, static function (string $message): void {
        });

        return new LocalizeRunner($this->db, $youtube, $translator, $quota, static function (string $message): void {
        });
    }

    public function testFullRunMarksVideoDoneAndSecondRunSkips(): void
    {
        $channel    = $this->insertChannel();
        $youtube    = $this->youtube();
        $translator = $this->translator([
            'en' => ['title' => 'a', 'description' => 'b'],
            'es' => ['title' => 'c', 'description' => 'd'],
        ]);
        $runner = $this->runner($youtube, $translator);

        $runner->run($channel, false);

        $row = $this->videoRow($channel);
        $this->assertSame('done', $row['status']);
        $this->assertSame('{en,es}', $row['localized_langs']);
        $this->assertSame(1, $this->translatorCalls);

        // Second run, source unchanged: the ON CONFLICT upsert must keep
        // status 'done' and the video must be skipped entirely.
        $runner->run($channel, false);

        $this->assertSame('done', $this->videoRow($channel)['status']);
        $this->assertSame(1, $this->translatorCalls);
    }

    public function testPartialResultPersistsRetryableState(): void
    {
        $channel    = $this->insertChannel();
        $youtube    = $this->youtube();
        $translator = $this->translator([
            'en' => ['title' => 'a', 'description' => 'b'],
            // 'es' missing from the LLM answer
        ]);

        $this->runner($youtube, $translator)->run($channel, false);

        $row = $this->videoRow($channel);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('{en}', $row['localized_langs']);
        $this->assertSame('partial: still missing es', $row['last_error']);
    }
}
