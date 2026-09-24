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
php tests/test-encryption.php        # archive encryption: round trips, wrong key, truncation, tampering
```

`.github/workflows/tests.yml` lints every PHP file and runs these on each push
and pull request, on a disposable hosted runner as a non-root user.

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
| `integration/browser-flow.sh` | The everyday workflow over HTTP as a logged-in admin: Backup Now, download the set as a zip bundle (and each file), upload it, restore, verify. Same-site recovery, move to another site, chunked upload, and a multisite network. |
| `integration/transfer.sh` | Site transfers in every direction: single to single, single to a new or existing subsite, subsite to single, main site to single, subsite to a new subsite, network to network. Each over file import and over SiteGuard's own remote send. |
| `integration/upgrade.sh` | Release check: fresh install from the release zip through WordPress's installer (admin page renders, uninstall is clean), and upgrade from the previous release's zip on a single site and a network, with the old backups still verifying and restoring. |
| `integration/storage.sh` | Remote storage against local emulators (MinIO for S3, Azurite for Azure over TLS): small and multipart uploads, byte-identical downloads, listing, delete, and the unsafe-name delete guard. |

```sh
tests/integration/browser-flow.sh $WPD/scripts/instance.sh /path/to/siteguardbackup
tests/integration/transfer.sh     $WPD/scripts/instance.sh /path/to/siteguardbackup --keep-failed
tests/integration/storage.sh      $WPD/scripts/instance.sh <instance-with-plugin>
tests/integration/upgrade.sh      $WPD/scripts/instance.sh old-release.zip new-release.zip
```

The PHP tests refuse to run as root or from a web request. `tests/` is excluded
from the release zip.
