#!/usr/bin/env bash
# =============================================================================
# Health monitor key across restores, against disposable WordPress instances.
#
#   tests/integration/health-key.sh <instance-manager> <src-single> <dst-single> <multisite> [scenario ...]
#
# <instance-manager> is WPMultisiteDocker's scripts/instance.sh. The three
# instances must already exist, be up, and have the plugin active. This script
# never creates, destroys or stops an instance. <src-single> plays production,
# <dst-single> the standby. Scenarios: H1 ... H20 (default: all implemented).
#
# All plugin code runs inside the instances' cli containers as www-data. Keys
# live only in this script's memory. Takes the shared integration lock (waits
# up to 20 minutes). See tests/README.md for where this may run.
#
# Optional environment:
#   HK151_PLUGIN  path to a SiteGuard 1.5.1 plugin directory: enables H19
#                 (golden diff against 1.5.1) and H20 (downgrade).
#   HK_PLUGIN     path to this build's plugin directory (reinstalled after H19/H20).
# =============================================================================
set -uo pipefail

IM="${1:?instance manager (WPMultisiteDocker/scripts/instance.sh) required}"
SRC="${2:?source single-site instance required}"
DST="${3:?destination single-site instance required}"
MULTI="${4:?multisite instance required}"
shift 4
ONLY=("$@")

