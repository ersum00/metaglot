<?php
declare(strict_types=1);

/**
 * YouTube Metadata Localizer
 * -------------------------------------------------------------------------
 * Bir kanalın son videolarını çeker, başlık + açıklamayı N dile lokalize eder
 * ve videos.update ile YouTube'a yazar. Kota muhasebesi PostgreSQL'de tutulur.
 *
 * Kullanım:
 *   php localizer.php --channel=1
 *   php localizer.php --all                 # cron: her saat başı
 *   php localizer.php --channel=1 --dry-run # yazmadan çıktıyı gör
 *
 * Ortam değişkenleri:
 *   PG_DSN, PG_USER, PG_PASS
 *   LLM_ENDPOINT  (örn. http://127.0.0.1:11434/v1/chat/completions)
 *   LLM_MODEL     (örn. qwen2.5:14b-instruct)
 *   LLM_KEY       (Ollama için boş bırakılabilir)
 */

// ---------------------------------------------------------------- sabitler

const YT_API   = 'https://www.googleapis.com/youtube/v3';
const OAUTH_URL = 'https://oauth2.googleapis.com/token';

// videos.insert 1600, captions.insert 400 — bu worker onları kullanmıyor.
const COST = [
    'channels.list'      => 1,
    'playlistItems.list' => 1,
    'videos.list'        => 1,
    'videos.update'      => 50,
];

const TITLE_MAX = 100;
const DESC_MAX  = 5000;

// ---------------------------------------------------------------- altyapı

final class QuotaExhausted extends RuntimeException {}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            getenv('PG_DSN') ?: 'pgsql:host=127.0.0.1;dbname=localizer',
            getenv('PG_USER') ?: 'localizer',
            getenv('PG_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function logline(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

/** Basit cURL sarmalayıcı; [status, decodedBody] döner. */
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("cURL hatası: $err");
    }
    return [$status, json_decode($raw, true) ?? ['raw' => $raw]];
}

// ---------------------------------------------------------------- OAuth

/**
 * DİKKAT: OAuth consent screen'i "Testing" modunda bırakırsan Google
 * refresh token'ı 7 günde geçersiz kılar ve invalid_grant alırsın.
 * Müşteriye kurulum yaparken ekranı "In production" durumuna aldır.
 */
function accessToken(array $ch): string
{
    static $cache = [];
    if (isset($cache[$ch['id']])) {
        return $cache[$ch['id']];
    }

    [$status, $res] = http('POST', OAUTH_URL,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id'     => $ch['client_id'],
            'client_secret' => $ch['client_secret'],
            'refresh_token' => $ch['refresh_token'],
            'grant_type'    => 'refresh_token',
        ])
    );

    if ($status !== 200 || empty($res['access_token'])) {
        $e = $res['error'] ?? 'bilinmeyen';
        if ($e === 'invalid_grant') {
            throw new RuntimeException(
                "refresh_token geçersiz (kanal {$ch['label']}). " .
                "Consent screen 'Testing' modundaysa token 7 günde ölür — müşteriden yeniden yetki al."
            );
        }
        throw new RuntimeException("Token yenilenemedi: $e");
    }

    return $cache[$ch['id']] = $res['access_token'];
}

// ---------------------------------------------------------------- kota

function quotaUsedToday(int $channelId): int
{
    $st = db()->prepare(
        "SELECT COALESCE(SUM(units),0) AS u FROM quota_log
         WHERE channel_id = :c AND day = (now() AT TIME ZONE 'America/Los_Angeles')::date"
    );
    $st->execute([':c' => $channelId]);
    return (int) $st->fetchColumn();
}

function quotaSpend(array $ch, string $op): void
{
    $units = COST[$op] ?? 1;
    if (quotaUsedToday($ch['id']) + $units > (int) $ch['daily_quota']) {
        throw new QuotaExhausted("Günlük kota doldu (kanal {$ch['label']}), yarın devam.");
    }
    $st = db()->prepare(
        "INSERT INTO quota_log (channel_id, day, op, units)
         VALUES (:c, (now() AT TIME ZONE 'America/Los_Angeles')::date, :o, :u)"
    );
    $st->execute([':c' => $ch['id'], ':o' => $op, ':u' => $units]);
}

// ---------------------------------------------------------------- YouTube

function ytGet(array $ch, string $path, array $query, string $op): array
{
    quotaSpend($ch, $op);
    $url = YT_API . '/' . $path . '?' . http_build_query($query);
    [$status, $res] = http('GET', $url, ['Authorization: Bearer ' . accessToken($ch)]);

    if ($status === 403 && str_contains(json_encode($res), 'quotaExceeded')) {
        throw new QuotaExhausted('YouTube kotayı reddetti — sayaç ile gerçek kullanım ayrışmış olabilir.');
    }
    if ($status !== 200) {
        throw new RuntimeException("$op başarısız ($status): " . json_encode($res));
    }
    return $res;
}

