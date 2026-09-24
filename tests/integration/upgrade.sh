#!/usr/bin/env bash
# =============================================================================
# Release check: install the release zip through WordPress's own installer,
# and upgrade to it from the previous release with existing data.
#
#   tests/integration/upgrade.sh <instance-manager> <old-release.zip> <new-release.zip> [--keep-failed]
#
# For a single site and a multisite network (network-activated):
#   1. install OLD from its zip, configure it, make a backup, remember content
#   2. upgrade to NEW with `wp plugin install --force` (the zip upload path)
#   3. the old backup is still listed and verifies, settings survived, the
#      plugin reports the new version, a new backup works
#   4. damage the site and restore the OLD backup with the NEW code
# Plus a clean install of NEW from its zip on a fresh single site.
# =============================================================================
set -uo pipefail

IM="${1:?instance manager required}"
OLD_ZIP="${2:?old release zip required}"
NEW_ZIP="${3:?new release zip required}"
KEEP_FAILED=0; [ "${4:-}" = "--keep-failed" ] && KEEP_FAILED=1
command -v unzip >/dev/null || { echo "unzip required" >&2; exit 2; }
NEW_VER="$(unzip -p "$NEW_ZIP" siteguardbackup/siteguardbackup.php | sed -nE 's/^ \* Version: *([0-9.]+).*/\1/p' | head -1)"
OLD_VER="$(unzip -p "$OLD_ZIP" siteguardbackup/siteguardbackup.php | sed -nE 's/^ \* Version: *([0-9.]+).*/\1/p' | head -1)"
[ -n "$NEW_VER" ] && [ -n "$OLD_VER" ] || { echo "cannot read versions from the zips" >&2; exit 2; }

exec 9>/tmp/siteguard-upgrade.lock
flock -n 9 || { echo "another upgrade run is active" >&2; exit 2; }

PASS=0; FAIL=0; FAILED=(); CTX=""
ok()    { PASS=$((PASS + 1)); echo "    ok   $*"; }
bad()   { FAIL=$((FAIL + 1)); FAILED+=("$CTX: $*"); echo "    FAIL $*"; }
check() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d"; return 1; fi; }
eq()    { [ "$1" = "$2" ]; }
wp()    { local i="$1"; shift; "$IM" wp "$i" "$@" 2> >(grep -v -e wp_update -e 'Warning: .*WordPress.org' >&2); }

install_zip() { # instance zip [--network]
	local i="$1" z="$2" net="${3:-}" c; c="$("$IM" compose "$i" ps -q wordpress)"
	docker cp -q "$z" "$c:/var/www/html/wp-content/sg-release.zip"
	docker exec -u 0 "$c" chown 33:33 /var/www/html/wp-content/sg-release.zip
	local act=--activate; [ -n "$net" ] && act=--activate-network
	wp "$i" plugin install /var/www/html/wp-content/sg-release.zip --force "$act" >/dev/null
	local r=$?
	docker exec -u 0 "$c" rm -f /var/www/html/wp-content/sg-release.zip
	return $r
}

