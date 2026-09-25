# SiteGuard Backup - Health Monitor REST API

External-facing REST API for Discord bots / monitoring tools. Exposes per-site HTTP reachability and SSL certificate info collected by the plugin's health monitor cron.

## Base URL

```
https://<your-site>/wp-json/sgbackup/v1
```

## Authentication

All endpoints require a Bearer token:

```
Authorization: Bearer <api_key>
```

Generate the key in **SiteGuard Backup → Health** → "Generate New Key". The plaintext key is shown **once**; only a hash is stored. Losing the key means regenerating. 48-char random string, stored via `wp_hash_password()`.

**Rate limiting.** 20 failed auth attempts per 5-minute window per client IP triggers HTTP 429. The client IP is `REMOTE_ADDR`, or `CF-Connecting-IP` when the connecting peer is a trusted proxy. `X-Forwarded-For` is never used.

**Disabled state.** If the health monitor is disabled in admin, all endpoints return `404 {"code":"disabled"}`. `define('SGBACKUP_HEALTH_ENABLED', true);` in `wp-config.php` overrides the setting on the main site. Use a real boolean (`true`/`false`, or `wp config set SGBACKUP_HEALTH_ENABLED true --raw`); a value that is not a boolean is ignored and the Health tab warns.

Restores and failover are covered in [Keys, backups, restores and failover](#keys-backups-restores-and-failover).

## Endpoints

### `GET /health`

Returns the cached health payload (refreshed by cron on the configured interval, default every 10 min).

```jsonc
{
  "status": "operational",              // operational | degraded | down | unknown
  "site_url": "https://example.com",
  "checked_at": 1775892820,             // unix ts - most recent HTTP check across all sites
  "ssl_checked_at": 1775892821,         // unix ts - most recent SSL probe across all sites
  "check_interval": 600,                // seconds
  "check_interval_label": "every10min",
  "ssl_warn_days": 14,                  // the "SSL Warning Threshold" setting
  "summary": {
    "total": 15,
    "operational": 14,
    "degraded": 1,
    "down": 0,
    "unknown": 0,
    "ssl_ok": 14,                       // sites whose last SSL probe succeeded
    "ssl_failing": 1,                   // sites whose last SSL probe failed
    "ssl_stale": 1,                     // sites showing carried-forward cert data
    "ssl_expiring_soon": 1,             // includes expired
    "ssl_expired": 0
  },
  "alerts": [
    {
      "severity": "warning",            // critical | warning | info
      "type": "ssl_probe_failed",       // see Alert types below
      "domain": "example.com",
      "message": "SSL probe failed for example.com: curl: Connection timed out after 15s (last successful probe 2026-04-10 14:22 UTC)",
      "site_id": 7,
      "ssl_last_success": 1775720000
    }
  ],
  "sites": [
    {
      "site_id": 1,
      "url": "https://example.com/",
      "domain": "example.com",
      "status": "operational",          // site-level status
      "http_code": 200,
      "response_time": 0.124,           // seconds
      "last_check": 1775892820,         // unix ts of HTTP probe

      "ssl_ok": true,                   // whether the last SSL probe succeeded
      "ssl_expiry_days": 75,            // days until expiry, -1 if unknown / never probed / http-only
      "ssl_issuer": "Let's Encrypt",
      "ssl_error": "",                  // "SSL probe failed for <domain>: <reason>" when ssl_ok=false
      "ssl_stale": false,               // true = showing carried-forward data from a previous success
      "ssl_last_success": 1775892820,   // unix ts of last successful probe (0 if none yet)
      "ssl_checked_at": 1775892820,     // unix ts of most recent probe attempt

      "error": "",                      // HTTP-level error message (e.g. "HTTP 503") or ""
      "check_method": "localhost",      // localhost | external | external_fallback | skipped
      "consecutive_failures": 0         // HTTP-down streak; resets on any successful HTTP check
    }
    // ...
  ],
  "version": "1.4.9",                   // plugin version
  "api_version": 2
}
```

### `GET /health/ping`

Minimal auth smoke-test endpoint. Returns 200 + tiny body without triggering a health run.

```json
{
  "ok": true,
  "site_url": "https://example.com",
  "version": "1.4.9",
  "api_version": 2,
  "time": 1775892820,
  "key": { "fp": "sgk-3f9c01ab22d0", "source": "db" }
}
```

`key` names the key that authenticated this request: its fingerprint, and `source` `db` (the key stored on the site) or `pin` (a hash pinned in `wp-config.php`). It is additive; bots that ignore it keep working. Compare `fp` with your key's own fingerprint: `printf %s "$KEY" | sha256sum | cut -c1-12` gives the 12 hex digits after `sgk-`.

### `POST /health/check`

Trigger an on-demand health run, then return the fresh payload (same shape as `GET /health`). Rate-limited by a **30-second global cooldown** across all callers - a 429 response with `{"code":"cooldown"}` means "poll `GET /health` for cached data or try again in a few seconds".

## Alert types

All alerts carry `severity`, `type`, `domain`, `site_id`, `message`, and optional type-specific extras.

| `type` | severity | when it fires | extras |
|---|---|---|---|
| `site_down` | critical | HTTP probe fails or returns non-2xx/3xx/403-from-localhost | - |
| `ssl_probe_failed` | warning | SSL probe failed (connect timeout, handshake failure, hostname mismatch, etc.) - the `message` is prefixed with the domain and ends with the underlying reason | `ssl_last_success` (unix ts, 0 if never) |
| `ssl_expiring` | warning (≤14d) / critical (≤3d) | Fresh probe succeeded and cert expires within `ssl_warn_days` | `ssl_expiry_days` |
| `ssl_expired` | critical | Fresh probe succeeded and reports `days <= 0` | `ssl_expiry_days` |

Alerts are only emitted on **fresh** data. If the cert data is carried forward from a previous successful probe (`ssl_stale: true`), no expiry alert is emitted - a stale "5 days left" reading might be hours or days old and is untrustworthy. The bot can still detect stale state from `summary.ssl_stale` or per-site `ssl_stale`.

## Bot implementation tips

- **Poll `GET /health` on a short interval** (~60 s is fine; the data refreshes no faster than `check_interval`, default 600 s). The endpoint is cheap - it just reads an option.
- **Use `alerts` for notifications** - each entry is pre-formatted and deduplication-friendly by `(type, domain)`.
- **Use `summary` for dashboards** - one object with counts, no need to re-reduce `sites`.
- **Use `sites` for deep dives** - full detail when a user asks "what's wrong with example.com".
- **Reserve `POST /health/check`** for user-triggered refresh (e.g. Discord `/status refresh` command). Don't call it on a timer - the cron already does that.
- **Handle 404 disabled**: if the operator disabled the health monitor, the bot should show "monitoring disabled" rather than "API down".
- **Handle 429 cooldown** on `POST /health/check`: fall back to `GET /health`.

## Keys, backups, restores and failover

### What a backup holds

A database backup contains all of `wp_options`, so it holds the monitor key's **hash** (`sgbackup_health_api_key_hash`), its metadata (`sgbackup_health_api_key_meta`: last 4 characters, fingerprint, origin, creation time) and the monitor settings. It never holds the plaintext key: only the bot has that. On WordPress 6.8 or later the hash (`$wp$2y$…`) does not depend on the site's salts, so it validates on any install running 6.8+. A 6.8+ hash cannot be checked on WordPress 6.7 or older; the Health tab and the restore output warn about that.

### What a restore does with it

The monitor key and its settings (enabled, interval, timeout, SSL warning days) are one unit. A database restore decides the unit once, right after the import, by the **health key policy**:

| This site | The backup | `keep` (default) | `backup` |
|---|---|---|---|
| has a key | has the same key | unchanged | unchanged |
| has a key | has another key | **this site's key** (the backup's can still be switched to, see below) | **the backup's key and settings** |
| has a key | has no key | this site's key | this site's key |
| has no key (fresh install, or the Health tab saved once without a key) | has a key | **the backup's key and settings**, monitor enabled if the backup's was | the backup's key and settings |
| has no key | has no key | nothing changes | nothing changes |