/** Uploads playlist'i cache'ler. search.list KULLANMA — 100 birim, bu 1 birim. */
function uploadsPlaylistId(array &$ch): string
{
    if (!empty($ch['uploads_playlist_id'])) {
        return $ch['uploads_playlist_id'];
    }
    $res = ytGet($ch, 'channels', ['part' => 'contentDetails', 'mine' => 'true'], 'channels.list');
    $pid = $res['items'][0]['contentDetails']['relatedPlaylists']['uploads']
        ?? throw new RuntimeException('Uploads playlist bulunamadı.');
    $cid = $res['items'][0]['id'] ?? null;

    db()->prepare('UPDATE channels SET uploads_playlist_id = :p, yt_channel_id = :c WHERE id = :i')
        ->execute([':p' => $pid, ':c' => $cid, ':i' => $ch['id']]);

    $ch['uploads_playlist_id'] = $pid;
    return $pid;
}

function recentVideoIds(array &$ch, int $limit): array
{
    $ids  = [];
    $page = null;
    do {
        $res = ytGet($ch, 'playlistItems', array_filter([
            'part'       => 'contentDetails',
            'playlistId' => uploadsPlaylistId($ch),
            'maxResults' => 50,
            'pageToken'  => $page,
        ]), 'playlistItems.list');

        foreach ($res['items'] ?? [] as $it) {
            $ids[] = $it['contentDetails']['videoId'];
            if (count($ids) >= $limit) {
                return $ids;
            }
        }
        $page = $res['nextPageToken'] ?? null;
    } while ($page !== null);

    return $ids;
}

/** 50'şerlik gruplar halinde çeker; her çağrı 1 birim. */
function fetchVideos(array $ch, array $ids): array
{
    $out = [];
    foreach (array_chunk($ids, 50) as $chunk) {
        $res = ytGet($ch, 'videos', [
            'part' => 'snippet,localizations,status',
            'id'   => implode(',', $chunk),
        ], 'videos.list');
        foreach ($res['items'] ?? [] as $item) {
            $out[$item['id']] = $item;
        }
    }
    return $out;
}

/**
 * videos.update part=snippet gönderirken snippet'i TAM göndermek zorundasın.
 * Eksik alan gönderirsen (tags, categoryId) YouTube o alanları SİLER.
 * Ayrıca defaultLanguage set değilse localizations sessizce yok sayılır.
 */
function pushLocalizations(array $ch, array $video, array $loc): void
{
    $snippet = $video['snippet'];

    $body = [
        'id'      => $video['id'],
        'snippet' => [
            'title'           => $snippet['title'],
            'description'     => $snippet['description'],
            'categoryId'      => $snippet['categoryId'],
            'tags'            => $snippet['tags'] ?? [],
            'defaultLanguage' => $snippet['defaultLanguage'] ?? $ch['source_lang'],
        ],
        'localizations' => $loc,
    ];

    quotaSpend($ch, 'videos.update');
    [$status, $res] = http(
        'PUT',
        YT_API . '/videos?' . http_build_query(['part' => 'snippet,localizations']),
        ['Authorization: Bearer ' . accessToken($ch), 'Content-Type: application/json'],
        json_encode($body, JSON_UNESCAPED_UNICODE)
    );

    if ($status === 403 && str_contains(json_encode($res), 'quotaExceeded')) {
        throw new QuotaExhausted('videos.update kota reddi.');
    }
    if ($status !== 200) {
        throw new RuntimeException("videos.update ($status): " . json_encode($res, JSON_UNESCAPED_UNICODE));
    }
}

// ---------------------------------------------------------------- çeviri

/**
 * Birebir çeviri DEĞİL: hedef dilde arama yapan izleyicinin yazacağı
 * ifadeyle başlık üretir. Ürünün asıl değeri burada.
 */
function localize(string $title, string $desc, string $src, array $targets): array
{
    $sys = <<<TXT
    You are a YouTube SEO localizer. For each target language produce a title and
    description that a native speaker would SEARCH for, not a literal translation.
    Rules: keep the title under 100 characters; preserve proper nouns, brand names,
    numbers and hashtags; keep the description structure (line breaks, links, timestamps)
    identical to the source; never invent facts.
    Return ONLY a JSON object: {"<lang>": {"title": "...", "description": "..."}}
    TXT;

    $user = json_encode([
        'source_language'  => $src,
        'target_languages' => array_values($targets),
        'title'            => $title,
        'description'      => mb_substr($desc, 0, DESC_MAX),
    ], JSON_UNESCAPED_UNICODE);

    [$status, $res] = http('POST',
        getenv('LLM_ENDPOINT') ?: 'http://127.0.0.1:11434/v1/chat/completions',
        array_filter([
            'Content-Type: application/json',
            getenv('LLM_KEY') ? 'Authorization: Bearer ' . getenv('LLM_KEY') : null,
        ]),
        json_encode([
            'model'           => getenv('LLM_MODEL') ?: 'qwen2.5:14b-instruct',
            'temperature'     => 0.3,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $user],
            ],
        ], JSON_UNESCAPED_UNICODE)
    );

    if ($status !== 200) {
        throw new RuntimeException("LLM hatası ($status): " . json_encode($res));
    }

    $content = $res['choices'][0]['message']['content'] ?? '';
    $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content));
    $parsed  = json_decode($content, true);

    if (!is_array($parsed)) {
        throw new RuntimeException('LLM geçerli JSON döndürmedi.');
    }

    // Sınırları burada zorla — YouTube 100 karakteri aşan başlığı reddeder.
    $clean = [];
    foreach ($targets as $lang) {
        if (empty($parsed[$lang]['title'])) {
            continue;
        }
        $clean[$lang] = [
            'title'       => mb_substr($parsed[$lang]['title'], 0, TITLE_MAX),
            'description' => mb_substr($parsed[$lang]['description'] ?? $desc, 0, DESC_MAX),
        ];
    }
    return $clean;
}

