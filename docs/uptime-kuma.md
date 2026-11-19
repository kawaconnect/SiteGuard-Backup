# SiteGuard Backup - Uptime Kuma push

The health monitor can report to an [Uptime Kuma](https://github.com/louislam/uptime-kuma)
**Push** monitor. After every health run (the scheduled cron run, "Check Now",
`wp siteguardbackup health_check` and `POST /wp-json/sgbackup/v1/health/check`)
SiteGuard sends one heartbeat for the whole site or network:

```
GET https://<kuma>/api/push/<token>?status=up|down&msg=<summary>&ping=<run time in ms>
```

One Kuma monitor then covers everything the health monitor checks, from inside
the server: every site's HTTP answer (through localhost, so Cloudflare's bot
protection is not in the way) and every certificate. It also covers what an
ICMP ping of the VM cannot see: when PHP, the database or WP-Cron is dead, no
heartbeat arrives and Kuma alerts after the heartbeat interval.

## When it says up or down

| Health result | Push |
|---|---|
| Every site operational | `up`, `12/12 sites operational` |
| Warnings only: slow site (> 5 s), SSL probe failed, certificate expiring within the warning threshold (default 14 days) but more than 3 days out, site skipped by the 55 s time budget | `up`, the warnings named: `11/12 sites operational; ssl_expiring: shop.example.com (9d)` |
| Any site down (HTTP error, timeout, refused), a certificate expired, or a certificate expiring within 3 days | `down`: `2/12 failing; down: example.com (HTTP 503); ssl_expired: example.org` |
| The health run threw, or found no sites | `down`: `health run failed: <exception class>` / `no sites checked` |
| WordPress, PHP, the database or WP-Cron not running | nothing is pushed; Kuma marks the monitor down when the heartbeat interval passes |

"Down" matches the `critical` alerts of the REST API (`site_down`,
`ssl_expired`, `ssl_expiring` at 3 days or less); "warning" alerts do not
page, so one certificate that renews in a week does not wake anyone up.
Certificate data carried forward from an earlier probe (`ssl_stale`) never
makes a push down.

The message is at most 200 characters: the count, then `label: site (reason)`
items, critical ones first. Labels: `down` (reason `HTTP <code>`, `timeout`,
`DNS`, `refused`, `SSL`, `unreachable`), `ssl_expired`, `ssl_expiring (<n>d)`,
`ssl_probe_failed`, `slow (<s>s)`, `skipped`. When the items do not fit the
message ends in `+N more`; the full list is on the Health Monitor tab and in the REST
API. `ping` is the health run's duration in milliseconds, so Kuma's response
time chart shows how long the checks take.

## Set up the monitor in Uptime Kuma

1. **Add New Monitor**
   - Monitor Type: **Push**
   - Friendly Name: e.g. `example.com WordPress (SiteGuard)`
   - **Heartbeat Interval**: at least two health check intervals plus two
     minutes. For the default 10-minute health checks: **1320 seconds**
     (anything from 1200 to 1800 works). The Health Monitor tab and
     `wp siteguardbackup health_kuma` print the value for your interval.
   - **Retries**: **0** (alert on the first missed or down heartbeat) or **1**
     (alert only when two in a row are bad).
   - Heartbeat Retry Interval: same as the heartbeat interval.
   - Resend Notification if Down X times consecutively: 0, or e.g. 6 for a
     reminder every hour.
   - Notifications: tick the ones to use (Discord, email, ...).
2. Save, then copy the **Push URL** Kuma shows
   (`https://<kuma>/api/push/<token>?status=up&msg=OK&ping=`).
3. In WordPress: **SiteGuard Backup → Health → Uptime Kuma Push**, paste the
   URL, **Save**, then **Send test push**. Or with WP-CLI:

   ```sh
   wp siteguardbackup health_kuma --push-url='https://kuma.example.com/api/push/AbC123xyz'
   wp siteguardbackup health_kuma --test
   ```

The heartbeat interval must be longer than the health check interval. If it
is shorter, Kuma reports "No heartbeat in the time window" between every
two runs.

The URL must be `https://` and its path must end in `/api/push/<token>`
(sub-paths in front are fine for Kuma behind a reverse proxy). The query
string is dropped. The Kuma host must accept requests from the WordPress
server; for a Kuma on a LAN, that means publishing it over HTTPS somewhere
the server can reach. Redirects are not followed: use the final URL (for
example the `https://` one a reverse proxy redirects to, with the right
host). A push that is answered with a redirect fails as `HTTP 301`/`302`.

## WP-Cron must run on time

Pushes happen when the health check cron runs. By default WP-Cron only runs
when someone visits the site, so on a quiet site checks run late and Kuma
reports missed heartbeats. Run WP-Cron from the system instead:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```cron
* * * * * www-data wp --path=/var/www/html cron event run --due-now >/dev/null 2>&1
# or, without WP-CLI:
* * * * * www-data curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null
```

The Health Monitor tab (once a push URL is set) and `wp siteguardbackup health_kuma`
warn when `DISABLE_WP_CRON` is not set, and when the last health run is more
than two intervals old. Disabling the health monitor stops the scheduled pushes (a manual
`wp siteguardbackup health_check` still pushes), so
Kuma reports the site down; pause the Kuma monitor first when that is
intended.

## WP-CLI

```sh
wp siteguardbackup health_kuma                 # masked URL, last push, recommended Kuma interval, cron warnings
wp siteguardbackup health_kuma --push-url=<push url>   # not --url, which is WP-CLI's global site selector
wp siteguardbackup health_kuma --test          # push once now from the last results; exit code 1 if Kuma did not accept it
wp siteguardbackup health_kuma --clear
wp siteguardbackup health_status               # includes an "Uptime Kuma push:" line
```

## The push URL is a secret

Anyone with the URL can push heartbeats to the monitor, so:

- It is shown masked after saving (`https://kuma.example.com/api/push/****xyz9`;
  a token shorter than 12 characters shows as `****` only);
  the admin page, AJAX responses and WP-CLI never return it in full.
- It is never logged. A failed push writes one line to the PHP error log
  (`SiteGuard Backup: Uptime Kuma push failed: <reason>`, with the URL and
  token masked), and one line when pushes work again; not one per run.
- It is not in the REST API, webhooks or a settings export.
- It is stored in the `sgbackup_health_kuma_url` option (the main site's on a
  multisite network; only network administrators can change it). Like every
  SiteGuard setting it is inside the site's database backups. Restoring a
  database keeps the destination's own SiteGuard options, so a standby that
  restores production's backup keeps its own Kuma URL (or none) and never
  pushes to production's monitor.

## Failures

A push that fails (timeout after 10 seconds, DNS, TLS, or Kuma answering
`{"ok":false,...}`, e.g. `Monitor not found or not active.` for a paused
monitor or a wrong token) is recorded as the "Last Push" on the Health Monitor tab
and never affects the health run or its results. Kuma sees no heartbeat and
alerts on its own.
