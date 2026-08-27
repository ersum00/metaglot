<?php

declare(strict_types=1);

namespace Metaglot\Tests\Pipeline;

use Metaglot\Database;
use Metaglot\Pipeline\LocalizeRunner;
use Metaglot\Quota\QuotaExhaustedException;
use Metaglot\Quota\QuotaMeter;
use Metaglot\Translate\TranslatorInterface;
use Metaglot\YouTube\ApiClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LocalizeRunnerTest extends TestCase
{
    /** @var PDOStatement&MockObject */
    private PDOStatement $doneStmt;

    /** @var PDOStatement&MockObject */
    private PDOStatement $failStmt;

    /** @var PDOStatement&MockObject */
    private PDOStatement $partialStmt;

    /** @return array<string, mixed> */
    private static function channel(): array
    {
        return [
            'id'                 => 1,
            'label'              => 'acme',
            'source_lang'        => 'tr',
            'target_langs'       => '{en,es}',
            'daily_quota'        => 10000,
            'max_videos_per_run' => 50,
        ];
    }

    /**
     * @param array<string, array<string, string>> $localizations
     *
     * @return array<string, mixed>
     */
    private static function video(array $localizations = []): array
    {
        return [
            'id'      => 'vid1',
            'snippet' => [
                'title'       => 'Title',
                'description' => 'Desc',
                'publishedAt' => '2026-08-27T00:00:00Z',
                'categoryId'  => '22',
            ],
            'localizations' => $localizations,
        ];
    }

    /**
     * @param array<string, mixed> $upsertRow row returned by the videos upsert
     */
    private function database(array $upsertRow): Database
    {
        $upsertStmt = $this->createMock(PDOStatement::class);
        $upsertStmt->method('fetch')->willReturn($upsertRow);

        $this->doneStmt    = $this->createMock(PDOStatement::class);
        $this->failStmt    = $this->createMock(PDOStatement::class);
        $this->partialStmt = $this->createMock(PDOStatement::class);

        [$doneStmt, $failStmt, $partialStmt] = [$this->doneStmt, $this->failStmt, $this->partialStmt];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static function (string $sql) use ($upsertStmt, $doneStmt, $failStmt, $partialStmt): PDOStatement {
                if (str_contains($sql, 'INSERT INTO videos')) {
                    return $upsertStmt;
                }
                if (str_contains($sql, "SET status='pending'")) {
                    return $partialStmt;
                }
                if (str_contains($sql, "status='done'")) {
                    return $doneStmt;
                }

                return $failStmt;
            }
        );

        $db = $this->createMock(Database::class);
        $db->method('pdo')->willReturn($pdo);

        return $db;
    }

    /**
     * @param array<string, mixed> $video
     *
     * @return ApiClient&MockObject
     */
    private function youtube(array $video): ApiClient
    {
        $youtube = $this->createMock(ApiClient::class);
        $youtube->method('recentVideoIds')->willReturn(['vid1']);
        $youtube->method('fetchVideos')->willReturn(['vid1' => $video]);

        return $youtube;
    }

    /** @return QuotaMeter&MockObject */
    private function quota(): QuotaMeter
    {
        $quota = $this->createMock(QuotaMeter::class);
        $quota->method('usedToday')->willReturn(0);

        return $quota;
    }

    private function runner(Database $db, ApiClient $youtube, TranslatorInterface $translator): LocalizeRunner
    {
        return new LocalizeRunner($db, $youtube, $translator, $this->quota(), static function (string $message): void {
        });
    }

    public function testDoneVideoWithUnchangedSourceMakesNoApiCalls(): void
    {
        // Idempotency: source hash unchanged (status 'done') — the video must
        // not be localized or pushed again.
        $youtube = $this->youtube(self::video([
            'en' => ['title' => 'a', 'description' => 'b'],
            'es' => ['title' => 'c', 'description' => 'd'],
        ]));
        $youtube->expects($this->never())->method('pushLocalizations');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->never())->method('localize');

        $db = $this->database(['status' => 'done', 'localized_langs' => '{en,es}']);
        $this->doneStmt->expects($this->never())->method('execute');

        $this->runner($db, $youtube, $translator)->run(self::channel(), false);
    }

    public function testVideoWithAllLanguagesPresentIsMarkedDoneWithoutApiWrites(): void
    {
        // Idempotency: every target language already exists on YouTube — mark
        // it done locally, never call the LLM or videos.update.
        $youtube = $this->youtube(self::video([
            'en' => ['title' => 'a', 'description' => 'b'],
            'es' => ['title' => 'c', 'description' => 'd'],
        ]));
        $youtube->expects($this->never())->method('pushLocalizations');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->never())->method('localize');

        $db = $this->database(['status' => 'pending', 'localized_langs' => '{}']);
        $this->doneStmt->expects($this->once())->method('execute')
            ->with([':c' => 1, ':v' => 'vid1', ':l' => '{en,es}']);

        $this->runner($db, $youtube, $translator)->run(self::channel(), false);
    }

    public function testQuotaExhaustionStopsTheWorkerWithoutMarkingVideoFailed(): void
    {
        // Quota exhaustion must bubble up so the worker stops; the video must
        // NOT be marked 'failed' — it will simply be retried tomorrow.
        $youtube = $this->youtube(self::video());
        $youtube->method('pushLocalizations')
            ->willThrowException(new QuotaExhaustedException('Daily quota exhausted'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('localize')->willReturn([
            'en' => ['title' => 'a', 'description' => 'b'],
            'es' => ['title' => 'c', 'description' => 'd'],
        ]);

        $db = $this->database(['status' => 'pending', 'localized_langs' => '{}']);
        $this->failStmt->expects($this->never())->method('execute');
        $this->doneStmt->expects($this->never())->method('execute');

        $this->expectException(QuotaExhaustedException::class);
        $this->runner($db, $youtube, $translator)->run(self::channel(), false);
    }

    public function testTranslatorFailureMarksVideoFailedAndContinues(): void
    {
        // A broken LLM response fails only that video; the run continues and
        // the error message is recorded.
        $youtube = $this->youtube(self::video());
        $youtube->expects($this->never())->method('pushLocalizations');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('localize')
            ->willThrowException(new RuntimeException('LLM did not return valid JSON.'));

        $db = $this->database(['status' => 'pending', 'localized_langs' => '{}']);
        $this->failStmt->expects($this->once())->method('execute')
            ->with([':c' => 1, ':v' => 'vid1', ':e' => 'LLM did not return valid JSON.']);
        $this->doneStmt->expects($this->never())->method('execute');

        $this->runner($db, $youtube, $translator)->run(self::channel(), false);
    }

    public function testPartialSuccessKeepsVideoRetryable(): void
    {
        // Partial success: the languages that arrived are pushed, but the
        // video stays 'pending' (retryable), NOT 'failed', so the missing
        // ones are attempted again on the next run.
        $youtube = $this->youtube(self::video());
        $youtube->expects($this->once())->method('pushLocalizations');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('localize')->willReturn([
            'en' => ['title' => 'a', 'description' => 'b'],
            // 'es' never came back from the LLM
        ]);

        $db = $this->database(['status' => 'pending', 'localized_langs' => '{}']);
        $this->partialStmt->expects($this->once())->method('execute')
            ->with([':c' => 1, ':v' => 'vid1', ':l' => '{en}', ':e' => 'partial: still missing es']);
        $this->doneStmt->expects($this->never())->method('execute');
        $this->failStmt->expects($this->never())->method('execute');

        $this->runner($db, $youtube, $translator)->run(self::channel(), false);
    }
}