A plaintext key from before 1.3.13 in an old backup never replaces this site's key; when the backup's unit is used, the plaintext is hashed during the restore and not stored. Every other SiteGuard setting (storage credentials, migration keys, history, failover role, schedules) keeps this site's value, as before.

The policy applies to database restores of a single site and of a network's main site (migrate, overwrite, tables-only, selected tables) and to a subsite restored into a single site. It does not apply to skip-database or files-only restores, to importing a subsite into a network, or to batch subsite imports: the main site is not touched, and the restore says so.

The restore prints one line with the result, for example:

```
Health monitor key: kept this site's key (…a1b2, sgk-3f9c01ab22d0). The backup's key (…9f3c, from prod.example.com) was not applied. Health tab → "Switch to the backup's key" to use it.
Health monitor key: this site had no key; using the backup's key (…9f3c). Monitor enabled from the backup (this site had no key).
Health monitor key: using the backup's key (…9f3c, sgk-77d0e1c2a9b4) (policy: backup).
Health monitor key: backup has no monitor key; kept this site's.
```

The scheduled check is set to match the restored settings at the end of the restore.

### Choosing the policy

- **Per restore, browser:** the restore dialog's "Health monitor API key" choice (shown when the database is restored). The verification step shows what will happen, and warns when a backup of this same site would bring back a key that was rotated since.
- **Per restore, WP-CLI:** `wp siteguardbackup restore <ts> --health-key=backup` (or `keep`); `wp siteguardbackup import <dir> --restore --health-key=backup`. Any other value is an error.
- **Presets:** a restore preset saved from the dialog remembers the choice. Presets saved before this setting existed follow the site default.
- **Site default:** Settings → "Default Health Monitor Key on Restore". Keep the default if the bot monitors this server with this server's own key. Choose "Use the key from the backup" only if the bot polls this server with the backup site's key: it then replaces this server's own key on every restore.