[ -x "$IM" ] || { echo "not executable: $IM" >&2; exit 2; }
for n in "$SRC" "$DST" "$MULTI"; do
	[[ "$n" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || { echo "bad instance name $n" >&2; exit 2; }
done
command -v jq >/dev/null || { echo "jq is required" >&2; exit 2; }
INSTDIR="$(cd "$(dirname "$IM")/.." && pwd)/instances"

exec 9>/tmp/siteguard-integration.lock
flock -w 1200 9 || { echo "another integration run is active; gave up waiting" >&2; exit 2; }

PASS=0; FAIL=0; FAILED=(); SCEN=""

ok()    { PASS=$((PASS + 1)); echo "  ok   $*"; }
bad()   { FAIL=$((FAIL + 1)); FAILED+=("$SCEN: $*"); echo "  FAIL $*"; }
check() { local d="$1"; shift; if "$@"; then ok "$d"; else bad "$d"; fi; }
want()  {
	[ ${#ONLY[@]} -eq 0 ] && return 0
	local t; for t in "${ONLY[@]}"; do [ "$t" = "$1" ] && return 0; done
	return 1
}

W()  { local n="$1"; shift; "$IM" wp "$n" "$@" 2>/dev/null; }
WE() { local n="$1"; shift; "$IM" wp "$n" "$@" 2>&1; }          # with stderr (restore output)
E()  { "$IM" wp "$1" eval "$2" 2>/dev/null; }
SH() { "$IM" compose "$1" exec -T cli sh -c "$2"; }            # as www-data
port() { (. "$INSTDIR/$1/.env"; echo "$HTTP_PORT"); }

# --- REST auth -----------------------------------------------------------------
IPN=0
# auth <inst> <key|""> [path] [site-path] -> "<http code>[ <error code>]"
auth() {
	IPN=$((IPN + 1))
	local out code body err
	out="$(curl -s -w '\n%{http_code}' -H "Host: $1.test" -H "CF-Connecting-IP: 198.51.100.$((IPN % 250 + 1))" \
		${2:+-H "Authorization: Bearer $2"} "http://127.0.0.1:$(port "$1")${4:-}/?rest_route=/sgbackup/v1/${3:-health/ping}")"
	code="${out##*$'\n'}"; body="${out%$'\n'*}"
	err="$(jq -r 'if type == "object" then (.code // empty) else empty end' <<<"$body" 2>/dev/null)"
	LAST_BODY="$body"
	echo "$code${err:+ $err}"
}
expect_auth() { # <desc> <want> <inst> <key> [path] [site-path]
	local d="$1" w="$2"; shift 2
	local got; got="$(auth "$@")"
	if [ "$got" = "$w" ]; then ok "$d ($got)"; else bad "$d (got '$got', want '$w')"; fi
}
clear_rl() { E "$1" 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%sgbackup\\_health\\_rl\\_%\" OR option_name LIKE \"%sgbackup\\_health\\_bot\\_check%\""); wp_cache_flush(); echo 1;' >/dev/null; }

# --- keys and state --------------------------------------------------------------
genkey() { W "$1" siteguardbackup health_api_key --regenerate | sed -n 's/^API Key: //p'; }
enable() { W "$1" siteguardbackup health_config enable --interval="${2:-every10min}" >/dev/null; }
wipe_health() { # remove every health row (both scopes) and the scheduled check
	E "$1" 'global $wpdb;
		foreach (array("sgbackup_health_api_key_hash","sgbackup_health_api_key","sgbackup_health_api_key_meta","sgbackup_health_enabled","sgbackup_health_interval","sgbackup_health_timeout","sgbackup_health_ssl_warn_days","sgbackup_health_last_restore") as $n) {
			$wpdb->delete($wpdb->options, array("option_name" => $n));
			if (is_multisite()) $wpdb->delete($wpdb->sitemeta, array("meta_key" => $n));
		}
		wp_clear_scheduled_hook("sgbackup_health_check"); wp_cache_flush(); echo 1;' >/dev/null
}
reset_restore_state() {
	E "$1" 'delete_transient("sgbackup_restore_progress"); delete_site_option("sgbackup_restore_progress");
		foreach (array("sgbackup_restore_sr_state","sgbackup_sr_progress","sgbackup_restore_preserved","sgbackup_restore") as $n) delete_option($n);
		echo 1;' >/dev/null
}
fp() { printf %s "$1" | sha256sum | cut -c1-12 | sed 's/^/sgk-/'; }
option_raw() { E "$1" "global \$wpdb; echo (string) \$wpdb->get_var(\$wpdb->prepare(\"SELECT option_value FROM {\$wpdb->options} WHERE option_name = %s\", '$2'));"; }
has_row() { test -n "$(E "$1" "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->options} WHERE option_name = %s\", '$2')) ?: '';")"; }
no_row()  { ! has_row "$@"; }
# Non-health SiteGuard rows (name => md5 of the raw value), for "nothing else changed".
sg_rows() {
	E "$1" 'global $wpdb; $o = array();
		foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE \"sgbackup\\_%\" ORDER BY option_name", ARRAY_A) as $r) {
			if (preg_match("/^sgbackup_(health_(api_key|api_key_hash|api_key_meta|enabled|interval|timeout|ssl_warn_days|last_restore)$|restore_preserved$|restore$|sem_|jobdata_|dbtables_|rl_|restore_sr_state$|sr_progress$)/", $r["option_name"])) continue;
			$o[$r["option_name"]] = md5($r["option_value"]);
		}
		ksort($o); echo json_encode($o);'
}
# Every row this site had keeps its value. Rows only the backup had are added,
# as in 1.5.1 (H19 compares the whole set against 1.5.1).
same_rows() { # <inst> <before-json>
	local after diff; after="$(sg_rows "$1")"
	diff="$(jq -n -c --argjson a "$2" --argjson b "$after" '[$a | keys[] | select($a[.] != $b[.])]')"
	[ "$diff" = "[]" ] && return 0
	echo "    rows changed or lost: $diff"
	return 1
}
cron_schedule() { E "$1" 'echo wp_next_scheduled("sgbackup_health_check") ? wp_get_schedule("sgbackup_health_check") : "none";'; }
last_restore() { W "$1" siteguardbackup health_api_key --format=json | jq -c '.key.last_restore'; }

# --- backups and transport -----------------------------------------------------
ts_of_nonce() { E "$1" "foreach (SiteGuard_Backup_History::get_history_raw() as \$t => \$s) { if (isset(\$s['nonce']) && \$s['nonce'] === '$2') { echo \$t; break; } }"; }
make_backup() { # <inst> [extra args] -> timestamp
	local n="$1"; shift
	local before; before="$(E "$n" 'echo implode(" ", array_keys(SiteGuard_Backup_History::get_history_raw()));')"
	W "$n" siteguardbackup backup --include-files=plugins "$@" >/dev/null
	sleep 1
	E "$n" "\$b = array_filter(explode(' ', '$before')); foreach (array_keys(SiteGuard_Backup_History::get_history_raw()) as \$t) { if (!in_array((string) \$t, \$b, true)) { echo \$t; break; } }"
}
# Copy a set's archives to another instance and register it there under the same
# timestamp (file transport, like transfer.sh). `wp siteguardbackup import` names
# sets by the minute in the file name, so several transfers in one minute would
# overwrite each other's history entry; this keeps the source's exact timestamp.
transfer() { # <from> <ts> <to> [from --url] -> timestamp on <to>
	local from="$1" ts="$2" to="$3" U=() set nonce dir todir
	[ -n "${4:-}" ] && U=(--url="$4")
	set="$("$IM" wp "$from" "${U[@]}" eval "\$h = SiteGuard_Backup_History::get_history_raw(); echo isset(\$h[$ts]) ? base64_encode(serialize(\$h[$ts])) : '';" 2>/dev/null)"
	nonce="$("$IM" wp "$from" "${U[@]}" eval "\$h = SiteGuard_Backup_History::get_history_raw(); echo \$h[$ts]['nonce'];" 2>/dev/null)"
	dir="$("$IM" wp "$from" "${U[@]}" eval 'echo SiteGuard_Backup_Options::instance()->get_backup_dir();' 2>/dev/null)"
	todir="$(E "$to" 'echo SiteGuard_Backup_Options::instance()->get_backup_dir();')"
	[ -n "$set" ] && [ -n "$nonce" ] || { echo "transfer: no set $ts on $from" >&2; return 1; }
	SH "$from" "cd '$dir' && tar cf - backup_*${nonce}*" | SH "$to" "cd '$todir' && tar xf -"
	E "$to" "SiteGuard_Backup_History::add_backup($ts, unserialize(base64_decode('$set'))); echo 1;" >/dev/null
	echo "$ts"
}
# Stage a set's archives in <to>'s wp-content/sg-incoming/<dir>/ for `import`.
stage_import() { # <from> <ts> <to> <dir>
	local nonce dir
	nonce="$(E "$1" "\$h = SiteGuard_Backup_History::get_history_raw(); echo \$h[$2]['nonce'];")"
	dir="$(E "$1" 'echo SiteGuard_Backup_Options::instance()->get_backup_dir();')"
	SH "$1" "cd '$dir' && tar cf - backup_*${nonce}*" \
		| SH "$3" "mkdir -p /var/www/html/wp-content/sg-incoming/$4 && cd /var/www/html/wp-content/sg-incoming/$4 && tar xf -"
}
restore() { # <inst> <ts> [args...] -> output
	local n="$1" ts="$2"; shift 2
	WE "$n" siteguardbackup restore "$ts" --yes "$@"
}
grep_out() { grep -qF -- "$2" <<<"$1"; }

# -----------------------------------------------------------------------------
# Fixture: production (SRC) with key KA, a post linking to its own URL and a
# marker; a backup of it transferred to the standby (DST).
KA=""; KB=""; TS_A=""; TS_A_ON_B=""
fixture() {
	[ -n "$TS_A_ON_B" ] && return 0
	reset_restore_state "$SRC"
	wipe_health "$SRC"
	W "$SRC" siteguardbackup health_config enable --interval=every5min --timeout=7 >/dev/null
	KA="$(genkey "$SRC")"
	W "$SRC" option update kk_marker src >/dev/null
	W "$SRC" post create --post_title='HK link' --post_status=publish --post_content="see http://$SRC.test/hk-page/" >/dev/null
	TS_A="$(make_backup "$SRC")"
	TS_A_ON_B="$(transfer "$SRC" "$TS_A" "$DST")"
	echo "  fixture: production backup $TS_A (on standby: $TS_A_ON_B), KA $(fp "$KA")"
}
# Standby with its own key KB and sentinels in non-health rows.
standby() { # [enabled interval]
	reset_restore_state "$DST"
	wipe_health "$DST"
	W "$DST" siteguardbackup health_config enable --interval="${1:-every15min}" >/dev/null
	KB="$(genkey "$DST")"
	E "$DST" 'SiteGuard_Backup_Options::instance()->update_option("sgbackup_retain", 37); SiteGuard_Backup_Options::instance()->update_option("sgbackup_failover_role", "standby"); echo 1;' >/dev/null
	W "$DST" option update kk_marker dst >/dev/null
	clear_rl "$DST"
}

# -----------------------------------------------------------------------------
H1() { # same-site restore of an older backup after a key rotation
	reset_restore_state "$SRC"; wipe_health "$SRC"; enable "$SRC"
	local k1 k2 ts out
	k1="$(genkey "$SRC")"; ts="$(make_backup "$SRC")"; k2="$(genkey "$SRC")"; clear_rl "$SRC"
	out="$(restore "$SRC" "$ts" --entities=db)"
	check "default keeps this site's key: result line" grep_out "$out" "Health monitor key: kept this site's key"
	expect_auth "current key K2 works" 200 "$SRC" "$k2"
	expect_auth "old key K1 refused" "403 forbidden" "$SRC" "$k1"
	check "the declined backup key can be adopted" test "$(W "$SRC" siteguardbackup health_api_key --format=json | jq -r '.key.has_declined')" = true
	out="$(restore "$SRC" "$ts" --entities=db --health-key=backup)"
	check "policy backup: result line" grep_out "$out" "using the backup's key"
	check "policy backup on a same-site rotation warns" grep_out "$out" "older monitor key that was rotated"
	clear_rl "$SRC"
	expect_auth "policy backup: K1 works" 200 "$SRC" "$k1"
	expect_auth "policy backup: K2 refused" "403 forbidden" "$SRC" "$k2"
	TS_A_ON_B=""   # production's live key changed: rebuild the fixture next time
}

H2() { # production -> standby, default policy; then adopt
	fixture; standby
	local before out; before="$(sg_rows "$DST")"
	out="$(restore "$DST" "$TS_A_ON_B" --entities=db --db-restore-mode=migrate)"
	check "result line names the backup key's hint" grep_out "$out" "The backup's key (…${KA: -4}"
	expect_auth "standby key KB still works" 200 "$DST" "$KB"
	expect_auth "production key KA refused" "403 forbidden" "$DST" "$KA"
	check "last restore offers the declined key" test "$(W "$DST" siteguardbackup health_api_key --format=json | jq -r '.key.has_declined')" = true
	check "non-health SiteGuard rows unchanged" same_rows "$DST" "$before"
	check "retain sentinel kept" test "$(option_raw "$DST" sgbackup_retain)" = 37
	out="$(WE "$DST" siteguardbackup health_api_key --adopt-restored)"
	check "adopt succeeds" grep_out "$out" "Switched to the backup's key"
	clear_rl "$DST"
	expect_auth "after adopt: KA works" 200 "$DST" "$KA"
	expect_auth "after adopt: KB refused" "403 forbidden" "$DST" "$KB"
	check "adopt cannot run twice" bash -c '! "$0" wp "$1" siteguardbackup health_api_key --adopt-restored >/dev/null 2>&1' "$IM" "$DST"
}

H3() { # production -> standby, policy backup (flag, then site default)
	fixture; standby
	local before out; before="$(sg_rows "$DST")"
	out="$(restore "$DST" "$TS_A_ON_B" --entities=db --health-key=backup)"
	check "flag: result line" grep_out "$out" "using the backup's key"
	expect_auth "flag: KA works" 200 "$DST" "$KA"
	expect_auth "flag: KB refused" "403 forbidden" "$DST" "$KB"
	check "interval came from production" test "$(option_raw "$DST" sgbackup_health_interval)" = every5min
	check "timeout came from production" test "$(option_raw "$DST" sgbackup_health_timeout)" = 7
	auth "$DST" "$KA" >/dev/null
	check "/ping reports the standby's own site_url" test "$(jq -r .site_url <<<"$LAST_BODY")" = "http://$DST.test"
	check "/ping names the matched key" test "$(jq -r .key.fp <<<"$LAST_BODY")" = "$(fp "$KA")"
	check "non-health SiteGuard rows unchanged" same_rows "$DST" "$before"
	standby
	E "$DST" 'SiteGuard_Backup_Options::instance()->update_option("sgbackup_default_health_key_policy", "backup"); echo 1;' >/dev/null
	out="$(restore "$DST" "$TS_A_ON_B" --entities=db)"
	check "site default backup: result line" grep_out "$out" "using the backup's key"
	expect_auth "site default backup: KA works" 200 "$DST" "$KA"
	expect_auth "site default backup: KB refused" "403 forbidden" "$DST" "$KB"
	E "$DST" 'SiteGuard_Backup_Options::instance()->update_option("sgbackup_default_health_key_policy", "keep"); echo 1;' >/dev/null
}

H4() { # fresh-install destination (no health rows at all)
	fixture
	local p out
	for p in keep backup; do
		reset_restore_state "$DST"; wipe_health "$DST"; clear_rl "$DST"
		out="$(restore "$DST" "$TS_A_ON_B" --entities=db --health-key=$p)"
		expect_auth "$p: KA works" 200 "$DST" "$KA"
		check "$p: monitor enabled" test "$(option_raw "$DST" sgbackup_health_enabled)" = 1
	done
}

H5() { # keyless standby whose Health tab was saved once (enabled=0)
	fixture
	reset_restore_state "$DST"; wipe_health "$DST"; clear_rl "$DST"
	W "$DST" option update sgbackup_health_enabled 0 >/dev/null
	local out; out="$(restore "$DST" "$TS_A_ON_B" --entities=db)"
	check "log line: monitor enabled from the backup" grep_out "$out" "Monitor enabled from the backup"
	expect_auth "KA works (1.5.1 gave 404 disabled)" 200 "$DST" "$KA"
}

H6() { # the backup has no key
	reset_restore_state "$SRC"; wipe_health "$SRC"
	local ts tsb out
	ts="$(make_backup "$SRC")"; tsb="$(transfer "$SRC" "$ts" "$DST")"
	standby
	out="$(restore "$DST" "$tsb" --entities=db --health-key=backup)"
	check "result line: backup has no key" grep_out "$out" "backup has no monitor key; kept this site's"
	expect_auth "KB works" 200 "$DST" "$KB"
	TS_A_ON_B=""   # production's key was wiped: rebuild the fixture next time
}

H7() { # legacy plaintext key in the backup
	reset_restore_state "$SRC"; wipe_health "$SRC"; enable "$SRC"
	local legacy ts tsb h1 h2
	legacy="$(E "$SRC" 'echo wp_generate_password(48, false, false);')"
	W "$SRC" option update sgbackup_health_api_key "$legacy" >/dev/null
	ts="$(make_backup "$SRC")"; tsb="$(transfer "$SRC" "$ts" "$DST")"
	standby
	restore "$DST" "$tsb" --entities=db >/dev/null
	expect_auth "keep: KB works (1)" 200 "$DST" "$KB"
	h1="$(option_raw "$DST" sgbackup_health_api_key_hash)"
	expect_auth "keep: KB works (2)" 200 "$DST" "$KB"
	expect_auth "keep: KB works (3)" 200 "$DST" "$KB"
	h2="$(option_raw "$DST" sgbackup_health_api_key_hash)"
	check "hash row unchanged between calls" test "$h1" = "$h2"
	expect_auth "keep: legacy key refused" "403 forbidden" "$DST" "$legacy"
	check "no legacy plaintext row" no_row "$DST" sgbackup_health_api_key
	standby
	restore "$DST" "$tsb" --entities=db --health-key=backup >/dev/null
	expect_auth "backup: legacy key works" 200 "$DST" "$legacy"
	check "backup: legacy key stored only as a hash" no_row "$DST" sgbackup_health_api_key
	check "backup: plaintext appears nowhere in the options table" test -z "$(E "$DST" "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->options} WHERE option_value LIKE %s\", '%$legacy%')) ?: '';")"
	TS_A_ON_B=""
}

