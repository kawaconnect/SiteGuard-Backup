#!/usr/bin/env bash
# =============================================================================
# SiteGuard integration tests against disposable WordPress instances.
#
#   tests/integration/run.sh <instance-manager> <single-instance> <multisite-instance> [test ...]
#
# <instance-manager> is WPMultisiteDocker's scripts/instance.sh. The instances
# must be throwaway (instance.sh create ... --mode single|multisite). All
# plugin code runs inside the instance's cli container as www-data (uid 33),
# never as root and never on the host, so a bad delete can at worst damage
# that instance's own files.
#
# Every destructive test takes a fingerprint of the WordPress tree (all files
# outside the backup directory) before and after, and fails if anything
# outside the backup directory changed.
#
# See tests/README.md for the rules on where these may run.
# =============================================================================
set -euo pipefail

IM="${1:?instance manager (WPMultisiteDocker/scripts/instance.sh) required}"
SINGLE="${2:?single-site instance name required}"
MULTI="${3:?multisite instance name required}"
shift 3
ONLY=("$@")

[ -x "$IM" ] || { echo "not executable: $IM" >&2; exit 2; }
for n in "$SINGLE" "$MULTI"; do
	[[ "$n" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || { echo "bad instance name $n" >&2; exit 2; }
done

exec 9>/tmp/siteguard-integration.lock
flock -n 9 || { echo "another integration run is active; refusing to share fixtures" >&2; exit 2; }

PASS=0; FAIL=0; FAILED=()
INST=""        # current instance
URL=()         # extra --url for multisite subsite calls

W()   { "$IM" wp "$INST" "${URL[@]}" "$@"; }
WQ()  { W "$@" 2>/dev/null; }
SH()  { "$IM" compose "$INST" exec -T cli sh -c "$1"; }   # as www-data
PHP() { W eval "$1"; }

ok()   { PASS=$((PASS + 1)); echo "  ok   $*"; }
bad()  { FAIL=$((FAIL + 1)); FAILED+=("$INST: $*"); echo "  FAIL $*"; }
check() { local d="$1"; shift; if "$@"; then ok "$d"; else bad "$d"; fi; }

backup_dir()   { PHP 'echo SiteGuard_Backup_Options::instance()->get_backup_dir();'; }
history_ts()   { PHP 'echo implode(" ", array_keys(SiteGuard_Backup_History::get_history_raw()));'; }
history_json() { PHP 'echo json_encode(SiteGuard_Backup_History::get_history_raw());'; }
set_count()    { local t; t="$(history_ts)"; set -- $t; echo "$#"; }

# Fingerprint of every file in the install except backup dirs, logs and caches.
fingerprint() {
	SH 'cd /var/www/html && find . \( -path "./wp-content/sgbackup" -o -path "./wp-content/sgbackup*" \
		-o -path "./wp-content/cache" -o -path "./wp-content/upgrade" \) -prune -o -type f \
		! -name debug.log -print0 | sort -z | xargs -0 md5sum | md5sum | cut -d" " -f1'
}

# Core files only (wp-admin, wp-includes, top-level *.php except wp-config.php).
core_fp() {
	SH 'cd /var/www/html && { find wp-admin wp-includes -type f; ls *.php | grep -v "^wp-config.php$"; } \
		| sort | xargs md5sum | md5sum | cut -d" " -f1'
}

canaries() {
	SH 'mkdir -p wp-content/uploads/sg-canary && echo keep > wp-content/uploads/sg-canary/a.txt \
		&& echo keep > wp-content/sg-canary.txt && echo keep > sg-canary-root.txt' >/dev/null
}

canaries_intact() {
	SH 'test -f wp-content/uploads/sg-canary/a.txt && test -f wp-content/sg-canary.txt && test -f sg-canary-root.txt'
}

make_backup() { W siteguardbackup backup "$@" >/dev/null; sleep 1; }

reset_state() {
	PHP '
		$o = SiteGuard_Backup_Options::instance();
		foreach (array_keys(SiteGuard_Backup_History::get_history_raw()) as $ts) { SiteGuard_Backup_History::delete_backup_set($ts, false); }
		$o->update_option("sgbackup_retain", 50);
		$o->update_option("sgbackup_retain_db", 50);
		$o->update_option("sgbackup_retain_extrarules", array());
		$o->delete_option("sgbackup_dir");
	' >/dev/null
	SH 'rm -f wp-content/mu-plugins/sg-test-*.php' >/dev/null
}

want() {
	[ ${#ONLY[@]} -eq 0 ] && return 0
	local t; for t in "${ONLY[@]}"; do [ "$t" = "$1" ] && return 0; done
	return 1
}

# -----------------------------------------------------------------------------
t_backup_verify() {
	make_backup
	local ts; ts="$(history_ts)"; ts="${ts##* }"
	check "backup recorded in history" test -n "$ts"
	check "verify passes" W siteguardbackup verify "$ts"
	local dir; dir="$(backup_dir)"
	check "backup dir is not / and is absolute" test "$dir" != "/" -a "${dir:0:1}" = "/"
	check "archives exist on disk" SH "ls '$dir' | grep -q '\\.zip\$'"
}

t_delete_scoped() {
	make_backup; make_backup
	local before; before="$(fingerprint)"
	local all; all="$(history_ts)"; set -- $all
	local victim="$1" survivor="$2"
	W siteguardbackup delete "$victim" --yes >/dev/null
	local after; after="$(history_ts)"
	check "deleted set gone from history" test "${after/$victim/}" = "$after"
	check "other set survives" grep -qw "$survivor" <<<"$after"
	check "nothing outside the backup dir changed" test "$before" = "$(fingerprint)"
	check "canaries intact" canaries_intact
}

t_retention_count() {
	PHP 'SiteGuard_Backup_Options::instance()->update_option("sgbackup_retain", 2); SiteGuard_Backup_Options::instance()->update_option("sgbackup_retain_db", 2);' >/dev/null
	local before; before="$(fingerprint)"
	make_backup; make_backup; make_backup; make_backup
	check "retain=2 keeps 2 sets (have $(set_count))" test "$(set_count)" -le 2
	check "nothing outside the backup dir changed" test "$before" = "$(fingerprint)"
	check "canaries intact" canaries_intact
}

# The incident's trigger: "retention extra rule for db must not delete file archives".
t_retention_extrarule_db() {
	PHP 'SiteGuard_Backup_Options::instance()->update_option("sgbackup_retain_extrarules",
		array("db" => array(array("after_count" => 1, "keep_every" => 86400))));' >/dev/null
	local before; before="$(fingerprint)"
	make_backup; make_backup; make_backup
	local h; h="$(history_json)"
	local sets_with_files
	sets_with_files="$(php_count_files "$h")"
	check "all 3 sets keep their file archives (have $sets_with_files)" test "$sets_with_files" -eq 3
	check "db pruned to at most 2 dumps in one day bucket" test "$(php_count_db "$h")" -le 2
	check "nothing outside the backup dir changed" test "$before" = "$(fingerprint)"
	check "canaries intact" canaries_intact
}
php_count_files() { jq '[.[] | select((.plugins // .themes // .others // null) != null)] | length' <<<"$1"; }
php_count_db()    { jq '[.[] | select(.db != null and .db != "" and .db != [])] | length' <<<"$1"; }

t_cleanup_symlink() {
	local dir; dir="$(backup_dir)"
	make_backup
	# An old orphan temp dir that is a symlink to the uploads tree, and a real one.
	SH "ln -sfn /var/www/html/wp-content/uploads '${dir}dbtemp_link' && mkdir -p '${dir}dbtemp_old/x' \
		&& echo junk > '${dir}dbtemp_old/x/f' && touch -h -d @\$(( \$(date +%s) - 172800 )) '${dir}dbtemp_link' '${dir}dbtemp_old'" >/dev/null
	local before; before="$(fingerprint)"
	W siteguardbackup cleanup >/dev/null 2>&1 || true
	PHP 'SiteGuard_Backup_Filesystem::clean_temp_files(SiteGuard_Backup_Options::instance()->get_backup_dir(), 60);' >/dev/null
	check "old orphan temp dir removed" SH "test ! -e '${dir}dbtemp_old'"
	check "uploads behind a symlinked temp dir untouched" test "$before" = "$(fingerprint)"
	check "canaries intact" canaries_intact
	SH "rm -f '${dir}dbtemp_link'" >/dev/null
}

t_hostile_backup_dir() {
	local before; before="$(fingerprint)"
	local core; core="$(core_fp)"
	SH 'mkdir -p wp-content/mu-plugins' >/dev/null
	local v
	for v in "" "/" "/var/www" "/var/www/html" "/etc" "/tmp"; do
		PHP "\$v = '$v'; wp_mkdir_p(WP_CONTENT_DIR . '/mu-plugins');
			file_put_contents(WP_CONTENT_DIR . '/mu-plugins/sg-test-dir.php',
				'<?php add_filter(\"sgbackup_backup_dir\", function () { return ' . var_export(\$v, true) . '; });');" >/dev/null
		check "hostile filter '$v' is loaded" test "$(PHP 'echo has_filter("sgbackup_backup_dir") ? "y" : "n";')" = y
		local d; d="$(backup_dir 2>/dev/null)"
		check "filter '$v' rejected (got $d)" bash -c '[ "$1" != "/" ] && case "$1" in /|/etc*|/tmp*|/var/www/|/var/www/html/) false;; *) true;; esac' _ "$d"
	done
	SH 'rm -f wp-content/mu-plugins/sg-test-dir.php' >/dev/null
	for v in / /etc /var/www /var/www/html; do
		PHP "SiteGuard_Backup_Options::instance()->update_option('sgbackup_dir', '$v');" >/dev/null
		local d; d="$(backup_dir 2>/dev/null)"
		check "option sgbackup_dir=$v rejected (got $d)" test "${d%/}" != "${v%/}"
	done
	PHP 'SiteGuard_Backup_Options::instance()->delete_option("sgbackup_dir");' >/dev/null
	make_backup
	W siteguardbackup cleanup >/dev/null 2>&1 || true
	check "nothing outside the backup dir changed" test "$before" = "$(fingerprint)"
	check "core files unchanged" test "$core" = "$(core_fp)"
}

t_restore_db() {
	local core; core="$(core_fp)"
	local pid; pid="$(W post create --post_title='SG original' --post_status=publish --porcelain)"
	make_backup
	local ts; ts="$(history_ts)"; ts="${ts##* }"
	W post update "$pid" --post_title='SG changed' >/dev/null
	W siteguardbackup restore "$ts" --entities=db --yes >/dev/null 2>&1 || true
	check "db restore brought the post title back" test "$(WQ post get "$pid" --field=post_title)" = "SG original"
	check "canaries intact after restore" canaries_intact
	check "core files unchanged after restore" test "$core" = "$(core_fp)"
	check "site still answers" W option get siteurl
}

t_multisite_scoping() {
	[ "$INST" = "$MULTI" ] || return 0
	local sub; sub="$(W site list --field=url | grep '/sub1/')"
	URL=(--url="$sub")
	reset_state
	make_backup
	local sub_dir; sub_dir="$(backup_dir)"
	check "subsite uses its own site-N backup dir ($sub_dir)" grep -q '/site-[0-9]*/$' <<<"$sub_dir"
	URL=()
	local main_before; main_before="$(history_ts)"
	make_backup
	local main_ts; main_ts="$(history_ts)"
	URL=(--url="$sub")
	PHP 'SiteGuard_Backup_Options::instance()->update_option("sgbackup_retain", 1); SiteGuard_Backup_Options::instance()->update_option("sgbackup_retain_db", 1);' >/dev/null
	make_backup; make_backup
	check "subsite retention kept 1 set" test "$(set_count)" -le 1
	URL=()
	check "main-site history untouched by subsite pruning" test "$(history_ts)" = "$main_ts"
	URL=(--url="$sub"); reset_state; URL=()
}

# Upgrading from in-webroot backups: stubs must stay while archives remain.
t_legacy_cleanup() {
	local L=/var/www/html/wp-content/sgbackup-legacytest
	# Use a scratch legacy root by pointing WP_CONTENT_DIR's sgbackup at a copy is not
	# possible, so exercise the real one with the backup dir moved elsewhere.
	PHP 'wp_mkdir_p(WP_CONTENT_DIR . "/mu-plugins");
		file_put_contents(WP_CONTENT_DIR . "/mu-plugins/sg-test-dir.php", "<?php add_filter(\"sgbackup_backup_dir\", function () { return \"/tmp/sg-moved\"; });");' >/dev/null
	local legacy; legacy="$(PHP 'echo WP_CONTENT_DIR;')/sgbackup"
	# Seed: plugin stubs + a real archive in the legacy dir.
	PHP 'delete_site_transient("sgbackup_legacy_cleanup"); SiteGuard_Backup_Options::instance()->get_backup_dir();' >/dev/null
	SH "mkdir -p '$legacy' && echo data > '$legacy/backup_legacy-db.gz'" >/dev/null
	PHP '$o = SiteGuard_Backup_Options::instance(); $m = new ReflectionMethod($o, "protect_backup_dir"); $m->setAccessible(true); $m->invoke($o, WP_CONTENT_DIR . "/sgbackup");' >/dev/null
	PHP 'delete_site_transient("sgbackup_legacy_cleanup"); echo SiteGuard_Backup_Options::instance()->get_backup_dir();' >/dev/null
	check "legacy archive left in place"            SH "test -f '$legacy/backup_legacy-db.gz'"
	check "legacy .htaccess kept while archive remains" SH "test -f '$legacy/.htaccess'"
	check "legacy web.config kept while archive remains" SH "test -f '$legacy/web.config'"
	check "leftover recorded for the admin notice"  test "$(PHP 'echo count((array) SiteGuard_Backup_Options::instance()->get_option("sgbackup_legacy_leftovers", array()));')" -ge 1
	# Leave only the plugin's stubs (earlier tests' logs live here too on a reused instance).
	SH "cd '$legacy' && for f in \$(ls -A); do case \"\$f\" in .htaccess|.user.ini|index.php|index.html|web.config) ;; *) rm -rf -- \"\$f\" ;; esac; done" >/dev/null
	PHP 'delete_site_transient("sgbackup_legacy_cleanup"); SiteGuard_Backup_Options::instance()->get_backup_dir();' >/dev/null
	check "stub-only legacy dir removed once empty" SH "test ! -e '$legacy'"
	SH 'rm -f wp-content/mu-plugins/sg-test-dir.php' >/dev/null
	PHP 'SiteGuard_Backup_Options::instance()->get_backup_dir();' >/dev/null
}

t_pre_restore_backup() {
	make_backup
	local ts; ts="$(history_ts)"; ts="${ts##* }"
	local before; before="$(set_count)"
	W siteguardbackup restore "$ts" --entities=db --pre-restore-backup --yes >/dev/null 2>&1
	check "pre-restore safety backup added" test "$(set_count)" -eq $((before + 1))
	check "safety backup is always-keep and labelled" test "$(PHP '$n = 0; foreach (SiteGuard_Backup_History::get_history_raw() as $b) { if (!empty($b["always_keep"]) && "Pre-restore" === ($b["label"] ?? "")) $n++; } echo $n;')" -ge 1
	check "SiteGuard still active after restore" W eval 'exit(class_exists("SiteGuard_Backup") ? 0 : 1);'
}

# A history entry that names another backup's archive must not be able to delete it.
t_delete_shared_file_protected() {
	make_backup; make_backup
	local all; all="$(history_ts)"; set -- $all
	local a="$1" b="$2"
	# Poison entry A with one of B's archives.
	PHP "\$h = SiteGuard_Backup_History::get_history_raw(); \$h[$a]['plugins'][] = \$h[$b]['plugins'][0]; SiteGuard_Backup_History::save_history(\$h);" >/dev/null
	local shared; shared="$(PHP "echo SiteGuard_Backup_History::get_history_raw()[$b]['plugins'][0];")"
	local dir; dir="$(backup_dir)"
	W siteguardbackup delete "$a" --yes >/dev/null
	check "archive still used by another backup survives" SH "test -f '$dir$shared'"
	check "that backup still verifies" W siteguardbackup verify "$b"
}

# Remote delete must refuse names that would address the whole backup folder.
t_remote_delete_names() {
	check "unsafe remote names are filtered" test "$(PHP 'require_once SGBACKUP_DIR . "methods/backup-module.php"; require_once SGBACKUP_DIR . "methods/ftp.php";
		$m = new SiteGuard_Backup_Method_Ftp(); $r = new ReflectionMethod($m, "safe_remote_names"); $r->setAccessible(true);
		echo json_encode($r->invoke($m, array("", ".", "..", "a/b.zip", "..\\x", "ok.zip", null, "x\0y")));')" = '["ok.zip"]'
}

# Every byte of awkward content must survive dump + restore unchanged.
t_data_fidelity() {
	local seed='
		$v = array(
			"sgx_json"    => "{\"url\":\"http:\\/\\/example.org\\/a\",\"pct\":\"100%\",\"q\":\"it\x27s\"}",
			"sgx_ser"     => array("url" => "http://example.org/x", "nested" => array("é" => "中文 🐍", 7 => true, "f" => 1.5), "pct" => "%s %d %%"),
			"sgx_unicode" => "héllo - 🐍 中文 עברית \u{1F600}",
			"sgx_quotes"  => "single \x27 double \" backslash \\ tab\t newline\n cr\r end",
			"sgx_pct"     => "100% LIKE \x27%a_b%\x27 {43ded7db8220f9320535a3aa7ca010fd0da6724531b8493a05e5c6388a06739f}",
			"sgx_big"     => str_repeat("0123456789abcdef", 65536),
		);
		foreach ($v as $k => $x) update_option($k, $x, false);
		$pid = wp_insert_post(array("post_title" => "SGX fidelity", "post_status" => "publish", "post_content" => $v["sgx_quotes"] . $v["sgx_unicode"]));
		update_post_meta($pid, "sgx_bin", base64_encode(random_bytes(3000)));
		update_option("sgx_fid_pid", $pid, false);'
	PHP "$seed" >/dev/null
	local fp='$o = array(); foreach (array("sgx_json","sgx_ser","sgx_unicode","sgx_quotes","sgx_pct","sgx_big") as $k) $o[$k] = get_option($k);
		$pid = (int) get_option("sgx_fid_pid"); $o["content"] = get_post_field("post_content", $pid); $o["bin"] = get_post_meta($pid, "sgx_bin", true);
		echo md5(serialize($o));'
	local before; before="$(PHP "$fp")"
	make_backup
	local ts; ts="$(history_ts)"; ts="${ts##* }"
	# Scramble everything, then restore.
	PHP 'foreach (array("sgx_json","sgx_ser","sgx_unicode","sgx_quotes","sgx_pct","sgx_big") as $k) update_option($k, "changed", false);
		$pid = (int) get_option("sgx_fid_pid"); wp_update_post(array("ID" => $pid, "post_content" => "changed")); update_post_meta($pid, "sgx_bin", "changed");' >/dev/null
	check "content scrambled before restore" test "$(PHP "$fp")" != "$before"
	W siteguardbackup restore "$ts" --entities=db --yes >/dev/null 2>&1
	check "every byte restored (serialized, JSON, unicode, quotes, %, 1 MB value, binary meta)" test "$(PHP "$fp")" = "$before"
	check "serialized option still an array" test "$(PHP 'echo is_array(get_option("sgx_ser")) ? "y" : "n";')" = y
}

# -----------------------------------------------------------------------------
TESTS=(t_legacy_cleanup t_backup_verify t_delete_scoped t_retention_count t_retention_extrarule_db t_cleanup_symlink t_hostile_backup_dir t_restore_db t_data_fidelity t_pre_restore_backup t_delete_shared_file_protected t_remote_delete_names t_multisite_scoping)

command -v jq >/dev/null || { echo "jq is required" >&2; exit 2; }

for INST in "$SINGLE" "$MULTI"; do
	echo "== instance $INST"
	URL=()
	W plugin is-active siteguardbackup >/dev/null 2>&1 || W plugin is-active siteguardbackup --network >/dev/null 2>&1 \
		|| { echo "siteguardbackup not active on $INST" >&2; exit 2; }
	SH 'rm -f wp-content/debug.log' >/dev/null
	canaries
	for t in "${TESTS[@]}"; do
		want "$t" || continue
		echo "-- $t"
		reset_state
		"$t" || bad "$t aborted"
	done
	reset_state
	if SH 'test -f wp-content/debug.log && grep -E "PHP (Fatal|Parse)" wp-content/debug.log' ; then
		bad "PHP fatal errors in debug.log"
	else
		ok "no PHP fatal errors in debug.log"
	fi
done

echo
echo "$PASS passed, $FAIL failed"
for f in "${FAILED[@]}"; do echo "  - $f"; done
[ "$FAIL" -eq 0 ]