### Interrupted and resumed restores

The decision is made in the request that imports the database and saved with the restore's state. A restore that is interrupted and resumed, or continued by the browser after a search-replace checkpoint, keeps that decision and never makes it again against an already-imported database.

### After a restore: see it, and change your mind

The Health tab shows the key's hint and fingerprint, where it came from, and the last restore's result. When the last restore kept this site's key although the backup had another one, **"Switch to the backup's key"** makes the backup's key the site's key (for 7 days after that restore; network administrators only). From WP-CLI:

```sh
wp siteguardbackup health_api_key                    # status: fingerprint, pin, last restore
wp siteguardbackup health_api_key --adopt-restored   # switch to the backup's key
wp siteguardbackup health_api_key --format=json
```

Neither shows a key or a hash.

### Production and a standby with one key

The bot polls one URL with one key. Check which URL and key it is configured with before changing anything on the standby.

- **The bot monitors the standby itself, with the standby's own key.** Keep the default policy (`keep`). Restores of production's backups then never change the standby's key, and the bot stays connected. Do not choose "Use the key from the backup" here: it would replace the standby's key with production's on every restore.
- **The bot polls production's domain, and a DNS failover points that domain at the standby.** This works only if the standby's **main site** answers production's main-site domain, which is the case after a raw database sync (the README's failover flow) or a network restore in mirror mode. A migrate restore keeps the standby's main site on its own domain, so no key setting makes the bot's production URL reach it. The health API also runs per site: a production domain mapped to a standby **subsite** answers with that subsite's settings (usually `404 disabled`), and the `wp-config.php` pin below applies to the main site only.

