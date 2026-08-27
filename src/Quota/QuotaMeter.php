<?php

declare(strict_types=1);

namespace Metaglot\Quota;

use Metaglot\Database;

/**
 * PostgreSQL-backed accounting of YouTube Data API quota units.
 *
 * The quota day rolls over on Pacific time — that is how YouTube counts it.
 */
final class QuotaMeter
{
    /**
     * Cost in quota units per API operation.
     * videos.insert costs 1600 and captions.insert 400 — this worker uses neither.
     */
    public const COST = [
        'channels.list'      => 1,
        'playlistItems.list' => 1,
        'videos.list'        => 1,
        'videos.update'      => 50,
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function usedToday(int $channelId): int
    {
        $st = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(units),0) AS u FROM quota_log
             WHERE channel_id = :c AND day = (now() AT TIME ZONE 'America/Los_Angeles')::date"
        );
        $st->execute([':c' => $channelId]);

        return (int) $st->fetchColumn();
    }

    /**
     * Records the cost of an operation, refusing it if the daily limit
     * would be exceeded.
     *
     * @param array<string, mixed> $channel
     *
     * @throws QuotaExhaustedException
     */
    public function spend(array $channel, string $op): void
    {
        $units = self::COST[$op] ?? 1;
        if ($this->usedToday((int) $channel['id']) + $units > (int) $channel['daily_quota']) {
            $label = (string) $channel['label'];
            throw new QuotaExhaustedException("Daily quota exhausted (channel $label), resuming tomorrow.");
        }
        $st = $this->db->pdo()->prepare(
            "INSERT INTO quota_log (channel_id, day, op, units)
             VALUES (:c, (now() AT TIME ZONE 'America/Los_Angeles')::date, :o, :u)"
        );
        $st->execute([':c' => $channel['id'], ':o' => $op, ':u' => $units]);
    }
}
