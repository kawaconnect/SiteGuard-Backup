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

**Rate limiting.** 20 failed auth attempts per 5-minute window per client IP triggers HTTP 429. Client IP is taken from `CF-Connecting-IP` → `X-Forwarded-For` → `REMOTE_ADDR` in that order.

**Disabled state.** If the health monitor is disabled in admin, all endpoints return `404 {"code":"disabled"}`.

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
  "time": 1775892820
}
```

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

## Backward compatibility

- `api_version: 1` - original shape, no `summary`, no `alerts`, no `ssl_checked_at`, fewer SSL per-site fields.
- `api_version: 2` - adds `summary`, `alerts`, `ssl_checked_at`, `ssl_warn_days`, and the SSL per-site fields `ssl_ok` / `ssl_error` / `ssl_stale` / `ssl_last_success` / `ssl_checked_at`.

`sites[*]` retains all v1 fields. Bots on v1 keep working; migrate when convenient.

## Changelog

- **1.4.9** - Added `summary`, `alerts`, `ssl_checked_at`, `ssl_warn_days`, `/health/ping`, `POST /health/check`. Bumped `api_version` to 2. Per-site SSL fields (`ssl_ok`, `ssl_error`, `ssl_stale`, `ssl_last_success`, `ssl_checked_at`) exposed in the `sites` array.
- **1.4.4** - `ssl_error` messages now include the domain prefix ("SSL probe failed for {domain}: ...").
