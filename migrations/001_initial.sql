-- metaglot — initial PostgreSQL schema
-- psql -d <database> -f migrations/001_initial.sql

CREATE TABLE IF NOT EXISTS channels (
    id                  SERIAL PRIMARY KEY,
    label               TEXT        NOT NULL,              -- client name, used for billing
    yt_channel_id       TEXT,                              -- UC... (filled on first run)
    uploads_playlist_id TEXT,                              -- UU... (cached so it is not re-fetched every run)
    refresh_token       TEXT        NOT NULL,              -- OAuth refresh token (client's own GCP project)
    client_id           TEXT        NOT NULL,              -- per-client OAuth client
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
    source_hash    TEXT,                                   -- hash of source title + description + language list
    localized_langs TEXT[]     NOT NULL DEFAULT '{}',
    status         TEXT        NOT NULL DEFAULT 'pending', -- pending | done | failed | skipped
    last_error     TEXT,
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (channel_id, video_id)
);

CREATE INDEX IF NOT EXISTS videos_pending_idx
    ON videos (channel_id, status)
    WHERE status IN ('pending', 'failed');

-- The quota day rolls over on Pacific time (this is how YouTube counts it)
CREATE TABLE IF NOT EXISTS quota_log (
    id         BIGSERIAL PRIMARY KEY,
    channel_id INT         NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    day        DATE        NOT NULL,
    op         TEXT        NOT NULL,
    units      INT         NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS quota_log_day_idx ON quota_log (channel_id, day);

-- Per-client reporting: how many videos and languages per day
CREATE OR REPLACE VIEW v_daily_report AS
SELECT c.label,
       v.updated_at::date              AS day,
       count(*) FILTER (WHERE v.status = 'done')   AS succeeded,
       count(*) FILTER (WHERE v.status = 'failed') AS failed,
       sum(array_length(v.localized_langs, 1))     AS languages_written
FROM videos v
JOIN channels c ON c.id = v.channel_id
GROUP BY c.label, v.updated_at::date
ORDER BY day DESC;