H8() { # restores that do not touch the main site's database
	fixture
	local mode out before hash
	for mode in "--db-restore-mode=skip-db" "--db-restore-mode=tables-only" "--tables=wp_posts" "files-only"; do
		standby
		before="$(sg_rows "$DST")"; hash="$(option_raw "$DST" sgbackup_health_api_key_hash)"
		if [ "$mode" = files-only ]; then
			# One plugin only (the CLI's --restore-plugins does not select items).
			out="$("$IM" wp "$DST" eval "require_once SGBACKUP_DIR . 'restorer.php';
				\$r = new SiteGuard_Backup_Restorer(SiteGuard_Backup_History::get_history($TS_A_ON_B), array('plugins' => true, 'restore_plugins_items' => array('akismet'), 'health_key_policy' => 'backup'));
				\$res = \$r->perform_restore(); echo implode(\"\n\", \$res['messages']); foreach (\$res['errors'] as \$e) echo \"\nError: \$e\";" 2>&1)"
		else
			# shellcheck disable=SC2086
			out="$(restore "$DST" "$TS_A_ON_B" --health-key=backup --entities=db $mode)"
		fi
		check "$mode: no error" bash -c '! grep -q "Error:" <<<"$0"' "$out"
		check "$mode: key hash unchanged" test "$(option_raw "$DST" sgbackup_health_api_key_hash)" = "$hash"
		check "$mode: SiteGuard rows unchanged" same_rows "$DST" "$before"
		check "$mode: says what happened" bash -c 'grep -qE "not applicable|same key|no monitor key|this site.s key" <<<"$0"' "$out"
		expect_auth "$mode: KB works" 200 "$DST" "$KB"
	done
}