// ---------------------------------------------------------------- ana akış

function processChannel(array $ch, bool $dryRun): void
{
    logline("Kanal: {$ch['label']} (kullanılan kota: " . quotaUsedToday($ch['id']) . '/' . $ch['daily_quota'] . ')');

    $ids    = recentVideoIds($ch, (int) $ch['max_videos_per_run']);
    $videos = fetchVideos($ch, $ids);
    $langs  = explodePgArray($ch['target_langs']);

    $upsert = db()->prepare(
        "INSERT INTO videos (channel_id, video_id, title, published_at, source_hash, status)
         VALUES (:c, :v, :t, :p, :h, 'pending')
         ON CONFLICT (channel_id, video_id) DO UPDATE
           SET title = EXCLUDED.title,
               status = CASE WHEN videos.source_hash IS DISTINCT FROM EXCLUDED.source_hash
                             THEN 'pending' ELSE videos.status END,
               source_hash = EXCLUDED.source_hash
         RETURNING status, localized_langs"
    );

    $done = db()->prepare(
        "UPDATE videos SET status='done', localized_langs=:l, last_error=NULL, updated_at=now()
         WHERE channel_id=:c AND video_id=:v"
    );
    $fail = db()->prepare(
        "UPDATE videos SET status='failed', last_error=:e, updated_at=now()
         WHERE channel_id=:c AND video_id=:v"
    );

    foreach ($videos as $vid => $video) {
        $snip = $video['snippet'];
        $hash = md5($snip['title'] . $snip['description'] . implode(',', $langs));

        $upsert->execute([
            ':c' => $ch['id'], ':v' => $vid,
            ':t' => $snip['title'],
            ':p' => $snip['publishedAt'],
            ':h' => $hash,
        ]);
        $row = $upsert->fetch();

        if (($row['status'] ?? 'pending') === 'done') {
            continue;                                   // kaynak değişmemiş, atla
        }

        // Zaten YouTube'da olan dilleri tekrar yazma
        $existing = array_keys($video['localizations'] ?? []);
        $missing  = array_values(array_diff($langs, $existing));
        if ($missing === []) {
            $done->execute([':c' => $ch['id'], ':v' => $vid, ':l' => toPgArray($existing)]);
            continue;
        }

        try {
            $new = localize($snip['title'], $snip['description'], $ch['source_lang'], $missing);
            if ($new === []) {
                throw new RuntimeException('Çeviri boş döndü.');
            }
            $merged = ($video['localizations'] ?? []) + $new;

            if ($dryRun) {
                logline("  [dry] $vid → " . implode(',', array_keys($new)));
                foreach ($new as $l => $x) {
                    logline("        $l: {$x['title']}");
                }
                continue;
            }

            pushLocalizations($ch, $video, $merged);
            $done->execute([':c' => $ch['id'], ':v' => $vid, ':l' => toPgArray(array_keys($merged))]);
            logline("  ✓ $vid (+" . count($new) . ' dil)');

        } catch (QuotaExhausted $e) {
            throw $e;                                   // dışarı fırlat, worker dursun
        } catch (Throwable $e) {
            $fail->execute([':c' => $ch['id'], ':v' => $vid, ':e' => $e->getMessage()]);
            logline("  ✗ $vid: " . $e->getMessage());
        }
    }
}

function explodePgArray(string $s): array
{
    return array_filter(array_map('trim', explode(',', trim($s, '{}'))));
}

function toPgArray(array $a): string
{
    return '{' . implode(',', $a) . '}';
}

// ---------------------------------------------------------------- giriş

$opts    = getopt('', ['channel::', 'all', 'dry-run']);
$dryRun  = isset($opts['dry-run']);

$sql = isset($opts['all'])
    ? 'SELECT * FROM channels WHERE active ORDER BY id'
    : 'SELECT * FROM channels WHERE id = :i';

$st = db()->prepare($sql);
$st->execute(isset($opts['all']) ? [] : [':i' => (int) ($opts['channel'] ?? 0)]);
$channels = $st->fetchAll();

if ($channels === []) {
    logline('İşlenecek kanal yok. --channel=N veya --all kullan.');
    exit(1);
}

$exit = 0;
foreach ($channels as $ch) {
    try {
        processChannel($ch, $dryRun);
    } catch (QuotaExhausted $e) {
        logline('KOTA: ' . $e->getMessage());
    } catch (Throwable $e) {
        logline('HATA (' . $ch['label'] . '): ' . $e->getMessage());
        $exit = 1;
    }
}
exit($exit);