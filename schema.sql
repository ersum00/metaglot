-- YouTube Metadata Localizer — PostgreSQL şeması
-- psql -d localizer -f schema.sql

CREATE TABLE IF NOT EXISTS channels (
    id                  SERIAL PRIMARY KEY,
    label               TEXT        NOT NULL,              -- müşteri adı, faturalama için
    yt_channel_id       TEXT,                              -- UC... (ilk çalıştırmada doldurulur)
    uploads_playlist_id TEXT,                              -- UU... (cache'lenir, her seferinde sorulmaz)
    refresh_token       TEXT        NOT NULL,              -- OAuth refresh token (müşterinin kendi GCP projesi)
    client_id           TEXT        NOT NULL,              -- müşteri bazlı OAuth client
    client_secret       TEXT        NOT NULL,
    source_lang         TEXT        NOT NULL DEFAULT 'tr',
    target_langs        TEXT[]      NOT NULL DEFAULT '{en,es,ar,de,fr,pt,hi,id,ja,ru}',
    daily_quota         INT         NOT NULL DEFAULT 10000,
    max_videos_per_run  INT         NOT NULL DEFAULT 50,
    active              BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS videos (
    id             BIGSERIAL PRIMARY KEY,
    channel_id     INT         NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    video_id       TEXT        NOT NULL,
    title          TEXT,
    published_at   TIMESTAMPTZ,
    source_hash    TEXT,                                   -- kaynak başlık+açıklama+dil listesi hash'i
    localized_langs TEXT[]     NOT NULL DEFAULT '{}',
    status         TEXT        NOT NULL DEFAULT 'pending', -- pending | done | failed | skipped
    last_error     TEXT,
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (channel_id, video_id)
);

CREATE INDEX IF NOT EXISTS videos_pending_idx
    ON videos (channel_id, status)
    WHERE status IN ('pending', 'failed');

-- Kota günü Pasifik saatiyle döner (YouTube böyle sayıyor)
CREATE TABLE IF NOT EXISTS quota_log (
    id         BIGSERIAL PRIMARY KEY,
    channel_id INT         NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    day        DATE        NOT NULL,
    op         TEXT        NOT NULL,
    units      INT         NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS quota_log_day_idx ON quota_log (channel_id, day);

-- Müşteriye rapor için: hangi gün kaç video, kaç dil
CREATE OR REPLACE VIEW v_daily_report AS
SELECT c.label,
       v.updated_at::date              AS gun,
       count(*) FILTER (WHERE v.status = 'done')   AS basarili,
       count(*) FILTER (WHERE v.status = 'failed') AS hatali,
       sum(array_length(v.localized_langs, 1))     AS toplam_dil_yazimi
FROM videos v
JOIN channels c ON c.id = v.channel_id
GROUP BY c.label, v.updated_at::date
ORDER BY gun DESC;