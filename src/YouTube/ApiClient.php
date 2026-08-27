<?php

declare(strict_types=1);

namespace Metaglot\YouTube;

use Metaglot\Auth\TokenProvider;
use Metaglot\Database;
use Metaglot\Http;
use Metaglot\Quota\QuotaExhaustedException;
use Metaglot\Quota\QuotaMeter;
use RuntimeException;

/**
 * Minimal YouTube Data API v3 client covering channels.list,
 * playlistItems.list, videos.list and videos.update.
 */
class ApiClient
{
    private const API = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private readonly Http $http,
        private readonly TokenProvider $tokens,
        private readonly QuotaMeter $quota,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $channel
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     */
    private function get(array $channel, string $path, array $query, string $op): array
    {
        $this->quota->spend($channel, $op);
        $url = self::API . '/' . $path . '?' . http_build_query($query);
        [$status, $res] = $this->http->request('GET', $url, [
            'Authorization: Bearer ' . $this->tokens->accessToken($channel),
        ]);

        if ($status === 403 && str_contains((string) json_encode($res), 'quotaExceeded')) {
            throw new QuotaExhaustedException(
                'YouTube rejected the request over quota — the local meter may have drifted from actual usage.'
            );
        }
        if ($status !== 200) {
            throw new RuntimeException("$op failed ($status): " . (string) json_encode($res));
        }

        return $res;
    }

    /**
     * Resolves and caches the channel's uploads playlist.
     * Do NOT use search.list for this — it costs 100 units; this path costs 1.
     *
     * @param array<string, mixed> $channel
     */
    public function uploadsPlaylistId(array &$channel): string
    {
        if (!empty($channel['uploads_playlist_id'])) {
            return (string) $channel['uploads_playlist_id'];
        }
        $res = $this->get($channel, 'channels', ['part' => 'contentDetails', 'mine' => 'true'], 'channels.list');
        $pid = $res['items'][0]['contentDetails']['relatedPlaylists']['uploads']
            ?? throw new RuntimeException('Uploads playlist not found.');
        $cid = $res['items'][0]['id'] ?? null;

        $this->db->pdo()
            ->prepare('UPDATE channels SET uploads_playlist_id = :p, yt_channel_id = :c WHERE id = :i')
            ->execute([':p' => $pid, ':c' => $cid, ':i' => $channel['id']]);

        $channel['uploads_playlist_id'] = $pid;

        return (string) $pid;
    }

    /**
     * @param array<string, mixed> $channel
     *
     * @return list<string>
     */
    public function recentVideoIds(array &$channel, int $limit): array
    {
        $ids  = [];
        $page = null;
        do {
            $res = $this->get($channel, 'playlistItems', array_filter([
                'part'       => 'contentDetails',
                'playlistId' => $this->uploadsPlaylistId($channel),
                'maxResults' => 50,
                'pageToken'  => $page,
            ]), 'playlistItems.list');

            foreach ($res['items'] ?? [] as $item) {
                $ids[] = (string) $item['contentDetails']['videoId'];
                if (count($ids) >= $limit) {
                    return $ids;
                }
            }
            $page = $res['nextPageToken'] ?? null;
        } while ($page !== null);

        return $ids;
    }

    /**
     * Fetches videos in chunks of 50; each call costs 1 unit.
     *
     * @param array<string, mixed> $channel
     * @param list<string> $ids
     *
     * @return array<string, array<mixed>>
     */
    public function fetchVideos(array $channel, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $res = $this->get($channel, 'videos', [
                'part' => 'snippet,localizations,status',
                'id'   => implode(',', $chunk),
            ], 'videos.list');
            foreach ($res['items'] ?? [] as $item) {
                $out[(string) $item['id']] = $item;
            }
        }

        return $out;
    }

    /**
     * Writes localizations back to a video.
     *
     * CRITICAL: when sending part=snippet to videos.update, the snippet must
     * be sent COMPLETE. If a field (tags, categoryId) is missing from the
     * request, YouTube DELETES it from the video. Additionally, if
     * defaultLanguage is not set, the localizations payload is silently
     * ignored.
     *
     * @param array<string, mixed> $channel
     * @param array<mixed> $video
     * @param array<string, array{title: string, description: string}> $localizations
     */
    public function pushLocalizations(array $channel, array $video, array $localizations): void
    {
        $snippet = $video['snippet'];

        $body = [
            'id'      => $video['id'],
            'snippet' => [
                'title'           => $snippet['title'],
                'description'     => $snippet['description'],
                'categoryId'      => $snippet['categoryId'],
                'tags'            => $snippet['tags'] ?? [],
                'defaultLanguage' => ($snippet['defaultLanguage'] ?? '') !== ''
                    ? $snippet['defaultLanguage']
                    : $channel['source_lang'],
            ],
            'localizations' => $localizations,
        ];

        $this->quota->spend($channel, 'videos.update');
        [$status, $res] = $this->http->request(
            'PUT',
            self::API . '/videos?' . http_build_query(['part' => 'snippet,localizations']),
            [
                'Authorization: Bearer ' . $this->tokens->accessToken($channel),
                'Content-Type: application/json',
            ],
            (string) json_encode($body, JSON_UNESCAPED_UNICODE),
        );

        if ($status === 403 && str_contains((string) json_encode($res), 'quotaExceeded')) {
            throw new QuotaExhaustedException('videos.update rejected over quota.');
        }
        if ($status !== 200) {
            throw new RuntimeException(
                "videos.update ($status): " . (string) json_encode($res, JSON_UNESCAPED_UNICODE)
            );
        }
    }
}
