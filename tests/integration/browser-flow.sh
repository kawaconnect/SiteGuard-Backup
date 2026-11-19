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
#   recover-encrypted  recover-single with backup encryption on: the bundle holds
#                    only .crypt archives, a wrong passphrase is refused without
#                    touching the site, the right one restores it
#   subsite-admin-limits  a subsite (and main-site) administrator who is not a
#                    network admin cannot download or restore network backups or
#                    run network-wide actions, but can back up and download
#                    their own subsite
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
[ ${#FLOWS[@]} -gt 0 ] || FLOWS=(recover-single move-single recover-network chunked-upload recover-encrypted subsite-admin-limits)
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

flow_recover_encrypted() {
	local S=bf-rec-enc; create "$S" single || { bad "create"; return; }
	seed "$S" "http://$S.test" enc
	local key="enc-$(openssl rand -hex 8)"
	wp "$S" eval "\$o = SiteGuard_Backup_Options::instance(); \$o->update_option('sgbackup_encryption_enabled', true); \$o->update_option('sgbackup_encryption_key', '$key');" >/dev/null
	login "$S" || { bad "login"; return; }
	local st; st="$(settings "$S")"
	local ts; ts="$(backup_now "$S" "$st")"; check "Backup Now finished (encrypted)" test -n "$ts"
	check "every archive is encrypted" test "$(wp "$S" eval "echo count(array_filter(SiteGuard_Backup_History::get_all_files(SiteGuard_Backup_History::get_history_raw()[$ts]), function (\$f) { return substr(\$f, -6) !== '.crypt' && substr(\$f, -5) !== '.json'; }));")" -eq 0
	download_bundle "$S" "$st" "$ts" "$WORK/enc.zip"
	bundle_checks "$S" "$st" "$ts" "$WORK/enc.zip"
	check "no plaintext database dump inside the bundle" bash -c "! unzip -l '$WORK/enc.zip' | grep -E -- '-db\\.gz\$'"
	wp "$S" siteguardbackup delete "$ts" --yes >/dev/null
	damage "$S" "http://$S.test" enc
	local up; up="$(upload_zip "$S" "$st" "$WORK/enc.zip")"
	check "bundle upload accepted" jq -e '.success == true' <<<"$up" || echo "      server said: ${up:0:400}"
	local nts; nts="$(history_ts "$S")"; check "bundle registered" test -n "$nts"
	local bad_out; bad_out="$(restore "$S" "$st" "$nts" -F "encryption_key=not-the-key")"
	check "restore with the wrong passphrase is refused" bash -c '! grep RINFO: <<<"$1" | sed "s/^.*RINFO://" | jq -s -e "map(select(.type == \"complete\")) | last | .success == true" >/dev/null' _ "$bad_out"
	check "wrong passphrase changed nothing" eq "$(wp "$S" option get sgx_marker)" DAMAGED
	local out; out="$(restore "$S" "$st" "$nts" -F "encryption_key=$key")"
	check "restore with the passphrase succeeds" restore_ok "$out"
	verify "$S" "http://$S.test" enc
	common_after "$S"
	finish "$S"
}

# A subsite administrator (not a network admin) must stay inside their subsite.
flow_subsite_admin_limits() {
	local N=bf-perm-net; create "$N" multisite || { bad "create"; return; }
	seed "$N" "http://$N.test/sub2" victim
	seed "$N" "http://$N.test/sub1" mine
	wp "$N" siteguardbackup backup >/dev/null                       # a network-wide backup (main site history)
	local net_ts; net_ts="$(history_ts "$N")"
	local pw; pw="$(openssl rand -hex 12)"
	wp "$N" user create subadmin subadmin@example.test --user_pass="$pw" >/dev/null
	wp "$N" --url="http://$N.test/sub1/" user set-role subadmin administrator >/dev/null
	wp "$N" --url="http://$N.test/" user set-role subadmin administrator >/dev/null  # also admin of the MAIN site, still not a network admin
	# log in as the subsite admin
	local jar="$WORK/$N.jar"; rm -f "$jar"
	curl -s -o /dev/null -c "$jar" -b "wordpress_test_cookie=WP%20Cookie%20check" -H "Host: $N.test" \
		--data-urlencode "log=subadmin" --data-urlencode "pwd=$pw" --data "testcookie=1&redirect_to=/sub1/wp-admin/" "$(base "$N")/wp-login.php"
	check "subsite admin can log in" grep -q wordpress_logged_in "$jar"
	# The main site's SiteGuard holds the network-wide backups and settings: a
	# main-site administrator who is not a network administrator gets nothing.
	local main; main="$(curl -s -b "$jar" -H "Host: $N.test" "$(base "$N")/wp-admin/admin.php?page=siteguardbackup" | grep -oE 'var sgbackup_settings = \{.*\};' | head -1 | sed -E 's/^var sgbackup_settings = //; s/;$//')"
	check "main-site SiteGuard page is not served to a main-site admin" test -z "$main"
	local sub; sub="$(curl -s -b "$jar" -H "Host: $N.test" "$(base "$N")/sub1/wp-admin/admin.php?page=siteguardbackup" | grep -oE 'var sgbackup_settings = \{.*\};' | head -1 | sed -E 's/^var sgbackup_settings = //; s/;$//')"
	check "their own subsite's SiteGuard page is served" test -n "$sub"
	# Nonces are per user, not per site: replay the subsite's against the main site.
	local code; code="$(curl -s -o "$WORK/net-dl.zip" -w '%{http_code}' -b "$jar" -H "Host: $N.test" \
		"$(base "$N")/wp-admin/admin-ajax.php?action=sgbackup_download_bundle&timestamp=$net_ts&_wpnonce=$(jq -r '.nonces.download' <<<"$sub")")"
	# (wp_die() in an AJAX request answers 200 with an empty body: judge by what came back.)
	check "cannot download the network-wide backup (HTTP $code, $(stat -c %s "$WORK/net-dl.zip" 2>/dev/null || echo 0) bytes)" bash -c '! unzip -tq "$1" >/dev/null 2>&1 && [ "$(stat -c %s "$1" 2>/dev/null || echo 0)" -lt 1024 ]' _ "$WORK/net-dl.zip"
	local r; r="$(curl -s -b "$jar" -H "Host: $N.test" "$(base "$N")/wp-admin/admin-ajax.php" \
		--data-urlencode action=sgbackup_ajax --data-urlencode subaction=searchreplace --data-urlencode "nonce=$(jq -r '.nonces.searchreplace' <<<"$sub")" \
		--data-urlencode 'data={"search":"victim","replace":"pwned","dry_run":0}')"
	check "network-wide search-replace refused" jq -e '.success == false' <<<"$r"
	r="$(curl -s -b "$jar" -H "Host: $N.test" "$(base "$N")/wp-admin/admin-ajax.php" \
		--data-urlencode action=sgbackup_ajax --data-urlencode subaction=backupnow --data-urlencode "nonce=$(jq -r '.nonces.backupnow' <<<"$sub")" --data-urlencode 'data={}')"
	check "main-site backup refused" jq -e '.success == false' <<<"$r"
	r="$(curl -s -b "$jar" -H "Host: $N.test" "$(base "$N")/wp-admin/admin-ajax.php" \
		--data-urlencode action=sgbackup_ajax --data-urlencode subaction=delete_backup --data-urlencode "nonce=$(jq -r '.nonce' <<<"$sub")" \
		--data-urlencode "data={\"timestamp\":$net_ts,\"delete_remote\":1}")"
	check "deleting the network backup refused" test -n "$(wp "$N" eval '$h = SiteGuard_Backup_History::get_history_raw(); echo isset($h['"$net_ts"']) ? "kept" : "";')"
	check "sub2 content untouched" eq "$(wp "$N" --url="http://$N.test/sub2/" option get sgx_marker)" victim
	local out; out="$(curl -s -N -b "$jar" -H "Host: $N.test" "$(base "$N")/wp-admin/admin-ajax.php" \
		-F action=sgbackup_restore -F "nonce=$(jq -r '.nonce' <<<"$sub")" -F "timestamp=$net_ts" -F sgbackup_restore_db=1 -F db_restore_mode=0)"
	check "restoring the network backup refused" bash -c '! grep RINFO: <<<"$1" | sed "s/^.*RINFO://" | jq -s -e "map(select(.type == \"complete\")) | last | .success == true" >/dev/null' _ "$out"

	# Their own subsite: back up and download is allowed, but only its own data.
	local SUB=(--url="http://$N.test/sub1/") sajax
	sajax() { curl -s -b "$jar" -H "Host: $N.test" "$(base "$N")/sub1/wp-admin/admin-ajax.php" --data-urlencode action=sgbackup_ajax \
		--data-urlencode "subaction=$1" --data-urlencode "nonce=$(jq -r ".nonces.$1 // .nonce" <<<"$sub")" --data-urlencode "data=$2"; }
	local bid; bid="$(wp "$N" "${SUB[@]}" eval 'echo get_current_blog_id();')"
	r="$(sajax savesettings '{"extra_dirs":["/etc"],"include_entities":["wpcore","plugins"],"backupdb_nonwp":1,"retain_extrarules":{"files":[],"db":[]}}')"
	check "extra directories cannot be set by a subsite admin" eq "$(wp "$N" "${SUB[@]}" eval 'echo json_encode((array) SiteGuard_Backup_Options::instance()->get_option("sgbackup_extra_dirs", array()));')" '[]'
	check "non-WordPress tables cannot be switched on by a subsite admin" eq "$(wp "$N" "${SUB[@]}" eval 'echo (int) SiteGuard_Backup_Options::instance()->get_option("sgbackup_backupdb_nonwp", 0);')" 0
	# Ask for WordPress core (wp-config.php) and the shared plugins.
	r="$(sajax backupnow '{"entities":["wpcore","plugins","uploads"],"service":["none"]}')"
	check "subsite admin can back up their own site" jq -e '.success == true' <<<"$r"
	local sts=""
	for _ in $(seq 1 40); do
		sts="$(wp "$N" "${SUB[@]}" eval '$h = SiteGuard_Backup_History::get_history_raw(); krsort($h); foreach ($h as $t => $b) { if (!empty($b["is_subsite_backup"]) && SiteGuard_Backup_History::get_all_files($b) && !SiteGuard_Backup_Job_Scheduler::get_active_jobs()) { echo $t; break; } }')"
		[ -n "$sts" ] && break; wp "$N" "${SUB[@]}" cron event run --due-now >/dev/null 2>&1; sleep 2
	done
	check "the set holds no core, plugins, themes or others archive" eq "$(wp "$N" "${SUB[@]}" eval '$h = SiteGuard_Backup_History::get_history_raw(); $b = $h['"${sts:-0}"']; echo implode(",", array_intersect(array_keys($b), array("wpcore", "plugins", "themes", "others")));')" ""
	code="$(curl -s -o "$WORK/own.zip" -w '%{http_code}' -b "$jar" -H "Host: $N.test" \
		"$(base "$N")/sub1/wp-admin/admin-ajax.php?action=sgbackup_download_bundle&timestamp=$sts&_wpnonce=$(jq -r '.nonces.download' <<<"$sub")")"
	check "subsite admin can download their own backup (HTTP $code)" test "$code" = 200 -a -s "$WORK/own.zip"
	check "no wp-config.php anywhere in the download" bash -c 'd="$1/own"; rm -rf "$d"; mkdir "$d" && cd "$d" && unzip -q ../own.zip && for z in *.zip; do [ -e "$z" ] || continue; unzip -l "$z" | grep -q "wp-config.php" && exit 1; done; exit 0' _ "$WORK"

	# A set made by a network administrator on this subsite (may hold shared code)
	# is not theirs to download or send elsewhere.
	wp "$N" "${SUB[@]}" siteguardbackup backup >/dev/null
	local nts; nts="$(wp "$N" "${SUB[@]}" eval '$h = SiteGuard_Backup_History::get_history_raw(); krsort($h); foreach ($h as $t => $b) { if (!empty($b["network_scope"])) { echo $t; break; } }')"
	check "a network administrator's subsite set is marked network_scope" test -n "$nts"
	code="$(curl -s -o "$WORK/nscope.zip" -w '%{http_code}' -b "$jar" -H "Host: $N.test" \
		"$(base "$N")/sub1/wp-admin/admin-ajax.php?action=sgbackup_download_bundle&timestamp=${nts:-1}&_wpnonce=$(jq -r '.nonces.download' <<<"$sub")")"
	check "subsite admin cannot download it (HTTP $code)" test "$code" = 403

	# A hostile backup, uploaded and restored by the subsite admin: SQL that
	# reaches outside the subsite, and PHP in the uploads archive.
	wp "$N" db query "CREATE TABLE IF NOT EXISTS wp_sgx_canary (a int); INSERT INTO wp_sgx_canary VALUES (1);" >/dev/null
	local admins_before; admins_before="$(wp "$N" eval 'echo md5(serialize(get_site_option("site_admins")));')"
	local E="$WORK/evil"; rm -rf "$E"; mkdir "$E"; ( cd "$E" && unzip -q "$WORK/own.zip" )
	local oldn; oldn="$(jq -r '.backup.nonce' "$E/_sgbackup_bundle.json")"
	local newn=evil00evil00 dbf upf
	dbf="$(cd "$E" && ls | grep -- '-db\.gz$' | head -1)"; upf="$(cd "$E" && ls | grep -- '-uploads\.zip$' | head -1)"
	check "the downloaded set has a database dump and an uploads archive" test -n "$dbf" -a -n "$upf" -a -n "$oldn"
	{
		gzip -dc "$E/$dbf"
		cat <<-SQL
		INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1,'sgx_pwned','1');
		INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1,'sgx_pwned','1');
		INSERT INTO \`wp_${bid}_options\` (option_name, option_value, autoload) SELECT 'sgx_leak', user_pass, 'no' FROM wp_users LIMIT 1;
		INSERT INTO \`wp_${bid}_options\` (\`option_name\`, \`option_value\`, \`autoload\`) VALUES ('sgx_leak2', (SELECT user_pass FROM wp_users LIMIT 1), 'no');
		DROP TABLE IF EXISTS \`wp_${bid}_sgx_none\`, \`wp_sgx_canary\`;
		DROP TABLE wp_sgx_canary;
		UPDATE \`wp_users\` SET display_name='sgx-pwned';
		RENAME TABLE \`wp_sgx_canary\` TO \`wp_sgx_gone\`;
		SQL
	} | gzip > "$E/db.new" && mv "$E/db.new" "$E/$dbf"
	( cd "$E" && mkdir -p "x/sites/$bid" && echo '<?php echo "sgx-shell";' > "x/sites/$bid/sgx-shell.php" && cp "x/sites/$bid/sgx-shell.php" "x/sites/$bid/sgx.php.jpg" \
		&& echo 'AddType application/x-httpd-php .jpg' > "x/sites/$bid/.htaccess" && echo ok > "x/sites/$bid/sgx-ok.txt" \
		&& cp "x/sites/$bid/sgx-shell.php" x/sgx-shell.php && cd x && zip -qr "../$upf" . && cd .. && rm -rf x )
	( cd "$E" && for f in *"$oldn"*; do mv "$f" "${f//$oldn/$newn}"; done \
		&& mf="$(jq -r '.backup.manifest // empty | if type == "array" then .[] else . end' _sgbackup_bundle.json | sed "s/$oldn/$newn/")" \
		&& sed -i "s/$oldn/$newn/g" _sgbackup_bundle.json && jq 'del(.backup.manifest)' _sgbackup_bundle.json > j && mv j _sgbackup_bundle.json \
		&& for m in $mf; do rm -f -- "$m"; done; zip -q "$WORK/evil.zip" ./* ./_sgbackup_bundle.json 2>/dev/null || zip -q "$WORK/evil.zip" ./* )
	r="$(curl -s -b "$jar" -H "Host: $N.test" "$(base "$N")/sub1/wp-admin/admin-ajax.php" \
		-F "action=sgbackup_upload" -F "nonce=$(jq -r '.nonces.upload' <<<"$sub")" -F "sgbackup_file=@$WORK/evil.zip")"
	check "the hostile bundle uploads" jq -e '.success == true' <<<"$r"
	local ets; ets="$(wp "$N" "${SUB[@]}" eval '$h = SiteGuard_Backup_History::get_history_raw(); foreach ($h as $t => $b) { if (isset($b["nonce"]) && "evil00evil00" === $b["nonce"]) { echo $t; break; } }')"
	check "...and is registered as its own set" test -n "$ets" -a "$ets" != "$sts"
	out="$(curl -s -N -b "$jar" -H "Host: $N.test" "$(base "$N")/sub1/wp-admin/admin-ajax.php" \
		-F action=sgbackup_restore -F "nonce=$(jq -r '.nonce' <<<"$sub")" -F "timestamp=${ets:-1}" -F sgbackup_restore_db=1 -F sgbackup_restore_uploads=1 -F db_restore_mode=0 -F move_in_mode=0)"
	check "the restore ran" grep -q 'RINFO:' <<<"$out"
	check "network super admins unchanged" eq "$(wp "$N" eval 'echo md5(serialize(get_site_option("site_admins")));')" "$admins_before"
	check "no row written to wp_sitemeta" eq "$(wp "$N" db query "SELECT COUNT(*) FROM wp_sitemeta WHERE meta_key='sgx_pwned'" --skip-column-names | tr -d '[:space:]')" 0
	check "no row written to wp_usermeta" eq "$(wp "$N" db query "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key='sgx_pwned'" --skip-column-names | tr -d '[:space:]')" 0
	check "no password hash copied into the subsite" eq "$(wp "$N" db query "SELECT COUNT(*) FROM wp_${bid}_options WHERE option_name LIKE 'sgx_leak%'" --skip-column-names | tr -d '[:space:]')" 0
	check "a table outside the subsite was not dropped or renamed" eq "$(wp "$N" db query "SELECT COUNT(*) FROM wp_sgx_canary" --skip-column-names | tr -d '[:space:]')" 1
	check "wp_users not modified" eq "$(wp "$N" db query "SELECT COUNT(*) FROM wp_users WHERE display_name='sgx-pwned'" --skip-column-names | tr -d '[:space:]')" 0
	check "the subsite's own data was restored" eq "$(wp "$N" "${SUB[@]}" option get sgx_marker 2>/dev/null)" mine
	check "the subsite still answers" eq "$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $N.test" "$(base "$N")/sub1/")" 200
	# The dump had refused statements, so the import failed as a whole and
	# nothing of the set (not even its harmless uploads) was applied.
	check "the hostile restore was reported as failed" bash -c '! grep RINFO: <<<"$1" | sed "s/^.*RINFO://" | jq -s -e "map(select(.type == \"complete\")) | last | .success == true" >/dev/null' _ "$out"
	check "no PHP, double-extension or .htaccess file in the uploads tree" bash -c "! $(printf %q "$IM") compose $N exec -T cli sh -c 'find wp-content/uploads \\( -name \"sgx-shell.php\" -o -name \"sgx.php.jpg\" -o -name .htaccess \\) | grep -q .'"
	check "sub2 still untouched" eq "$(wp "$N" --url="http://$N.test/sub2/" option get sgx_marker)" victim
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
		recover-encrypted) flow_recover_encrypted ;;
		subsite-admin-limits) flow_subsite_admin_limits ;;
		*) bad "unknown flow" ;;
	esac
done

echo
echo "$PASS passed, $FAIL failed"
for f in "${FAILED[@]}"; do echo "  - $f"; done
[ "$FAIL" -eq 0 ]
