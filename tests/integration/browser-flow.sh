#!/usr/bin/env bash
# =============================================================================
# The everyday SiteGuard workflow, driven exactly as a browser does it:
#
#   log in -> "Backup Now" -> download the set as a zip bundle (and each file)
#   -> upload that zip -> restore it -> verify the site
#
#   tests/integration/browser-flow.sh <instance-manager> <plugin-dir> [flow ...] [--keep-failed]
#
# Flows:
#   recover-single   single site: back up, download, lose everything (backup
#                    deleted from the server, content damaged), upload the zip
#                    to the same site, restore, verify
#   move-single      single site A -> fresh single site B via the downloaded zip
#   recover-network  multisite network: back up from Network Admin, download,
#                    damage main site and sub1, upload, restore, verify both
#   chunked-upload   as move-single, but the zip is uploaded in 1 MB chunks
#                    (the path the admin UI uses for large files)
#
# Uses admin-ajax.php over HTTP with a real login cookie and the page's own
# nonces. Instances are disposable (WPMultisiteDocker scripts/instance.sh).
# =============================================================================
set -uo pipefail

IM="${1:?instance manager required}"
PLUGIN="${2:?plugin directory required}"
shift 2
KEEP_FAILED=0; FLOWS=()
for a in "$@"; do case "$a" in --keep-failed) KEEP_FAILED=1 ;; *) FLOWS+=("$a") ;; esac; done
[ ${#FLOWS[@]} -gt 0 ] || FLOWS=(recover-single move-single recover-network chunked-upload)
command -v jq >/dev/null || { echo "jq required" >&2; exit 2; }

exec 9>/tmp/siteguard-browserflow.lock
flock -n 9 || { echo "another browser-flow run is active" >&2; exit 2; }

PASS=0; FAIL=0; FAILED=(); CTX=""
ok()    { PASS=$((PASS + 1)); echo "    ok   $*"; }
bad()   { FAIL=$((FAIL + 1)); FAILED+=("$CTX: $*"); echo "    FAIL $*"; }
check() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d"; return 1; fi; }
eq()    { [ "$1" = "$2" ]; }

WORK="$(mktemp -d /tmp/sg-browserflow.XXXXXX)"
trap 'rm -rf --one-file-system -- "$WORK"' EXIT

wp()   { local i="$1"; shift; "$IM" wp "$i" "$@" 2> >(grep -v -e wp_update -e 'Warning: .*WordPress.org' >&2); }
envv() { ( . "$(dirname "$IM")/../instances/$1/.env"; eval echo "\$$2" ); }
base() { echo "http://127.0.0.1:$(envv "$1" HTTP_PORT)"; }

create() {
	"$IM" destroy "$1" --yes >/dev/null 2>&1 || true
	"$IM" create "$1" --mode "$2" >/dev/null 2>&1 && "$IM" install-plugin "$1" "$PLUGIN" --activate >/dev/null 2>&1
}

# --- browser session ------------------------------------------------------
login() { # instance -> cookie jar $WORK/<i>.jar
	local i="$1" jar="$WORK/$1.jar"
	rm -f "$jar"
	curl -s -o /dev/null -c "$jar" -b "wordpress_test_cookie=WP%20Cookie%20check" -H "Host: $i.test" \
		--data-urlencode "log=admin" --data-urlencode "pwd=$(envv "$i" WP_ADMIN_PASSWORD)" \
		--data "testcookie=1&wp-submit=Log+In&redirect_to=/wp-admin/" "$(base "$i")/wp-login.php"
	grep -q wordpress_logged_in "$jar"
}
settings() { # instance [network] -> sgbackup_settings JSON (nonces) from the admin page
	local i="$1" page="/wp-admin/admin.php?page=siteguardbackup"
	[ "${2:-}" = network ] && page="/wp-admin/network/admin.php?page=siteguardbackup"
	curl -s -b "$WORK/$i.jar" -H "Host: $i.test" "$(base "$i")$page" \
		| grep -oE 'var sgbackup_settings = \{.*\};' | head -1 | sed -E 's/^var sgbackup_settings = //; s/;$//'
}
ajax() { # instance nonce subaction json-data -> response json
	curl -s -b "$WORK/$1.jar" -H "Host: $1.test" "$(base "$1")/wp-admin/admin-ajax.php" \
		--data-urlencode "action=sgbackup_ajax" --data-urlencode "subaction=$3" \
		--data-urlencode "nonce=$2" --data-urlencode "data=$4"
}

history_ts() { wp "$1" eval '$h = SiteGuard_Backup_History::get_history_raw(); krsort($h); foreach ($h as $t => $b) { if (SiteGuard_Backup_History::get_all_files($b)) { echo $t; break; } }'; }

# "Backup Now" button, then let the (external) cron runner finish the job.
backup_now() { # instance settings-json
	local i="$1" s="$2" before; before="$(history_ts "$i")"
	local r; r="$(ajax "$i" "$(jq -r '.nonces.backupnow' <<<"$s")" backupnow '{}')"
	jq -e '.success == true' <<<"$r" >/dev/null || { echo "backupnow said: $r" >&2; return 1; }
	for _ in $(seq 1 60); do
		local now; now="$(history_ts "$i")"
		if [ -n "$now" ] && [ "$now" != "$before" ] && [ -z "$(wp "$i" eval 'echo count(SiteGuard_Backup_Job_Scheduler::get_active_jobs()) ?: "";')" ]; then
			echo "$now"; return 0
		fi
		wp "$i" cron event run --due-now >/dev/null 2>&1
		sleep 2
	done
	return 1
}

download_bundle() { # instance settings ts out.zip
	curl -s -f -b "$WORK/$1.jar" -H "Host: $1.test" -o "$4" \
		"$(base "$1")/wp-admin/admin-ajax.php?action=sgbackup_download_bundle&timestamp=$3&_wpnonce=$(jq -r '.nonces.download' <<<"$2")"
}
download_file() { # instance settings filename out
	curl -s -f -b "$WORK/$1.jar" -H "Host: $1.test" -o "$4" \
		"$(base "$1")/wp-admin/admin-ajax.php?action=sgbackup_download&file=$3&_wpnonce=$(jq -r '.nonces.download' <<<"$2")"
}
upload_zip() { # instance settings file
	curl -s -b "$WORK/$1.jar" -H "Host: $1.test" "$(base "$1")/wp-admin/admin-ajax.php" \
		-F "action=sgbackup_upload" -F "nonce=$(jq -r '.nonces.upload' <<<"$2")" -F "sgbackup_file=@$3"
}
upload_chunked() { # instance settings file  (1 MB chunks, like the UI)
	local i="$1" s="$2" f="$3" size off=0 chunk=1048576 last r
	size=$(stat -c %s "$f")
	while [ "$off" -lt "$size" ]; do
		last=0; [ $((off + chunk)) -ge "$size" ] && last=1
		dd if="$f" of="$WORK/chunk" bs=$chunk skip=$((off / chunk)) count=1 status=none
		r="$(curl -s -b "$WORK/$i.jar" -H "Host: $i.test" "$(base "$i")/wp-admin/admin-ajax.php" \
			-F "action=sgbackup_ajax" -F "subaction=upload_chunk" -F "nonce=$(jq -r '.nonces.upload_chunk' <<<"$s")" \
			-F "data={\"filename\":\"$(basename "$f")\",\"offset\":$off,\"total_size\":$size,\"is_last\":$last}" \
			-F "chunk_data=@$WORK/chunk;filename=chunk")"
		jq -e '.success == true' <<<"$r" >/dev/null 2>&1 || { echo "chunk at $off: $r" >&2; return 1; }
		off=$((off + chunk))
	done
	echo '{"success":true}'
}
restore() { # instance settings ts [extra form fields...]
	local i="$1" s="$2" ts="$3"; shift 3
	curl -s -N -b "$WORK/$i.jar" -H "Host: $i.test" "$(base "$i")/wp-admin/admin-ajax.php" \
		-F "action=sgbackup_restore" -F "nonce=$(jq -r '.nonce' <<<"$s")" -F "timestamp=$ts" \
		-F "sgbackup_restore_db=1" -F "sgbackup_restore_plugins=1" -F "sgbackup_restore_themes=1" \
		-F "sgbackup_restore_uploads=1" -F "sgbackup_restore_others=1" \
		-F "move_in_mode=0" -F "db_restore_mode=0" "$@"
}
restore_ok() { grep 'RINFO:' <<<"$1" | sed 's/^.*RINFO://' | jq -s -e 'map(select(.type == "complete")) | last | .success == true' >/dev/null 2>&1; }

# --- content that must survive the round trip ------------------------------
seed() { # instance url label
	local i="$1" u="$2" l="$3" U=(--url="$2")
	"$IM" compose "$i" exec -T cli sh -c 'printf "%s" iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg== | base64 -d > /tmp/sgx.png'
	wp "$i" "${U[@]}" option update sgx_marker "$l" >/dev/null
	local aid; aid="$(wp "$i" "${U[@]}" media import /tmp/sgx.png --title="img-$l" --porcelain)"
	wp "$i" "${U[@]}" post create --post_status=publish --post_title="SGX $l" --post_name="sgx-$l" \
		--post_content="img <img src=\"$(wp "$i" "${U[@]}" eval "echo wp_get_attachment_url($aid);")\">" >/dev/null
}
damage() { # instance url label: what a bad day looks like
	local i="$1" U=(--url="$2") l="$3"
	wp "$i" "${U[@]}" option update sgx_marker DAMAGED >/dev/null
	local pid; pid="$(wp "$i" "${U[@]}" post list --name="sgx-$l" --field=ID)"
	[ -n "$pid" ] && wp "$i" "${U[@]}" post delete "$pid" --force >/dev/null
	"$IM" compose "$i" exec -T cli sh -c 'find wp-content/uploads -name "sgx*.png" -delete' >/dev/null
}
verify() { # instance url label
	local i="$1" u="$2" l="$3" U=(--url="$2/")
	check "[$l] marker option back"            eq "$(wp "$i" "${U[@]}" option get sgx_marker)" "$l"
	local pid; pid="$(wp "$i" "${U[@]}" post list --name="sgx-$l" --field=ID)"
	check "[$l] marker post back"              test -n "$pid"
	local img; img="$(wp "$i" "${U[@]}" post get "${pid:-0}" --field=post_content 2>/dev/null | grep -oE 'src="[^"]+"' | sed -E 's/^src="|"$//g')"
	check "[$l] image URL on this site"        grep -qF "$u/wp-content/uploads/" <<<"$img"
	check "[$l] image file served"             eq "$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $i.test" "$(base "$i")$(sed -E 's#^https?://[^/]+##' <<<"$img")")" 200
	local link; link="$(wp "$i" "${U[@]}" eval "echo get_permalink(${pid:-0});" | sed -E 's#^https?://[^/]+##')"
	check "[$l] post page served"              eq "$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $i.test" "$(base "$i")$link")" 200
	check "[$l] siteurl is this site"          eq "$(wp "$i" "${U[@]}" option get siteurl)" "$u"
}
common_after() { # instance
	check "admin can still log in"            login "$1"
	check "SiteGuard still active"            wp "$1" eval 'exit(class_exists("SiteGuard_Backup") ? 0 : 1);'
	check "no PHP fatal errors"               bash -c "! $(printf %q "$IM") compose $1 exec -T cli sh -c 'grep -qE \"PHP (Fatal|Parse)\" wp-content/debug.log 2>/dev/null'"
}

bundle_checks() { # instance settings ts zip
	local i="$1" s="$2" ts="$3" z="$4"
	check "bundle downloaded"                 test -s "$z"
	check "bundle is a valid zip"             unzip -tq "$z"
	local files; files="$(wp "$i" eval "echo implode(' ', SiteGuard_Backup_History::get_all_files(SiteGuard_Backup_History::get_history_raw()[$ts]));")"
	local f n=0 same=0
	local sc; sc="$("$IM" compose "$i" ps -q wordpress)"
	local sdir; sdir="$(wp "$i" eval 'echo SiteGuard_Backup_Options::instance()->get_backup_dir();')"
	for f in $files; do
		n=$((n + 1))
		unzip -p "$z" "$f" > "$WORK/fromzip" 2>/dev/null
		download_file "$i" "$s" "$f" "$WORK/single"
		docker cp -q "$sc:$sdir$f" "$WORK/server"
		[ "$(md5sum < "$WORK/fromzip")" = "$(md5sum < "$WORK/server")" ] \
			&& [ "$(md5sum < "$WORK/single")" = "$(md5sum < "$WORK/server")" ] && same=$((same + 1))
	done
	check "bundle and single downloads byte-identical to the server ($same/$n files)" test "$n" -gt 0 -a "$same" -eq "$n"
	check "bundle carries its manifest"       bash -c "unzip -l '$z' | grep -q _sgbackup_bundle.json"
}

finish() { # instances...
	local keep=0; [ "$KEEP_FAILED" = 1 ] && [ "$FAIL" -gt "$FAIL_BEFORE" ] && keep=1
	local i; for i in "$@"; do
		if [ $keep = 1 ]; then echo "  (kept $i)"; else "$IM" destroy "$i" --yes >/dev/null 2>&1; fi
	done
}

# ---------------------------------------------------------------------------
flow_recover_single() {
	local S=bf-rec-single; create "$S" single || { bad "create"; return; }
	seed "$S" "http://$S.test" rec
	login "$S" || { bad "login"; return; }
	local st; st="$(settings "$S")"
	local ts; ts="$(backup_now "$S" "$st")"; check "Backup Now finished" test -n "$ts"
	download_bundle "$S" "$st" "$ts" "$WORK/bundle.zip"
	bundle_checks "$S" "$st" "$ts" "$WORK/bundle.zip"
	# Lose everything: the server copy and the content.
	wp "$S" siteguardbackup delete "$ts" --yes >/dev/null
	damage "$S" "http://$S.test" rec
	check "damage applied" eq "$(wp "$S" option get sgx_marker)" DAMAGED
	local up; up="$(upload_zip "$S" "$st" "$WORK/bundle.zip")"
	check "bundle upload accepted" jq -e '.success == true' <<<"$up" || echo "      server said: ${up:0:400}"
	local nts; nts="$(history_ts "$S")"; check "uploaded bundle registered as a backup" test -n "$nts"
	local out; out="$(restore "$S" "$st" "$nts")"
	check "restore stream reports success" restore_ok "$out"
	verify "$S" "http://$S.test" rec
	common_after "$S"
	finish "$S"
}

flow_move_single() { # [chunked]
	local A=bf-move-a B=bf-move-b chunked="${1:-}"
	[ -n "$chunked" ] && { A=bf-chunk-a; B=bf-chunk-b; }
	create "$A" single && create "$B" single || { bad "create"; return; }
	seed "$A" "http://$A.test" move
	login "$A" && login "$B" || { bad "login"; return; }
	local sa sb; sa="$(settings "$A")"; sb="$(settings "$B")"
	local ts; ts="$(backup_now "$A" "$sa")"; check "Backup Now finished on A" test -n "$ts"
	download_bundle "$A" "$sa" "$ts" "$WORK/move.zip"
	bundle_checks "$A" "$sa" "$ts" "$WORK/move.zip"
	local up
	if [ -n "$chunked" ]; then up="$(upload_chunked "$B" "$sb" "$WORK/move.zip")"; sleep 3; else up="$(upload_zip "$B" "$sb" "$WORK/move.zip")"; fi
	check "bundle upload accepted on B" jq -e '.success == true' <<<"$up" || echo "      server said: ${up:0:400}"
	local nts; nts="$(history_ts "$B")"; check "bundle registered on B" test -n "$nts"
	local out; out="$(restore "$B" "$sb" "$nts")"
	check "restore stream reports success" restore_ok "$out"
	verify "$B" "http://$B.test" move
	check "no URLs of A left in B's content" test "$(wp "$B" db query "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%//$A.test%'" --skip-column-names)" -eq 0
	common_after "$B"
	finish "$A" "$B"
}

flow_recover_network() {
	local N=bf-rec-net; create "$N" multisite || { bad "create"; return; }
	seed "$N" "http://$N.test" netmain
	seed "$N" "http://$N.test/sub1" netsub
	login "$N" || { bad "login"; return; }
	local st; st="$(settings "$N" network)"
	check "network admin page gives nonces" test -n "$(jq -r '.nonces.backupnow // empty' <<<"$st")"
	local ts; ts="$(backup_now "$N" "$st")"; check "network Backup Now finished" test -n "$ts"
	download_bundle "$N" "$st" "$ts" "$WORK/net.zip"
	bundle_checks "$N" "$st" "$ts" "$WORK/net.zip"
	wp "$N" siteguardbackup delete "$ts" --yes >/dev/null
	damage "$N" "http://$N.test" netmain
	damage "$N" "http://$N.test/sub1" netsub
	local up; up="$(upload_zip "$N" "$st" "$WORK/net.zip")"
	check "bundle upload accepted" jq -e '.success == true' <<<"$up" || echo "      server said: ${up:0:400}"
	local nts; nts="$(history_ts "$N")"; check "bundle registered" test -n "$nts"
	local out; out="$(restore "$N" "$st" "$nts" -F "subsite_mode=0")"
	check "restore stream reports success" restore_ok "$out"
	verify "$N" "http://$N.test" netmain
	verify "$N" "http://$N.test/sub1" netsub
	check "subsites still listed" test "$(wp "$N" site list --format=count)" -ge 3
	common_after "$N"
	finish "$N"
}

for f in "${FLOWS[@]}"; do
	CTX="$f"; FAIL_BEFORE=$FAIL
	echo "== $f"
	case "$f" in
		recover-single)  flow_recover_single ;;
		move-single)     flow_move_single ;;
		recover-network) flow_recover_network ;;
		chunked-upload)  flow_move_single chunked ;;
		*) bad "unknown flow" ;;
	esac
done

echo
echo "$PASS passed, $FAIL failed"
for f in "${FAILED[@]}"; do echo "  - $f"; done
[ "$FAIL" -eq 0 ]