# Crash a restore (run through wp eval with a hook) when progress reaches <entity>:<status>.
crash_restore() { # <inst> <ts> <entity:status> <php restore options array>
	"$IM" wp "$1" eval "
		add_action('set_transient_sgbackup_restore_progress', function (\$v) {
			list(\$e, \$s) = explode(':', '$3');
			if (isset(\$v['entities'][\$e]) && \$v['entities'][\$e] === \$s) { echo \"SIMULATED CRASH at $3\n\"; exit(3); }
		});
		require_once SGBACKUP_DIR . 'restorer.php';
		\$r = new SiteGuard_Backup_Restorer(SiteGuard_Backup_History::get_history($2), $4);
		\$r->perform_restore();" 2>&1
}

H9() { # interrupted after the DB phase, resumed from completed phases
	fixture
	local p out before
	for p in keep backup; do
		standby
		before="$(sg_rows "$DST")"
		out="$(crash_restore "$DST" "$TS_A_ON_B" plugins:started "array('db' => true, 'plugins' => true, 'restore_plugins_items' => array('akismet'), 'health_key_policy' => '$p')")"
		check "$p: crashed after the database" grep_out "$out" "SIMULATED CRASH at plugins:started"
		if [ "$p" = keep ]; then expect_auth "$p: before resume, KB works" 200 "$DST" "$KB"; else expect_auth "$p: before resume, KA works" 200 "$DST" "$KA"; fi
		check "$p: this site's history is back before the resume" test -n "$(ts_of_nonce "$DST" "$(E "$SRC" "\$h = SiteGuard_Backup_History::get_history($TS_A); echo \$h['nonce'];")")"
		out="$(WE "$DST" siteguardbackup resume_restore)"
		check "$p: resume finds the backup" bash -c '! grep -q "Backup not found" <<<"$0"' "$out"
		check "$p: resume skips the database" grep_out "$out" "Database already restored"
		check "$p: resume writes this site's settings back" grep_out "$out" "plugin config options"
		clear_rl "$DST"
		if [ "$p" = keep ]; then
			expect_auth "$p: after resume, KB works" 200 "$DST" "$KB"
			expect_auth "$p: after resume, KA refused" "403 forbidden" "$DST" "$KA"
		else
			expect_auth "$p: after resume, KA works" 200 "$DST" "$KA"
			expect_auth "$p: after resume, KB refused" "403 forbidden" "$DST" "$KB"
		fi
		check "$p: non-health SiteGuard rows restored" same_rows "$DST" "$before"
		check "$p: snapshot row removed" no_row "$DST" sgbackup_restore_preserved
		check "$p: progress cleared" test -z "$(E "$DST" 'echo get_transient("sgbackup_restore_progress") ? "left" : "";')"
		# The interrupted run's restore lock row is in the saved snapshot; writing it
		# back would refuse every restore for an hour.
		check "$p: no restore lock left behind" no_row "$DST" sgbackup_restore
	done
}

H10() { # crash right after the import of a migration, before search-replace
	fixture; standby
	local out prog
	out="$(crash_restore "$DST" "$TS_A_ON_B" db_import:completed "array('db' => true, 'db_restore_mode' => 0)")"
	check "crashed after the import" grep_out "$out" "SIMULATED CRASH at db_import:completed"
	prog="$(E "$DST" 'echo json_encode(get_transient("sgbackup_restore_progress"));')"
	check "progress keyed to the real timestamp ($(jq -r .timestamp <<<"$prog"))" test "$(jq -r .timestamp <<<"$prog")" = "$TS_A_ON_B"
	check "progress carries the backup set" test "$(jq -r '.backup_set.nonce // empty' <<<"$prog")" != ""
	check "progress carries only the policy, no key" bash -c '[ "$(jq -r .options.health_key_policy <<<"$0")" = keep ] && ! grep -q "\$2y\$\|\$wp\$" <<<"$0"' "$prog"
	out="$(WE "$DST" siteguardbackup resume_restore)"
	check "no stale checkpoint discarded" bash -c '! grep -q "Discarding stale search-replace checkpoint" <<<"$0"' "$out"
	check "import re-run with the saved settings" grep_out "$out" "Reusing destination settings saved by the interrupted run"
	check "search-replace ran (no source URL in posts)" test "$(E "$DST" "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->posts} WHERE post_content LIKE '%$SRC.test%'\");")" = 0
	clear_rl "$DST"
	expect_auth "policy result holds: KB works" 200 "$DST" "$KB"
	check "retain sentinel kept" test "$(option_raw "$DST" sgbackup_retain)" = 37
}

