# Google Cloud setup — the long version

This is the full walkthrough for getting metaglot talking to the YouTube Data API v3:
a Google Cloud project, an OAuth client, and a refresh token for each channel.
It is deliberately text-only — screenshots go stale, menu names mostly don't.

If you manage channels for clients, create **one Google Cloud project per client**.
Quota is counted per project, and a client parting ways takes their project (and
their quota, and their credentials) with them cleanly.

## 1. Create a project

1. Open [console.cloud.google.com](https://console.cloud.google.com) with the Google
   account that should own the credentials.
2. Project picker (top bar) → **New project**. Name it after the channel or client.
3. Wait for creation to finish and make sure the new project is selected.

## 2. Enable the YouTube Data API v3

1. **APIs & Services → Library**.
2. Search for "YouTube Data API v3" and open it.
3. Click **Enable**.

## 3. Configure the OAuth consent screen

1. **APIs & Services → OAuth consent screen**.
2. User type: **External** (unless the channel's account lives in your Google Workspace
   organization, in which case Internal is simpler and skips the rest of this section's
   caveats).
3. Fill in the app name and the support / developer contact e-mail addresses. Nothing
   else is required.
4. **Scopes** step: add
   `https://www.googleapis.com/auth/youtube.force-ssl`
   — metaglot needs it to write video metadata. No other scope is needed.
5. Finish the wizard, then — **this is the step everyone skips** — press
   **Publish app** so the status reads **In production**.

Why publishing matters: while the consent screen is in **Testing**, every refresh token
it issues **expires after 7 days**. The tool works for a week, then dies with
`invalid_grant`. In production, the refresh token lives until it is revoked.

Publishing an app that uses a sensitive scope normally triggers Google's verification
process. You do **not** need to complete verification to use your own tool: authorization
still works, Google just shows an "unverified app" warning that the channel owner clicks
through ("Advanced" → "Go to <app> (unsafe)"). For a self-hosted tool operating on
channels you control, that is the expected state.

## 4. Create the OAuth client

1. **APIs & Services → Credentials → Create credentials → OAuth client ID**.
2. Application type: **Desktop app**. Name it anything.
3. Note the **Client ID** and **Client secret**. They go into the `channels` table —
   never into a file in this repository.

## 5. Obtain a refresh token

The channel owner has to approve access once; after that, metaglot refreshes access
tokens on its own. The flow below uses the loopback redirect that Desktop clients
allow — no web server needed.

1. Build the authorization URL (replace `CLIENT_ID`):

   ```
   https://accounts.google.com/o/oauth2/v2/auth?client_id=CLIENT_ID&redirect_uri=http://127.0.0.1:8080&response_type=code&scope=https://www.googleapis.com/auth/youtube.force-ssl&access_type=offline&prompt=consent
   ```

   `access_type=offline` is what makes Google issue a refresh token at all, and
   `prompt=consent` forces one to be issued even if the account approved the app before.

2. Open the URL in a browser **signed in as the channel's Google account**, and approve
   the requested access (clicking through the unverified-app warning if it appears).

3. The browser is redirected to `http://127.0.0.1:8080/?code=4/0Ab...&scope=...` and the
   page fails to load — that is fine. Copy the value of the `code` parameter from the
   address bar. The code is single-use and expires within minutes, so do the next step
   right away.

4. Exchange the code for tokens (replace all three placeholders):

   ```sh
   curl -s https://oauth2.googleapis.com/token \
     -d code=AUTH_CODE \
     -d client_id=CLIENT_ID \
     -d client_secret=CLIENT_SECRET \
     -d redirect_uri=http://127.0.0.1:8080 \
     -d grant_type=authorization_code
   ```

5. The JSON answer contains `refresh_token`. Store it in the channel row — that is the
   long-lived credential metaglot uses from now on. (If the answer has an `access_token`
   but **no** `refresh_token`, the account had already authorized the app and
   `prompt=consent` was missing from the URL in step 1.)

## 6. Register the channel in PostgreSQL

```sh
createdb metaglot                      # or use an existing database
psql -d metaglot -f migrations/001_initial.sql
```

```sql
INSERT INTO channels (label, refresh_token, client_id, client_secret, source_lang, target_langs)
VALUES ('my-channel',
        '<refresh-token from step 5>',
        '<client-id from step 4>',
        '<client-secret from step 4>',
        'tr',                                     -- language of the original titles
        '{en,es,ar,de,fr,pt,hi,id,ja,ru}');       -- languages to produce
```

`yt_channel_id` and `uploads_playlist_id` are filled automatically on the first run.
Optional columns worth knowing: `daily_quota` (default 10000 — the project's quota),
`max_videos_per_run` (default 50), `active` (set false to pause a channel).

Then verify end to end without writing anything:

```sh
php bin/metaglot --channel=1 --dry-run
```

## 7. Quota

Every project gets **10,000 units/day** by default, which is roughly **190 localized
videos per day** (see the quota math in the [README](../README.md#quota-math)). The
day resets at midnight Pacific time. If a channel's backlog is larger, either let the
hourly cron chew through it over a few days — metaglot stops cleanly at the limit and
resumes tomorrow — or request a quota increase via the
[YouTube API quota audit form](https://support.google.com/youtube/contact/yt_api_form).

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `invalid_grant` after ~7 days of working fine | Consent screen still in **Testing** — publish it to In production (section 3) and re-authorize once (section 5). |
| Token exchange returns no `refresh_token` | Missing `prompt=consent` / `access_type=offline` in the authorization URL, or the code was reused. Redo section 5 step 1–4. |
| `redirect_uri_mismatch` during exchange | The `redirect_uri` in step 4 must be byte-identical to the one in step 1. |
| `quotaExceeded` although the local meter shows headroom | Another tool shares the project's quota, or the meter's history was truncated. Wait for the Pacific-midnight reset; give metaglot its own project. |
| `accessNotConfigured` | The YouTube Data API v3 is not enabled in this project (section 2). |