For the second case, give the standby's main site production's key in one of these ways. The first is recommended because it is additive: the standby keeps its own key as well.

1. **Pin production's key hash in the standby's `wp-config.php`.** It works whatever a restore or a raw database sync does, because `wp-config.php` is never restored:

   ```sh
   # on production
   wp siteguardbackup health_api_key --print-hash
   # prints: define('SGBACKUP_HEALTH_API_KEY_HASH', '$wp$2y$10$…');
   ```

   Add that line to the standby's `wp-config.php`, with `define('SGBACKUP_HEALTH_ENABLED', true);`. The pinned hash is accepted in addition to the standby's own key; the constant can also be an array of hashes. The Health tab shows "Pinned in wp-config". Main site only.

   The simplest way is WP-CLI on the standby, which writes both lines to `wp-config.php` with the `$` signs intact (single quotes keep the shell from expanding them):

   ```sh
   wp config set SGBACKUP_HEALTH_API_KEY_HASH '$wp$2y$10$…' --type=constant
   wp config set SGBACKUP_HEALTH_ENABLED true --raw --type=constant
   ```

   **Docker Compose (`WORDPRESS_CONFIG_EXTRA`, `.env`):** Compose reads every `$` in those values as the start of a variable. `$wp` and `$abc…` become empty and `$2y` is an error, so the hash arrives broken. Write every `$` as `$$` there, or use `wp config set` / a mounted `wp-config.php` instead. The Health tab and `wp siteguardbackup health_api_key` report a mangled hash as a malformed `SGBACKUP_HEALTH_API_KEY_HASH` entry, and it is ignored; check that line after setting the pin.
2. **Set the same key on both.** `printf %s "$KEY" | wp siteguardbackup health_api_key --set` stores the key the bot already has (read from STDIN, or prompted for with hidden input; at least 32 letters and digits). This replaces the standby's own key.
3. **Let restores bring production's key.** Set the standby's Settings → "Default Health Monitor Key on Restore" to "Use the key from the backup". This covers restores through SiteGuard only, and replaces the standby's own key on every restore.

After a raw database sync (for example `mysqldump | mysql`) or a DNS failover, run `wp siteguardbackup postfailover --flush-only` on the standby so a persistent object cache (Redis) does not serve old option values.

### Downgrading

A downgrade to 1.5.1 keeps the stored key working. The `wp-config.php` pin, the key metadata and the last-restore record are ignored by 1.5.1 (the rows are harmless), so do not rely on the pin until you have confirmed the version.

## Backward compatibility

- `api_version: 1` - original shape, no `summary`, no `alerts`, no `ssl_checked_at`, fewer SSL per-site fields.
- `api_version: 2` - adds `summary`, `alerts`, `ssl_checked_at`, `ssl_warn_days`, and the SSL per-site fields `ssl_ok` / `ssl_error` / `ssl_stale` / `ssl_last_success` / `ssl_checked_at`.

`sites[*]` retains all v1 fields. Bots on v1 keep working; migrate when convenient.

## Changelog

- **Unreleased** - `/health/ping` adds `key` (`fp`, `source`). The monitor key survives restores and failover: health key policy, key fingerprints, "Switch to the backup's key", `wp-config.php` pin (`SGBACKUP_HEALTH_API_KEY_HASH`, `SGBACKUP_HEALTH_ENABLED`), `health_api_key --set/--print-hash/--adopt-restored/--format`.

- **1.4.9** - Added `summary`, `alerts`, `ssl_checked_at`, `ssl_warn_days`, `/health/ping`, `POST /health/check`. Bumped `api_version` to 2. Per-site SSL fields (`ssl_ok`, `ssl_error`, `ssl_stale`, `ssl_last_success`, `ssl_checked_at`) exposed in the `sites` array.
- **1.4.4** - `ssl_error` messages now include the domain prefix ("SSL probe failed for {domain}: ...").