H11() { # stale progress with timestamp 0 must not skip another backup's database
	fixture; standby
	E "$DST" 'set_transient("sgbackup_restore_progress", array("timestamp" => 0, "options" => array("db" => true), "entities" => array("db" => "completed"), "started_at" => time()), 86400); echo 1;' >/dev/null
	local out; out="$(restore "$DST" "$TS_A_ON_B" --entities=db)"
	check "database not skipped" bash -c '! grep -q "skipped (already restored in interrupted run)" <<<"$0"' "$out"
	check "database actually imported (marker from production)" test "$(W "$DST" option get kk_marker)" = src
}

H12() { # multisite main site
	local M="$MULTI" k1 k2 ks ts out sub
	reset_restore_state "$M"; wipe_health "$M"
	W "$M" siteguardbackup health_config enable >/dev/null
	sub="$(W "$M" site list --field=url | grep '/sub1/' | head -1)"
	k1="$(genkey "$M")"; ts="$(make_backup "$M")"; k2="$(genkey "$M")"; clear_rl "$M"
	[ -n "$sub" ] && "$IM" wp "$M" --url="$sub" option update sgbackup_retain 29 >/dev/null 2>&1
	out="$(restore "$M" "$ts" --entities=db --subsite-mode=mirror)"
	check "keep: result line" grep_out "$out" "kept this site's key"
	expect_auth "keep: K2 works" 200 "$M" "$k2"
	expect_auth "keep: K1 refused" "403 forbidden" "$M" "$k1"
	if [ -n "$sub" ]; then
		expect_auth "/sub1/ health still disabled" "404 disabled" "$M" "$k2" health/ping /sub1
		check "sub1 keeps its own SiteGuard rows (retain sentinel)" test "$("$IM" wp "$M" --url="$sub" option get sgbackup_retain 2>/dev/null)" = 29
	fi
	restore "$M" "$ts" --entities=db --subsite-mode=mirror --health-key=backup >/dev/null; clear_rl "$M"
	expect_auth "backup: K1 works" 200 "$M" "$k1"
	expect_auth "backup: K2 refused" "403 forbidden" "$M" "$k2"
	# This site's key only in wp_sitemeta (legacy network fallback); the backup has a blog-1 key.
	ks="$(E "$M" 'echo wp_generate_password(48, false, false);')"
	E "$M" "global \$wpdb; \$wpdb->delete(\$wpdb->options, array('option_name' => 'sgbackup_health_api_key_hash')); \$wpdb->delete(\$wpdb->options, array('option_name' => 'sgbackup_health_api_key_meta'));
		update_site_option('sgbackup_health_api_key_hash', wp_hash_password('$ks')); wp_cache_flush(); echo 1;" >/dev/null
	clear_rl "$M"
	expect_auth "sitemeta-only key works before" 200 "$M" "$ks"
	restore "$M" "$ts" --entities=db --subsite-mode=mirror >/dev/null; clear_rl "$M"
	expect_auth "sitemeta-only key still works after a keep restore" 200 "$M" "$ks"
	expect_auth "the backup's blog-1 key does not shadow it" "403 forbidden" "$M" "$k1"
	E "$M" 'delete_site_option("sgbackup_health_api_key_hash"); echo 1;' >/dev/null
	# Import a subsite backup as a subsite with --health-key=backup: the main site is untouched.
	if [ -n "$sub" ]; then
		local k3 subts sid main_before
		k3="$(genkey "$M")"
		subts="$("$IM" wp "$M" --url="$sub" siteguardbackup backup --subsite-only >/dev/null 2>&1; sleep 1; "$IM" wp "$M" --url="$sub" eval 'echo max(array_keys(SiteGuard_Backup_History::get_history_raw()));' 2>/dev/null)"
		sid="$(E "$M" "echo get_blog_id_from_url(parse_url('$sub', PHP_URL_HOST), parse_url('$sub', PHP_URL_PATH));")"
		main_before="$(E "$M" 'global $wpdb; echo md5(json_encode($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE \"sgbackup\\_health\\_%\" AND option_name NOT LIKE \"sgbackup\\_health\\_results%\" AND option_name NOT LIKE \"sgbackup\\_health\\_last\\_run%\" ORDER BY option_name")));')"
		if [ -n "$subts" ]; then
			out="$("$IM" wp "$M" --url="$sub" siteguardbackup restore "$subts" --entities=db --subsite-mode=import-as --target-subsite-id="${sid:-2}" --health-key=backup --yes 2>&1)"
			check "import-as: not applicable notice" grep_out "$out" "not applicable"
			check "import-as: main-site health rows byte-identical" test "$(E "$M" 'global $wpdb; echo md5(json_encode($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE \"sgbackup\\_health\\_%\" AND option_name NOT LIKE \"sgbackup\\_health\\_results%\" AND option_name NOT LIKE \"sgbackup\\_health\\_last\\_run%\" ORDER BY option_name")));')" = "$main_before"
			clear_rl "$M"
			expect_auth "import-as: main key still works" 200 "$M" "$k3"
		else
			bad "could not make a subsite backup"
		fi
	fi
}

