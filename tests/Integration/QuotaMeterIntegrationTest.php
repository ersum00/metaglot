<?php

declare(strict_types=1);

namespace Metaglot\Tests\Integration;

use Metaglot\Quota\QuotaExhaustedException;
use Metaglot\Quota\QuotaMeter;

final class QuotaMeterIntegrationTest extends IntegrationTestCase
{
    public function testSpendAccumulatesInPostgres(): void
    {
        $channel = $this->insertChannel();
        $meter   = new QuotaMeter($this->db, static function (string $message): void {
        });

        $meter->spend($channel, 'videos.update');   // 50
        $meter->spend($channel, 'videos.list');     // 1
        $meter->spend($channel, 'channels.list');   // 1

        $this->assertSame(52, $meter->usedToday((int) $channel['id']));
    }

    public function testThrowsWhenTheDailyLimitWouldBeExceeded(): void
    {
        $channel = $this->insertChannel(dailyQuota: 60);
        $meter   = new QuotaMeter($this->db, static function (string $message): void {
        });

        $meter->spend($channel, 'videos.update');   // 50 of 60

        $this->expectException(QuotaExhaustedException::class);
        $meter->spend($channel, 'videos.update');   // would be 100 of 60
    }

    public function testRefusedSpendIsNotRecorded(): void
    {
        $channel = $this->insertChannel(dailyQuota: 60);
        $meter   = new QuotaMeter($this->db, static function (string $message): void {
        });

        $meter->spend($channel, 'videos.update');

        try {
            $meter->spend($channel, 'videos.update');
            $this->fail('Expected QuotaExhaustedException');
        } catch (QuotaExhaustedException) {
            // The refused operation must not have been logged.
            $this->assertSame(50, $meter->usedToday((int) $channel['id']));
        }
    }
}
