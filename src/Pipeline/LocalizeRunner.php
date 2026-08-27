<?php

declare(strict_types=1);

namespace Metaglot\Pipeline;

use Closure;
use Metaglot\Database;
use Metaglot\Quota\QuotaExhaustedException;
use Metaglot\Quota\QuotaMeter;
use Metaglot\Translate\TranslatorInterface;
use Metaglot\YouTube\ApiClient;
use RuntimeException;
use Throwable;

/**
 * End-to-end run for one channel: fetch recent videos, localize the missing
 * languages, push the result back to YouTube, and record progress in
 * PostgreSQL.
 *
 * Idempotency contract: a video is never processed twice. When the source
 * title/description hash is unchanged and every target language is already
 * present, no LLM call and no YouTube write happens for that video.
 */
final class LocalizeRunner
{
    /** @var Closure(string): void */
    private readonly Closure $log;

    /**
     * @param (Closure(string): void)|null $log progress logger; defaults to STDERR
     */
    public function __construct(
        private readonly Database $db,
        private readonly ApiClient $youtube,
        private readonly TranslatorInterface $translator,
        private readonly QuotaMeter $quota,
        ?Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $message): void {
            fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
        };
    }

    /**
     * @param array<string, mixed> $channel
     */
    public function run(array $channel, bool $dryRun): void
    {
        $label = (string) $channel['label'];
        ($this->log)(sprintf(
            'Channel: %s (quota used: %d/%d)',
            $label,
            $this->quota->usedToday((int) $channel['id']),
            (int) $channel['daily_quota'],
        ));

        $ids    = $this->youtube->recentVideoIds($channel, (int) $channel['max_videos_per_run']);
        $videos = $this->youtube->fetchVideos($channel, $ids);
        $langs  = self::explodePgArray((string) $channel['target_langs']);

        $upsert = $this->db->pdo()->prepare(
            "INSERT INTO videos (channel_id, video_id, title, published_at, source_hash, status)
             VALUES (:c, :v, :t, :p, :h, 'pending')
             ON CONFLICT (channel_id, video_id) DO UPDATE
               SET title = EXCLUDED.title,
                   status = CASE WHEN videos.source_hash IS DISTINCT FROM EXCLUDED.source_hash
                                 THEN 'pending' ELSE videos.status END,
                   source_hash = EXCLUDED.source_hash
             RETURNING status, localized_langs"
        );

        $done = $this->db->pdo()->prepare(
            "UPDATE videos SET status='done', localized_langs=:l, last_error=NULL, updated_at=now()
             WHERE channel_id=:c AND video_id=:v"
        );
        $fail = $this->db->pdo()->prepare(
            "UPDATE videos SET status='failed', last_error=:e, updated_at=now()
             WHERE channel_id=:c AND video_id=:v"
        );

        foreach ($videos as $vid => $video) {
            $snip = $video['snippet'];
            $hash = md5($snip['title'] . $snip['description'] . implode(',', $langs));

            $upsert->execute([
                ':c' => $channel['id'], ':v' => $vid,
                ':t' => $snip['title'],
                ':p' => $snip['publishedAt'],
                ':h' => $hash,
            ]);
            $row = $upsert->fetch();

            if (($row['status'] ?? 'pending') === 'done') {
                // Idempotency: source hash unchanged and every target language
                // was already written — nothing to do, no API calls.
                continue;
            }

            // Do not rewrite languages that already exist on YouTube.
            $existing = array_keys($video['localizations'] ?? []);
            $missing  = array_values(array_diff($langs, $existing));
            if ($missing === []) {
                // Idempotency: all target languages already present — record it
                // locally, never call the LLM or videos.update.
                $done->execute([':c' => $channel['id'], ':v' => $vid, ':l' => self::toPgArray($existing)]);
                continue;
            }

            try {
                $new = $this->translator->localize(
                    (string) $snip['title'],
                    (string) $snip['description'],
                    (string) $channel['source_lang'],
                    $missing,
                );
                if ($new === []) {
                    throw new RuntimeException('Translation came back empty.');
                }
                $merged = ($video['localizations'] ?? []) + $new;

                if ($dryRun) {
                    ($this->log)("  [dry] $vid → " . implode(',', array_keys($new)));
                    foreach ($new as $lang => $loc) {
                        ($this->log)("        $lang: {$loc['title']}");
                    }
                    continue;
                }

                $this->youtube->pushLocalizations($channel, $video, $merged);
                $done->execute([':c' => $channel['id'], ':v' => $vid, ':l' => self::toPgArray(array_keys($merged))]);
                ($this->log)("  ✓ $vid (+" . count($new) . ' languages)');
            } catch (QuotaExhaustedException $e) {
                throw $e; // bubble up — the worker must stop
            } catch (Throwable $e) {
                $fail->execute([':c' => $channel['id'], ':v' => $vid, ':e' => $e->getMessage()]);
                ($this->log)("  ✗ $vid: " . $e->getMessage());
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function explodePgArray(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', trim($value, '{}')))));
    }

    /**
     * @param list<string> $values
     */
    private static function toPgArray(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }
}