H13() { # a subsite restored into a single site
	local M="$MULTI" sub ks ts tsb out
	sub="$(W "$M" site list --field=url | grep '/sub1/' | head -1)"
	[ -n "$sub" ] || { bad "no /sub1/ subsite on $M"; return; }
	"$IM" wp "$M" --url="$sub" siteguardbackup health_config enable >/dev/null 2>&1
	ks="$("$IM" wp "$M" --url="$sub" siteguardbackup health_api_key --regenerate 2>/dev/null | sed -n 's/^API Key: //p')"
	"$IM" wp "$M" --url="$sub" siteguardbackup backup --subsite-only >/dev/null 2>&1; sleep 1
	ts="$("$IM" wp "$M" --url="$sub" eval 'echo max(array_keys(SiteGuard_Backup_History::get_history_raw()));' 2>/dev/null)"
	tsb="$(transfer "$M" "$ts" "$DST" "$sub")"
	reset_restore_state "$DST"; wipe_health "$DST"; clear_rl "$DST"
	out="$(restore "$DST" "$tsb" --entities=db)"
	check "keyless single site: result line" grep_out "$out" "this site had no key"
	expect_auth "subsite's key works on the single site" 200 "$DST" "$ks"
	standby
	restore "$DST" "$tsb" --entities=db >/dev/null; clear_rl "$DST"
	expect_auth "single site with its own key keeps it" 200 "$DST" "$KB"
	expect_auth "subsite's key refused there" "403 forbidden" "$DST" "$ks"
	"$IM" wp "$M" --url="$sub" siteguardbackup health_config disable >/dev/null 2>&1
}

H14() { # a browser-style restore that checkpoints search-replace, then continues
	fixture; standby
	local prog out
	out="$("$IM" wp "$DST" eval "
		define('DOING_AJAX', true);
		add_filter('sgbackup_restore_sr_budget_seconds', function () { return 1; });
		add_action('add_option_sgbackup_restore_sr_state', function () { sleep(17); });
		require_once SGBACKUP_DIR . 'restorer.php';
		ob_start();
		\$r = new SiteGuard_Backup_Restorer(SiteGuard_Backup_History::get_history($TS_A_ON_B), array('db' => true, 'db_restore_mode' => 0, 'health_key_policy' => 'backup'));
		\$res = \$r->perform_restore();
		ob_end_clean();
		echo empty(\$res['incomplete']) ? 'complete' : 'incomplete';" 2>&1 | tail -1)"
	check "search-replace checkpointed" test "$out" = incomplete
	prog="$(E "$DST" 'echo json_encode(get_transient("sgbackup_restore_progress"));')"
	check "saved options carry health_key_policy=backup" test "$(jq -r .options.health_key_policy <<<"$prog")" = backup
	check "history found for the continuation" test -n "$(E "$DST" "echo SiteGuard_Backup_History::get_history($TS_A_ON_B) ? 'y' : '';")"
	clear_rl "$DST"
	expect_auth "decision already applied before the continuation: KA works" 200 "$DST" "$KA"
	out="$(WE "$DST" siteguardbackup resume_restore)"
	check "continuation resumed search-replace" grep_out "$out" "Resuming search-replace"
	clear_rl "$DST"
	expect_auth "after the continuation: KA works" 200 "$DST" "$KA"
	expect_auth "after the continuation: KB refused" "403 forbidden" "$DST" "$KB"
	check "retain sentinel kept" test "$(option_raw "$DST" sgbackup_retain)" = 37
	check "no restore lock left behind by the first request" no_row "$DST" sgbackup_restore
	# A bogus per-restore value falls back to the site default.
	check "bogus policy falls back to keep" test "$(E "$DST" "require_once SGBACKUP_DIR . 'restorer.php'; \$r = new SiteGuard_Backup_Restorer(array('nonce' => 'x', 'timestamp' => 1), array('health_key_policy' => 'BACKUP')); \$p = new ReflectionProperty(\$r, 'health_key_policy'); \$p->setAccessible(true); echo \$p->getValue(\$r);")" = keep
}

