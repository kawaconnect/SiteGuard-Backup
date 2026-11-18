# Testing SiteGuard safely

SiteGuard deletes things for a living: retention pruning, restore-overwrite,
temp cleanup, uninstall. A bug in any of these that resolves a path wrongly can
walk and delete from `/`. That has happened. On 2026-09-23 a parallel test run,
executed as root on a management container, destroyed the container's entire
root filesystem, including the only copies of its config and keys.

These rules are not optional.

## Rules

1. **Never run destructive tests on a host that holds unique state.** Use a
   throwaway VM or container, and snapshot it before each run
   (`rbd snap create`, `pct snapshot`, etc.).
2. **Never run the plugin or its tests as root.** Use `wp` without
   `--allow-root`, as the web user or a dedicated unprivileged user that
   can only write inside the test site.
3. **Keep the safety net outside the blast radius.** Backups, git bundles and
   notes you want to survive a bad run belong on another machine.
4. **Run one test run per fixture at a time.** Two runs resetting the same test
   site at once corrupt each other's state. `run-sandboxed.sh` takes a lock.
5. **Shell teardown scripts** that `rm -rf` a test site must use
   `set -euo pipefail`, quote every expansion, and assert the target first:

   ```sh
   N="${1:?site name required}"
   D="/srv/sgtest/$N"
   case "$D" in /srv/sgtest/?*) ;; *) echo "refusing: $D" >&2; exit 2 ;; esac
   rm -rf -- "$D"
   ```

## Code rules

- Every file or directory delete goes through
  `SiteGuard_Backup_Filesystem::delete_file($path, $root)` or
  `::delete_tree($dir, $root)`. They refuse empty, relative, `/`, system, or
  escaping paths, never follow symlinks, and return a `WP_Error` instead of
  failing silently.
- No `@unlink` / `@rmdir`. A failed delete must be visible.
- No hand-written `RecursiveIteratorIterator` delete loops.

## Running the standalone tests

```sh
sudo tests/run-sandboxed.sh          # read-only /, tmpfs /tmp, runs as nobody
php tests/test-delete-guard.php      # or directly, as an unprivileged user
php tests/test-health-kuma.php       # Uptime Kuma push: URL validation, masking, up/down message (pure)
php tests/test-encryption.php        # archive encryption: round trips, wrong key, truncation, tampering
php tests/test-health-key-policy.php # whose health monitor key a restore keeps (pure, no files)
php tests/test-client-ip.php         # client IP: CF-Connecting-IP only from Cloudflare/loopback/SGBACKUP_TRUSTED_PROXIES, IPv6 /64 buckets
php tests/test-health-api.php        # health REST API: one key check per request, 429 on the 21st bad key, no-store headers, SSL expiry
php tests/test-method-registry.php   # every storage method: settings-save allow-list, settings panel, secrets encrypted at rest
```

The GitLab pipeline's `test` job (`ci/test.sh`, see `.gitlab-ci.yml`) lints
every PHP file and runs these in a throwaway PHP 8.3 container, as `nobody`, on
each `v*` tag, on manual pipelines and on merge requests.

## Integration tests (real WordPress)

`tests/integration/run.sh` drives SiteGuard through WP-CLI in disposable
single-site and multisite instances created with WPMultisiteDocker's
`scripts/instance.sh`. Plugin code runs only inside the instance containers
as www-data. Each destructive test fingerprints every file outside the backup
directory before and after, and fails on any change.

```sh
# on a disposable Docker host, never on a machine holding unique state
WPD=/srv/sgtest/wpdocker
$WPD/scripts/instance.sh create sgt-single --mode single
$WPD/scripts/instance.sh create sgt-multi  --mode multisite
# install the plugin into both, then:
tests/integration/run.sh $WPD/scripts/instance.sh sgt-single sgt-multi
```

Further suites, same rules (disposable host, instances from `instance.sh`):

| Script | What it proves |
|---|---|
| `integration/health-kuma.sh` | Uptime Kuma push against a fake Kuma (HTTPS, throwaway CA) on the instance mesh: up and down heartbeats naming the failing site, push failures (Kuma 404/5xx, DNS, timeout, a redirect, which is not followed) never breaking the run, a run that throws still pushing, cron and REST runs, WP-CLI set/test/clear, a database restore keeping the site's own URL, and the push token never appearing in output, logs, REST, AJAX, export or the admin page. Reuses existing instances. |
| `integration/browser-flow.sh` | The everyday workflow over HTTP as a logged-in admin: Backup Now, download the set as a zip bundle (and each file), upload it, restore, verify. Same-site recovery, move to another site, chunked upload, and a multisite network. |
| `integration/transfer.sh` | Site transfers in every direction: single to single, single to a new or existing subsite, subsite to single, main site to single, subsite to a new subsite, network to network. Each over file import and over SiteGuard's own remote send. |
| `integration/upgrade.sh` | Release check: fresh install from the release zip through WordPress's installer (admin page renders, uninstall is clean), and upgrade from the previous release's zip on a single site and a network, with the old backups still verifying and restoring. |
| `integration/storage.sh` | Every storage destination, one case each (or name cases: `s3 b2 sftppaste oauthflow ...`, list in the script header). Real servers: versitygw (S3, generic S3, B2 and GCS over TLS under their real host names), Azurite, rclone WebDAV, OpenSSH (SFTP, SCP, key file, pasted key, Ed25519 refusal), ProFTPD (FTP, explicit and implicit FTPS with certificate verification, ext-ftp fallback, missing-extension message), Swift SAIO behind Keystone v3/v2.0 (no orphaned SLO segments), Mailpit. Protocol fakes from the providers' API docs (`fake-clouds.py`): Dropbox, Google Drive, OneDrive, pCloud US/EU, each starting from an expired token. `oauthflow`: the sign-in from the settings button through the provider redirect to the REST callback (state single use, right account, survives a settings save, plain-permalinks warning). `jobs`: `wp siteguardbackup backup --send-to-cloud` to five destinations, every archive verified on each. The fakes prove the documented protocol is followed, not that the live service accepts it. |
| `integration/health-key.sh` | The health monitor key across restores between two single sites and a multisite: keep/backup policy, keyless standby, legacy keys (in the backup, and this site's own kept only as a hash in restore state), not-applicable modes, interrupted and resumed restores (no restore lock left behind), stale progress, cron, WP-CLI plumbing, the wp-config pin (including a truncated pin and a non-boolean `SGBACKUP_HEALTH_ENABLED`), and a secrets grep. Reuses existing instances; never creates or destroys any. |

```sh
tests/integration/browser-flow.sh $WPD/scripts/instance.sh /path/to/siteguardbackup
tests/integration/health-kuma.sh  $WPD/scripts/instance.sh <single-instance> <multisite-instance> [test...]
tests/integration/transfer.sh     $WPD/scripts/instance.sh /path/to/siteguardbackup --keep-failed
tests/integration/storage.sh      $WPD/scripts/instance.sh <instance-with-plugin>
tests/integration/health-key.sh   $WPD/scripts/instance.sh <src-single> <dst-single> <multisite> [scenario...]
tests/integration/upgrade.sh      $WPD/scripts/instance.sh old-release.zip new-release.zip
```

The PHP tests refuse to run as root or from a web request. `tests/` is excluded
from the release zip.