run_upgrade() { # mode
	local mode="$1" I="up-$1" U=()
	CTX="upgrade/$mode"; local fail_before=$FAIL
	echo "== upgrade $OLD_VER -> $NEW_VER on a $mode install"
	"$IM" destroy "$I" --yes >/dev/null 2>&1
	"$IM" create "$I" --mode "$mode" >/dev/null 2>&1 || { bad "create"; return; }
	local net=""; [ "$mode" = multisite ] && net=--network
	check "install $OLD_VER from its zip" install_zip "$I" "$OLD_ZIP" $net
	check "running $OLD_VER" eq "$(wp "$I" eval 'echo SGBACKUP_VERSION;')" "$OLD_VER"
	# configure + content + backup with the OLD code
	wp "$I" eval 'SiteGuard_Backup_Options::instance()->update_option("sgbackup_retain", 9); SiteGuard_Backup_Options::instance()->update_option("sgbackup_retain_db", 8);' >/dev/null
	wp "$I" option update sgx_marker before-upgrade >/dev/null
	local pid; pid="$(wp "$I" post create --post_title="SGX upgrade" --post_status=publish --porcelain)"
	wp "$I" siteguardbackup backup >/dev/null 2>&1
	local ts; ts="$(wp "$I" eval '$h = SiteGuard_Backup_History::get_history_raw(); echo $h ? max(array_keys($h)) : "";')"
	check "$OLD_VER made a backup" test -n "$ts"

	check "upgrade to $NEW_VER from its zip" install_zip "$I" "$NEW_ZIP" $net
	check "running $NEW_VER" eq "$(wp "$I" eval 'echo SGBACKUP_VERSION;')" "$NEW_VER"
	if [ "$mode" = multisite ]; then
		check "still network-active" wp "$I" plugin is-active siteguardbackup --network
	else
		check "still active" wp "$I" plugin is-active siteguardbackup
	fi
	check "settings kept" eq "$(wp "$I" eval 'echo SiteGuard_Backup_Options::instance()->get_option("sgbackup_retain"), "/", SiteGuard_Backup_Options::instance()->get_option("sgbackup_retain_db");')" "9/8"
	check "old backup still listed" wp "$I" eval "exit(isset(SiteGuard_Backup_History::get_history_raw()[$ts]) ? 0 : 1);"
	check "old backup verifies" wp "$I" siteguardbackup verify "$ts"
	check "new backup works" wp "$I" siteguardbackup backup
	check "backup dir is safe" bash -c "d=\$($(printf %q "$IM") wp $I eval 'echo SiteGuard_Backup_Options::instance()->get_backup_dir();' 2>/dev/null); [ -n \"\$d\" ] && [ \"\$d\" != / ]"

	# restore the OLD backup with the NEW code
	wp "$I" option update sgx_marker DAMAGED >/dev/null
	wp "$I" post delete "$pid" --force >/dev/null
	check "old backup restores with $NEW_VER" wp "$I" siteguardbackup restore "$ts" --entities=db --yes
	check "content back" eq "$(wp "$I" option get sgx_marker)" before-upgrade
	check "post back" test -n "$(wp "$I" post list --title='SGX upgrade' --field=ID)"
	check "still running $NEW_VER after restoring an old backup" eq "$(wp "$I" eval 'echo SGBACKUP_VERSION;')" "$NEW_VER"
	check "no PHP fatal errors" bash -c "! $(printf %q "$IM") compose $I exec -T cli sh -c 'grep -qE \"PHP (Fatal|Parse)\" wp-content/debug.log 2>/dev/null'"
	if [ $KEEP_FAILED = 1 ] && [ $FAIL -gt $fail_before ]; then echo "  (kept $I)"; else "$IM" destroy "$I" --yes >/dev/null 2>&1; fi
}

run_fresh() {
	local I=up-fresh; CTX="fresh"; local fail_before=$FAIL
	echo "== fresh install of $NEW_VER from its zip"
	"$IM" destroy "$I" --yes >/dev/null 2>&1
	"$IM" create "$I" --mode single >/dev/null 2>&1 || { bad "create"; return; }
	check "install from zip" install_zip "$I" "$NEW_ZIP"
	check "active, version $NEW_VER" eq "$(wp "$I" eval 'echo SGBACKUP_VERSION;')" "$NEW_VER"
	check "no test files shipped" bash -c "! $(printf %q "$IM") compose $I exec -T cli sh -c 'test -e wp-content/plugins/siteguardbackup/tests'"
	check "backup works" wp "$I" siteguardbackup backup
	check "status works" wp "$I" siteguardbackup status
	check "admin page renders" bash -c "$(printf %q "$IM") wp $I eval 'require_once ABSPATH . \"wp-admin/includes/admin.php\"; require_once SGBACKUP_DIR . \"admin.php\"; wp_set_current_user(1); set_current_screen(\"toplevel_page_siteguardbackup\"); ob_start(); SiteGuard_Backup_Admin::instance()->render_page(); \$h = ob_get_clean(); exit(strlen(\$h) > 1000 && false === stripos(\$h, \"Fatal error\") ? 0 : 1);'"
	check "deactivate + uninstall clean" bash -c "$(printf %q "$IM") wp $I plugin deactivate siteguardbackup && $(printf %q "$IM") wp $I plugin uninstall siteguardbackup"
	check "no sgbackup options left" eq "$(wp "$I" db query "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'sgbackup\\_%'" --skip-column-names)" 0
	check "no PHP fatal errors" bash -c "! $(printf %q "$IM") compose $I exec -T cli sh -c 'grep -qE \"PHP (Fatal|Parse)\" wp-content/debug.log 2>/dev/null'"
	if [ $KEEP_FAILED = 1 ] && [ $FAIL -gt $fail_before ]; then echo "  (kept $I)"; else "$IM" destroy "$I" --yes >/dev/null 2>&1; fi
}

run_fresh
run_upgrade single
run_upgrade multisite

echo
echo "$PASS passed, $FAIL failed"
for f in "${FAILED[@]}"; do echo "  - $f"; done
[ "$FAIL" -eq 0 ]