H15() { # the scheduled check follows the restored settings
	reset_restore_state "$SRC"; wipe_health "$SRC"; TS_A_ON_B=""
	local ts tsb
	ts="$(make_backup "$SRC")"; tsb="$(transfer "$SRC" "$ts" "$DST")"     # backup without a health event
	standby every15min
	restore "$DST" "$tsb" --entities=db >/dev/null
	check "enabled standby keeps its event at its interval" test "$(cron_schedule "$DST")" = every15min
	fixture
	standby every15min; W "$DST" siteguardbackup health_config disable >/dev/null
	restore "$DST" "$TS_A_ON_B" --entities=db >/dev/null
	check "disabled standby (backup enabled) has no event" test "$(cron_schedule "$DST")" = none
	standby every15min
	restore "$DST" "$TS_A_ON_B" --entities=db >/dev/null
	check "interval mismatch rescheduled to this site's (every15min)" test "$(cron_schedule "$DST")" = every15min
}

H16() { # WP-CLI plumbing
	fixture; standby
	local out rc
	out="$(WE "$DST" siteguardbackup restore "$TS_A_ON_B" --entities=db --health-key=foo --yes)"; rc=$?
	check "--health-key=foo fails" test "$rc" -ne 0
	check "--health-key=foo lists the values" grep_out "$out" "keep, backup"
	W "$DST" siteguardbackup presets save --name=hk-backup --db=1 --health_key_policy=backup >/dev/null
	out="$(restore "$DST" "$TS_A_ON_B" --preset=hk-backup)"
	check "preset with backup applies" grep_out "$out" "using the backup's key"
	standby
	out="$(restore "$DST" "$TS_A_ON_B" --preset=hk-backup --health-key=keep)"
	check "flag overrides the preset" grep_out "$out" "kept this site's key"
	E "$DST" '$p = SiteGuard_Backup_Options::instance()->get_option("sgbackup_restore_presets", array()); $p[] = array("name" => "hk-old", "created" => time(), "restore_options" => array("db" => true)); SiteGuard_Backup_Options::instance()->update_option("sgbackup_restore_presets", $p); echo 1;' >/dev/null
	standby
	E "$DST" 'SiteGuard_Backup_Options::instance()->update_option("sgbackup_default_health_key_policy", "backup"); echo 1;' >/dev/null
	out="$(restore "$DST" "$TS_A_ON_B" --preset=hk-old)"
	check "an old preset follows the site default" grep_out "$out" "using the backup's key"
	E "$DST" 'require_once SGBACKUP_DIR . "includes/class-commands.php"; (new SiteGuard_Backup_Commands())->savesettings(array("default_health_key_policy" => "bogus")); echo 1;' >/dev/null
	check "savesettings stores a bogus default as keep" test "$(option_raw "$DST" sgbackup_default_health_key_policy)" = keep
	standby
	local stage="hk-$(date +%s%N)"
	stage_import "$SRC" "$TS_A" "$DST" "$stage"
	out="$("$IM" wp "$DST" siteguardbackup import "/var/www/html/wp-content/sg-incoming/$stage/" --restore --entities=db --health-key=foo 2>&1)"; rc=$?
	check "import --health-key=foo fails before importing" bash -c '[ "$0" -ne 0 ] && ! grep -q "Imported:" <<<"$1"' "$rc" "$out"
	standby
	out="$("$IM" wp "$DST" siteguardbackup import "/var/www/html/wp-content/sg-incoming/$stage/" --restore --entities=db --health-key=backup 2>&1)"
	check "import --restore --health-key=backup applies the backup's key" grep_out "$out" "using the backup's key"
	clear_rl "$DST"
	expect_auth "import --restore --health-key=backup: KA works" 200 "$DST" "$KA"
}

H17() { # wp-config pin
	TS_A_ON_B=""; fixture; standby
	local hash out kc
	hash="$(W "$SRC" siteguardbackup health_api_key --print-hash | sed -n "s/^define('SGBACKUP_HEALTH_API_KEY_HASH', '\(.*\)');$/\1/p")"
	check "--print-hash prints a define" test -n "$hash"
	W "$DST" config set SGBACKUP_HEALTH_API_KEY_HASH "$hash" --type=constant >/dev/null
	W "$DST" config set SGBACKUP_HEALTH_ENABLED true --raw --type=constant >/dev/null
	# PHP-FPM's opcache may serve the old wp-config.php for up to its
	# revalidate interval; wait (at most ~90 s) until the web side sees the pin.
	local i
	for i in $(seq 1 18); do
		clear_rl "$DST"
		[ "$(auth "$DST" "$KA")" = 200 ] && break
		sleep 5
	done
	clear_rl "$DST"
	expect_auth "pinned KA works" 200 "$DST" "$KA"
	auth "$DST" "$KA" >/dev/null
	check "/ping says the pin matched" test "$(jq -r .key.source <<<"$LAST_BODY")" = pin
	restore "$DST" "$TS_A_ON_B" --entities=db >/dev/null; clear_rl "$DST"
	expect_auth "(a) keep restore: KA still works" 200 "$DST" "$KA"
	expect_auth "(a) keep restore: KB works" 200 "$DST" "$KB"
	check "Health status shows the pin" test "$(W "$DST" siteguardbackup health_api_key --format=json | jq -r .key.pinned)" = 1
	# Stand-in for a raw database sync: the stored key and the enabled switch are
	# replaced by other values; the pin and SGBACKUP_HEALTH_ENABLED still win.
	kc="$(genkey "$DST")"; W "$DST" option update sgbackup_health_enabled 0 >/dev/null; clear_rl "$DST"
	expect_auth "(c) DB key replaced and disabled: KA still works" 200 "$DST" "$KA"
	W "$DST" config delete SGBACKUP_HEALTH_API_KEY_HASH >/dev/null
	W "$DST" config delete SGBACKUP_HEALTH_ENABLED >/dev/null
	W "$DST" option update sgbackup_health_enabled 1 >/dev/null
	out="$(printf %s "$KA" | "$IM" wp "$DST" siteguardbackup health_api_key --set 2>&1)"
	check "--set from STDIN" grep_out "$out" "API key set"
	clear_rl "$DST"
	expect_auth "--set: KA works without the pin" 200 "$DST" "$KA"
	out="$(printf %s "short0123456789" | "$IM" wp "$DST" siteguardbackup health_api_key --set 2>&1)"
	check "--set rejects a key shorter than 32 characters" grep_out "$out" "at least 32"
}

