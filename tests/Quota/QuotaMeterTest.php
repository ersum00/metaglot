<?php

declare(strict_types=1);

namespace Metaglot\Tests\Quota;

use Metaglot\Database;
use Metaglot\Quota\QuotaExhaustedException;
use Metaglot\Quota\QuotaMeter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class QuotaMeterTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    /** @var PDOStatement&MockObject */
    private PDOStatement $insertStmt;

    protected function setUp(): void
    {
        $this->warnings = [];
    }

    /** @return array<string, mixed> */
    private static function channel(): array
    {
        return ['id' => 1, 'label' => 'acme', 'daily_quota' => 10000];
    }

    private function meter(int ...$usedValues): QuotaMeter
    {
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('fetchColumn')->willReturnOnConsecutiveCalls(...$usedValues);

        $this->insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt       = $this->insertStmt;

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static function (string $sql) use ($selectStmt, $insertStmt): PDOStatement {
                return str_contains($sql, 'INSERT INTO quota_log') ? $insertStmt : $selectStmt;
            }
        );

        $db = $this->createMock(Database::class);
        $db->method('pdo')->willReturn($pdo);

        return new QuotaMeter($db, function (string $message): void {
            $this->warnings[] = $message;
        });
    }

    public function testThrowsWhenDailyLimitWouldBeExceeded(): void
    {
        $meter = $this->meter(9960); // 9960 + 50 > 10000
        $this->insertStmt->expects($this->never())->method('execute');

        $this->expectException(QuotaExhaustedException::class);
        $meter->spend(self::channel(), 'videos.update');
    }

    public function testWarnsOnceAtNinetyPercent(): void
    {
        $meter = $this->meter(8950, 9000);

        $meter->spend(self::channel(), 'videos.update'); // reaches 9000 = 90%
        $meter->spend(self::channel(), 'videos.update'); // still over 90%, already warned

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('9000/10000', $this->warnings[0]);
    }

    public function testStaysQuietBelowNinetyPercent(): void
    {
        $meter = $this->meter(100);

        $meter->spend(self::channel(), 'videos.list');

        $this->assertSame([], $this->warnings);
    }

    public function testCostTableIsTheSingleSourceOfTruth(): void
    {
        $this->assertSame(
            [
                'channels.list'      => 1,
                'playlistItems.list' => 1,
                'videos.list'        => 1,
                'videos.update'      => 50,
                'captions.insert'    => 400,
            ],
            QuotaMeter::COST,
        );
    }
}
