<?php

declare(strict_types=1);

namespace Metaglot\Quota;

use Closure;
use Metaglot\Database;

/**
 * PostgreSQL-backed accounting of YouTube Data API quota units.
 *
 * The quota day rolls over on Pacific time — that is how YouTube counts it.
 * A warning is logged when a channel reaches 90% of its daily limit; an
 * operation that would exceed 100% throws QuotaExhaustedException.
 */
class QuotaMeter
{
    /**
     * Cost in quota units per API operation — the single source of truth.
     * videos.insert costs 1600 — this worker does not use it.
     */
    public const COST = [
        'channels.list'      => 1,
        'playlistItems.list' => 1,
        'videos.list'        => 1,
        'videos.update'      => 50,
        'captions.insert'    => 400,
    ];

    private const WARN_RATIO = 0.9;

    /** @var Closure(string): void */
    private readonly Closure $warn;

    /**
     * Channels already warned about in this process.
     *
     * @var array<int, bool>
     */
    private array $warned = [];

    /**
     * @param (Closure(string): void)|null $warn warning logger; defaults to STDERR
     */
    public function __construct(private readonly Database $db, ?Closure $warn = null)
    {
        $this->warn = $warn ?? static function (string $message): void {
            fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
        };
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
     * Records the cost of an operation, refusing it if the daily limit would
     * be exceeded.
     *
     * @param array<string, mixed> $channel
     *
     * @throws QuotaExhaustedException
     */
    public function spend(array $channel, string $op): void
    {
        $units     = self::COST[$op] ?? 1;
        $channelId = (int) $channel['id'];
        $limit     = (int) $channel['daily_quota'];
        $label     = (string) $channel['label'];
        $used      = $this->usedToday($channelId);

        if ($used + $units > $limit) {
            throw new QuotaExhaustedException("Daily quota exhausted (channel $label), resuming tomorrow.");
        }

        $st = $this->db->pdo()->prepare(
            "INSERT INTO quota_log (channel_id, day, op, units)
             VALUES (:c, (now() AT TIME ZONE 'America/Los_Angeles')::date, :o, :u)"
        );
        $st->execute([':c' => $channelId, ':o' => $op, ':u' => $units]);

        $total = $used + $units;
        if ($total >= $limit * self::WARN_RATIO && !($this->warned[$channelId] ?? false)) {
            $this->warned[$channelId] = true;
            ($this->warn)("Quota warning: channel $label has used $total/$limit units (>=90%) today.");
        }
    }
}