H18() { # secrets never leak (run last: it greps everything the other scenarios produced)
	local k leaks=0 where
	for where in SRC DST MULTI; do
		local n="${!where}" dir blob
		dir="$(E "$n" 'echo SiteGuard_Backup_Options::instance()->get_backup_dir();')"
		blob="$(SH "$n" "cat '$dir'log.*.txt 2>/dev/null")$(E "$n" 'echo json_encode(array(get_option("sgbackup_audit_log"), get_site_option("sgbackup_audit_log")));')$(E "$n" 'echo json_encode(get_transient("sgbackup_restore_progress"));')$(E "$n" 'require_once SGBACKUP_DIR . "includes/class-commands.php"; echo json_encode((new SiteGuard_Backup_Commands())->get_health_status(array()));')"
		for k in "$KA" "$KB"; do
			[ -n "$k" ] && grep -qF -- "$k" <<<"$blob" && { leaks=$((leaks + 1)); echo "    plaintext key found on $n"; }
		done
		if grep -qE '\$wp\$2y\$|\$2y\$[0-9]{2}\$|\$P\$[./A-Za-z0-9]{8}' <<<"$blob"; then leaks=$((leaks + 1)); echo "    hash found on $n"; fi
	done
	auth "$DST" "$KA" >/dev/null
	grep -qE '\$wp\$|\$2y\$|\$P\$' <<<"$LAST_BODY" && leaks=$((leaks + 1))
	check "no plaintext key or hash in logs, audit log, progress, health status or /ping" test "$leaks" -eq 0
}

H19() { # golden: the same restore under 1.5.1 and this build
	[ -n "${HK151_PLUGIN:-}" ] && [ -n "${HK_PLUGIN:-}" ] || { echo "  skip (HK151_PLUGIN/HK_PLUGIN not set)"; return 0; }
	fixture
	local base v151 vnew
	standby
	base="$(E "$DST" 'global $wpdb; echo base64_encode(serialize($wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE \"sgbackup\\_%\"", ARRAY_A)));')"
	put_base() { E "$DST" "global \$wpdb; \$wpdb->query(\"DELETE FROM {\$wpdb->options} WHERE option_name LIKE 'sgbackup\\\\_%'\"); foreach (unserialize(base64_decode('$base')) as \$r) \$wpdb->insert(\$wpdb->options, \$r); wp_cache_flush(); echo 1;" >/dev/null; }
	dump() { E "$DST" 'global $wpdb; $o = array(); foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE \"sgbackup\\_%\" ORDER BY option_name", ARRAY_A) as $r) { if (preg_match("/^sgbackup_(sem_|jobdata_|restore$|health_results$|health_last_run$)/", $r["option_name"])) continue; $o[$r["option_name"]] = md5($r["option_value"]); } echo json_encode($o);'; }
	"$IM" install-plugin "$DST" "$HK151_PLUGIN" --activate >/dev/null
	put_base; restore "$DST" "$TS_A_ON_B" --entities=db >/dev/null; v151="$(dump)"
	"$IM" install-plugin "$DST" "$HK_PLUGIN" --activate >/dev/null
	put_base; restore "$DST" "$TS_A_ON_B" --entities=db --health-key=keep >/dev/null; vnew="$(dump)"
	local diff; diff="$(jq -n -c --argjson a "$v151" --argjson b "$vnew" '[($a|keys[]), ($b|keys[])] | unique | map(select($a[.] != $b[.]))')"
	echo "    rows that differ from 1.5.1: $diff"
	check "only the new rows differ from 1.5.1" bash -c 'jq -e "all(.[]; test(\"^sgbackup_health_(api_key_meta|last_restore|api_key)$\"))" <<<"$0" >/dev/null' "$diff"
}

H20() { # downgrade to 1.5.1 keeps the stored key working
	[ -n "${HK151_PLUGIN:-}" ] && [ -n "${HK_PLUGIN:-}" ] || { echo "  skip (HK151_PLUGIN/HK_PLUGIN not set)"; return 0; }
	standby
	"$IM" install-plugin "$DST" "$HK151_PLUGIN" --activate >/dev/null
	clear_rl "$DST"
	expect_auth "1.5.1: KB works" 200 "$DST" "$KB"
	check "1.5.1: plugin loads (health_api_key runs)" W "$DST" siteguardbackup health_api_key
	"$IM" install-plugin "$DST" "$HK_PLUGIN" --activate >/dev/null
	expect_auth "reinstalled: KB works" 200 "$DST" "$KB"
}

# -----------------------------------------------------------------------------
SCENARIOS=(H1 H2 H3 H4 H5 H6 H7 H8 H9 H10 H11 H12 H13 H14 H15 H16 H17 H19 H20 H18)

for n in "$SRC" "$DST" "$MULTI"; do
	W "$n" plugin is-active siteguardbackup >/dev/null || W "$n" plugin is-active siteguardbackup --network >/dev/null \
		|| { echo "siteguardbackup not active on $n" >&2; exit 2; }
done
for SCEN in "${SCENARIOS[@]}"; do
	want "$SCEN" || continue
	echo "-- $SCEN"
	"$SCEN" || bad "$SCEN aborted"
done
SCEN=final
for n in "$SRC" "$DST" "$MULTI"; do
	if SH "$n" 'test -f wp-content/debug.log && grep -E "PHP (Fatal|Parse)" wp-content/debug.log'; then
		bad "PHP fatal errors in $n debug.log"
	else
		ok "no PHP fatal errors in $n debug.log"
	fi
done

echo
echo "$PASS passed, $FAIL failed"
for f in "${FAILED[@]}"; do echo "  - $f"; done
[ "$FAIL" -eq 0 ]
