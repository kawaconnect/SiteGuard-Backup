#!/usr/bin/env bash
# =============================================================================
# Uptime Kuma push from the health monitor, against disposable WordPress
# instances and a fake Kuma.
#
#   tests/integration/health-kuma.sh <instance-manager> <single-instance> <multisite-instance> [test ...]
#
# <instance-manager> is WPMultisiteDocker's scripts/instance.sh. The instances
# must already exist, be up and have the plugin active; this script never
# creates, stops or destroys one. Plugin code runs only inside the instances'
# cli containers as www-data.
#
# A fake Kuma (tests/integration/fake-kuma.php, HTTPS with a throwaway CA) runs
# as a container on the internal "wpi-mesh" network as kuma.test and logs every
# request. A test-only mu-plugin trusts the CA for kuma.test; another makes the
# health check of chosen sites answer 503. Both are removed afterwards.
#
# Takes the shared integration lock (waits up to 20 minutes). See
# tests/README.md for where this may run.
# =============================================================================
set -uo pipefail

IM="${1:?instance manager (WPMultisiteDocker/scripts/instance.sh) required}"
SINGLE="${2:?single-site instance name required}"
MULTI="${3:?multisite instance name required}"
shift 3
ONLY=("$@")
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[ -x "$IM" ] || { echo "not executable: $IM" >&2; exit 2; }
for n in "$SINGLE" "$MULTI"; do
	[[ "$n" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || { echo "bad instance name $n" >&2; exit 2; }
done
command -v jq >/dev/null || { echo "jq is required" >&2; exit 2; }
INSTDIR="$(cd "$(dirname "$IM")/.." && pwd)/instances"

exec 9>/tmp/siteguard-integration.lock
flock -w 1200 9 || { echo "another integration run is active; gave up waiting" >&2; exit 2; }

# Nothing is deleted: the fake Kuma container is started with --rm and only
# stopped, the test mu-plugins are moved to wp-content/sg-test-off/, and the
# throwaway CA stays in the mktemp directory.
KUMA=sgt-kuma-fake
WORK="$(mktemp -d /tmp/sg-kuma.XXXXXX)"
cleanup() {
	docker stop "$KUMA" >/dev/null 2>&1 || true
	for n in "$SINGLE" "$MULTI"; do
		"$IM" compose "$n" exec -T cli sh -c 'mkdir -p wp-content/sg-test-off && for f in wp-content/mu-plugins/sg-test-kuma-ca.php wp-content/mu-plugins/sg-test-kuma-fail.php; do [ -f "$f" ] && mv -f "$f" wp-content/sg-test-off/; done; true' >/dev/null 2>&1 || true
	done
}
trap cleanup EXIT

PASS=0; FAIL=0; FAILED=(); INST=""
ok()    { PASS=$((PASS + 1)); echo "  ok   $*"; }
bad()   { FAIL=$((FAIL + 1)); FAILED+=("$INST: $*"); echo "  FAIL $*"; }
check() { local d="$1"; shift; if "$@"; then ok "$d"; else bad "$d"; fi; }
want()  {
	[ ${#ONLY[@]} -eq 0 ] && return 0
	local t; for t in "${ONLY[@]}"; do [ "$t" = "$1" ] && return 0; done
	return 1
}

W()   { "$IM" wp "$INST" "$@" 2>/dev/null; }
WE()  { "$IM" wp "$INST" "$@" 2>&1; }
PHP() { W eval 'require_once SGBACKUP_DIR . "includes/class-health-monitor.php"; require_once SGBACKUP_DIR . "includes/class-health-kuma.php"; '"$1"; }
SH()  { "$IM" compose "$INST" exec -T cli sh -c "$1"; }      # as www-data
port() { (. "$INSTDIR/$1/.env"; echo "$HTTP_PORT"); }

# --- fake Kuma -----------------------------------------------------------------
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=SG Kuma Test CA" \
	-keyout "$WORK/ca.key" -out "$WORK/ca.pem" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj "/CN=kuma.test" -keyout "$WORK/key.pem" -out "$WORK/req.csr" >/dev/null 2>&1
printf 'subjectAltName=DNS:kuma.test\n' > "$WORK/san.ext"
openssl x509 -req -in "$WORK/req.csr" -CA "$WORK/ca.pem" -CAkey "$WORK/ca.key" -CAcreateserial \
	-days 1 -extfile "$WORK/san.ext" -out "$WORK/cert.pem" >/dev/null 2>&1
cp "$HERE/fake-kuma.php" "$WORK/fake-kuma.php"
chmod 644 "$WORK"/*.pem "$WORK/fake-kuma.php"
docker stop "$KUMA" >/dev/null 2>&1 || true
docker run -d --rm --name "$KUMA" --network wpi-mesh --network-alias kuma.test -v "$WORK:/k:ro" \
	php:8.2-cli sh -c 'touch /tmp/req.log /tmp/kuma-down && exec php /k/fake-kuma.php /k/cert.pem /k/key.pem /tmp/req.log' >/dev/null
for _ in $(seq 1 30); do docker logs "$KUMA" 2>&1 | grep -q "fake kuma listening" && break; sleep 1; done

kuma_reset() { docker exec "$KUMA" sh -c ': > /tmp/req.log'; }
kuma_log()   { docker exec "$KUMA" cat /tmp/req.log; }
kuma_count() { kuma_log | grep -c . ; }
kuma_last()  { kuma_log | tail -n 1 | jq -r ".$1"; }

# Tokens: "good"/"paused"/"slow" select the fake's answer; the random part is
# the secret that must never show up anywhere.
SECRET="$(openssl rand -hex 10)"
GOOD="https://kuma.test/api/push/good$SECRET"
PAUSED="https://kuma.test/api/push/paused$SECRET"
SLOW="https://kuma.test/api/push/slow$SECRET"
NODNS="https://nokuma.invalid/api/push/good$SECRET"

setup_instance() {
	docker cp -q "$WORK/ca.pem" "$("$IM" compose "$INST" ps -q wordpress):/var/www/html/wp-content/sg-test-kuma-ca.pem"
	SH 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/sg-test-kuma-ca.php' <<'PHP'
<?php
// Test only: trust the throwaway CA for the fake Kuma.
add_filter('http_request_args', function ($args, $url) {
	if ('kuma.test' === wp_parse_url($url, PHP_URL_HOST)) {
		$args['sslcertificates'] = WP_CONTENT_DIR . '/sg-test-kuma-ca.pem';
	}
	return $args;
}, 10, 2);
PHP
	SH 'cat > wp-content/mu-plugins/sg-test-kuma-fail.php' <<'PHP'
<?php
// Test only: when wp-content/sg-test-kuma-fail is not empty, health checks of
// URLs whose path starts with its content answer 503; "throw" makes the health
// run itself throw.
$sgf = WP_CONTENT_DIR . '/sg-test-kuma-fail';
$prefix = is_file($sgf) ? trim((string) file_get_contents($sgf)) : '';
if ('' !== $prefix) {
	$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
	if ('throw' !== $prefix && false !== strpos($ua, 'SiteGuard-Health-Monitor') && isset($_SERVER['REQUEST_URI'])
		&& 0 === strpos($_SERVER['REQUEST_URI'], $prefix)) {
		http_response_code(503);
		exit;
	}
	if ('throw' === $prefix) {
		add_filter('pre_http_request', function ($pre, $args, $url) {
			if (false !== strpos((string) $args['user-agent'], 'SiteGuard-Health-Monitor') && 'kuma.test' !== wp_parse_url($url, PHP_URL_HOST)) {
				throw new RuntimeException('sg-test: health run broken');
			}
			return $pre;
		}, 10, 3);
	}
}
PHP
	fail_none
	W siteguardbackup health_config enable --interval=every10min >/dev/null
	LOGOFF="$(SH 'wc -c < wp-content/debug.log 2>/dev/null || echo 0' | tr -dc 0-9)"
}
fail_none() { SH ': > wp-content/sg-test-kuma-fail' >/dev/null; }
fail_path() { SH "printf %s '$1' > wp-content/sg-test-kuma-fail" >/dev/null; }
debug_log() { SH "tail -c +$((${LOGOFF:-0} + 1)) wp-content/debug.log 2>/dev/null" || true; }  # this run's part only
kuma_down() { docker exec "$KUMA" sh -c "echo $1 > /tmp/kuma-down"; }

no_secret() { # <desc> <text>
	if grep -q -- "$SECRET" <<<"$2"; then bad "$1 (push token leaked)"; else ok "$1"; fi
}

# --- tests -----------------------------------------------------------------------
t_cli_set() {
	W siteguardbackup health_kuma --clear >/dev/null
	local out
	out="$(WE siteguardbackup health_kuma --push-url="http://kuma.test/api/push/good$SECRET")"
	check "http:// URL refused" test $? -ne 0
	out="$(WE siteguardbackup health_kuma --push-url="https://kuma.test/dashboard/1")"
	check "URL without /api/push/ refused" grep -q "api/push" <<<"$out"
	check "nothing stored after refusals" test "$(W siteguardbackup health_kuma)" = "Uptime Kuma push: not configured"
	out="$(WE siteguardbackup health_kuma --push-url="$GOOD?status=up&msg=OK&ping=")"
	check "valid URL (with Kuma's query) accepted" grep -q "Success: Uptime Kuma push URL set: https://kuma.test/api/push/\*\*\*\*" <<<"$out"
	no_secret "set output masks the token" "$out"
	check "stored without the query" test "$(PHP 'echo get_option("sgbackup_health_kuma_url");')" = "$GOOD"
	out="$(WE siteguardbackup health_kuma)"
	check "status shows the masked URL" grep -q "Uptime Kuma push: https://kuma.test/api/push/\*\*\*\*" <<<"$out"
	check "status recommends the Kuma interval" grep -q "heartbeat interval >= 1320s" <<<"$out"
	if [ "$(PHP 'echo (defined("DISABLE_WP_CRON") && DISABLE_WP_CRON) ? 1 : 0;')" = 0 ]; then
		check "status warns WP-Cron is traffic-driven" grep -q "DISABLE_WP_CRON" <<<"$out"
	else
		check "no traffic-driven WP-Cron warning with DISABLE_WP_CRON set" test "$(grep -c "DISABLE_WP_CRON" <<<"$out")" = 0
	fi
	local last_run; last_run="$(PHP 'echo (int) get_option("sgbackup_health_last_run");')"
	PHP 'update_option("sgbackup_health_last_run", time() - 7200);' >/dev/null
	out="$(WE siteguardbackup health_kuma)"
	PHP "update_option('sgbackup_health_last_run', $last_run);" >/dev/null
	check "status warns when the last health run is overdue" grep -q "WP-Cron does not seem to be running" <<<"$out"
	no_secret "status output masks the token" "$out"
}

t_push_up() {
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null
	kuma_reset
	local out; out="$(WE siteguardbackup health_check)"
	check "health_check pushed exactly once" test "$(kuma_count)" = 1
	check "push is a GET to the push path" test "$(kuma_last method) $(kuma_last path)" = "GET /api/push/good$SECRET"
	check "status=up" test "$(kuma_last status)" = up
	local total; total="$(PHP 'echo count(SiteGuard_Backup_Health_Monitor::instance()->get_results());')"
	check "msg '$total/$total sites operational' (got '$(kuma_last msg)')" test "$(kuma_last msg)" = "$total/$total sites operational"
	check "ping is the run time in ms" grep -qE '^[0-9]+$' <<<"$(kuma_last ping)"
	check "user agent names SiteGuard" grep -q "SiteGuard-Health-Monitor" <<<"$(kuma_last ua)"
	check "health_check output reports the push" grep -q "Uptime Kuma: .*last push OK (status=up, HTTP 200)" <<<"$out"
	no_secret "health_check output masks the token" "$out"
}

t_push_down() {
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null
	local path want
	if [ "$INST" = "$MULTI" ]; then path=/sub1/; want="$INST.test/sub1 (HTTP 503)"; else path=/; want="$INST.test (HTTP 503)"; fi
	fail_path "$path"
	kuma_reset
	W siteguardbackup health_check >/dev/null
	fail_none
	local total; total="$(PHP 'echo count(SiteGuard_Backup_Health_Monitor::instance()->get_results());')"
	check "failing site: status=down" test "$(kuma_last status)" = down
	check "msg names the site and reason (got '$(kuma_last msg)')" test "$(kuma_last msg)" = "1/$total failing; down: $want"
	kuma_reset
	W siteguardbackup health_check >/dev/null
	check "recovered: status=up again" test "$(kuma_last status)" = up
}

t_push_failures() {
	local before out code
	for u in "$PAUSED" "$NODNS" "$SLOW"; do
		W siteguardbackup health_kuma --push-url="$u" >/dev/null
		kuma_reset
		before="$(PHP 'echo (int) get_option("sgbackup_health_last_run");')"
		sleep 1
		local t0=$SECONDS
		out="$(WE siteguardbackup health_check)"; code=$?
		local kind="${u#https://}"; kind="${kind%%$SECRET}"
		check "$kind: health_check still succeeds (exit $code)" test "$code" -eq 0
		check "$kind: results still stored" test "$(PHP 'echo (int) get_option("sgbackup_health_last_run");')" -gt "$before"
		check "$kind: last push recorded as failed" test "$(PHP 'echo json_encode(SiteGuard_Backup_Health_Kuma::get_last()["ok"]);')" = false
		no_secret "$kind: output masks the token" "$out"
		case "$kind" in
			*paused*) check "paused: Kuma's reason recorded" grep -q "last push FAILED (status=up, HTTP 404, Monitor not found or not active.)" <<<"$out" ;;
			*slow*)   check "slow: gave up after ~10 s (took $((SECONDS - t0)) s)" test $((SECONDS - t0)) -lt 25
			          sleep 12 ;;   # let the single-threaded fake finish the slow answer
		esac
	done
	# Same URL failing twice (Kuma itself down), then answering again.
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null
	kuma_down 1
	W siteguardbackup health_check >/dev/null
	W siteguardbackup health_check >/dev/null
	kuma_down 0
	W siteguardbackup health_check >/dev/null
	local log; log="$(debug_log)"
	check "failures logged" grep -q "SiteGuard Backup: Uptime Kuma push failed:" <<<"$log"
	check "Kuma's 5xx logged" grep -q "Uptime Kuma push failed: HTTP 503" <<<"$log"
	check "logged once per change, not per run (4 changes)" test "$(grep -c "Uptime Kuma push failed" <<<"$log")" -eq 4
	check "recovery logged" grep -q "Uptime Kuma push working again" <<<"$log"
	no_secret "debug.log never contains the token" "$log"
}

t_run_error() {
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null
	kuma_reset
	fail_path throw
	local out; out="$(PHP 'try { SiteGuard_Backup_Health_Monitor::instance()->run_checks(); echo "no-throw"; } catch (Throwable $e) { echo "threw ", get_class($e); }')"
	fail_none
	check "run still throws to its caller ($out)" test "$out" = "threw RuntimeException"
	check "a push was still sent" test "$(kuma_count)" = 1
	check "status=down, msg names the failure (got '$(kuma_last msg)')" test "$(kuma_last status) $(kuma_last msg)" = "down health run failed: RuntimeException"
}

t_cron() {
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null
	kuma_reset
	W cron event run sgbackup_health_check >/dev/null
	check "cron run pushed (status=$(kuma_last status))" test "$(kuma_count) $(kuma_last status)" = "1 up"
	W siteguardbackup health_config disable >/dev/null
	kuma_reset
	W eval 'do_action("sgbackup_health_check");' >/dev/null
	check "disabled monitor: cron pushes nothing" test "$(kuma_count)" = 0
	W siteguardbackup health_config enable --interval=every10min >/dev/null
}

t_cli_test_clear() {
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null
	W siteguardbackup health_check >/dev/null
	kuma_reset
	local out code
	out="$(WE siteguardbackup health_kuma --test)"; code=$?
	check "--test with a working monitor exits 0" test "$code" -eq 0
	check "--test reports Kuma's answer" grep -q "Success: Kuma accepted the push: status=up .*HTTP 200" <<<"$out"
	check "--test message marked as test" grep -q "^test: " <<<"$(kuma_last msg)"
	no_secret "--test output masks the token" "$out"
	W siteguardbackup health_kuma --push-url="$PAUSED" >/dev/null
	out="$(WE siteguardbackup health_kuma --test)"; code=$?
	check "--test with a paused monitor exits 1" test "$code" -eq 1
	check "--test names Kuma's reason" grep -q "Monitor not found or not active" <<<"$out"
	out="$(WE siteguardbackup health_kuma --clear)"
	check "--clear" grep -q "Success: Uptime Kuma push URL removed." <<<"$out"
	kuma_reset
	W siteguardbackup health_check >/dev/null
	check "no URL: nothing pushed" test "$(kuma_count)" = 0
	out="$(WE siteguardbackup health_kuma --test)"; code=$?
	check "--test without a URL fails" test "$code" -eq 1
	check "health_status shows 'not configured'" grep -q "Uptime Kuma push: not configured" <<<"$(WE siteguardbackup health_status)"
}

t_no_leaks() {
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null
	W siteguardbackup health_check >/dev/null
	no_secret "health_status" "$(WE siteguardbackup health_status)"
	no_secret "health_status --format=json" "$(WE siteguardbackup health_status --format=json)"
	no_secret "health_check --format=json" "$(WE siteguardbackup health_check --format=json)"
	no_secret "health_config" "$(WE siteguardbackup health_config show)"
	no_secret "AJAX health_kuma status/test responses" "$(PHP 'require_once SGBACKUP_DIR . "includes/class-commands.php"; $c = new SiteGuard_Backup_Commands(); echo json_encode(array($c->health_kuma(array("op" => "status")), $c->health_kuma(array("op" => "test")), $c->get_health_status(array())));')"
	no_secret "settings export" "$(PHP 'require_once SGBACKUP_DIR . "includes/class-commands.php"; $c = new SiteGuard_Backup_Commands(); echo json_encode($c->export_settings(array()));')"
	local audit
	audit="$(PHP "require_once SGBACKUP_DIR . 'includes/class-commands.php'; \$a = SiteGuard_Backup_Audit_Log::instance(); \$a->set_enabled(true);
		\$c = new SiteGuard_Backup_Commands(); \$c->health_kuma(array('op' => 'save', 'url' => '$GOOD')); \$c->health_kuma(array('op' => 'clear'));
		\$c->health_kuma(array('op' => 'save', 'url' => '$GOOD')); echo json_encode(\$a->get_entries()); \$a->set_enabled(false);")"
	check "audit log records set and clear" grep -q "health_kuma_cleared" <<<"$audit"
	no_secret "audit log" "$audit"
	local key; key="$(W siteguardbackup health_api_key --regenerate | sed -n 's/^API Key: //p')"
	local body; body="$(curl -s -H "Host: $INST.test" -H "Authorization: Bearer $key" "http://127.0.0.1:$(port "$INST")/?rest_route=/sgbackup/v1/health")"
	check "REST /health answers" grep -q '"api_version":2' <<<"$body"
	no_secret "REST /health" "$body"
	body="$(curl -s -X POST -H "Host: $INST.test" -H "Authorization: Bearer $key" "http://127.0.0.1:$(port "$INST")/?rest_route=/sgbackup/v1/health/check")"
	no_secret "REST POST /health/check" "$body"
	check "REST-triggered run pushed too" test "$(kuma_last status)" = up
	no_secret "debug.log" "$(debug_log)"
}

# The Health tab: the section renders with the masked URL only.
t_admin_page() {
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null
	local html
	html="$(PHP 'wp_set_current_user(1); $opts = SiteGuard_Backup_Options::instance(); ob_start(); include SGBACKUP_DIR . "templates/tab-health.php"; echo ob_get_clean();')"
	check "Health tab has the Kuma section" grep -q 'id="sgbackup-kuma-section"' <<<"$html"
	check "Health tab shows the masked URL" grep -q "https://kuma.test/api/push/\*\*\*\*" <<<"$html"
	check "Health tab has the test button" grep -q 'id="sgbackup-kuma-test"' <<<"$html"
	no_secret "Health tab HTML" "$html"
}

# Network-level: set from the network, seen (and pushed) the same from a subsite.
t_multisite_network() {
	[ "$INST" = "$MULTI" ] || return 0
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null
	local sub; sub="$("$IM" wp "$INST" --url="http://$INST.test/sub2/" siteguardbackup health_kuma 2>&1)"
	check "subsite sees the network's push URL" grep -q "Uptime Kuma push: https://kuma.test/api/push/\*\*\*\*" <<<"$sub"
	check "stored on the main site only" test "$(PHP 'switch_to_blog(3); $v = get_option("sgbackup_health_kuma_url", "none"); restore_current_blog(); echo $v;')" = none
	local out; out="$("$IM" wp "$INST" --url="http://$INST.test/sub2/" siteguardbackup health_kuma --clear 2>&1)"
	check "cleared from a subsite context clears the network's" grep -q "not configured" <<<"$(WE siteguardbackup health_kuma)"
}

# A database restore keeps this site's own push URL, not the one in the backup.
t_restore_keeps_url() {
	W siteguardbackup health_kuma --push-url="$PAUSED" >/dev/null   # in the backup
	W option update sgx_kuma_marker in-backup >/dev/null
	W siteguardbackup backup --include-files=themes >/dev/null 2>&1
	sleep 1
	local ts; ts="$(PHP '$h = array_keys(SiteGuard_Backup_History::get_history_raw()); echo $h ? max($h) : "";')"
	[ -n "$ts" ] || { bad "backup for the restore test was not created"; return; }
	W siteguardbackup health_kuma --push-url="$GOOD" >/dev/null      # this site's
	W option update sgx_kuma_marker after-backup >/dev/null
	W siteguardbackup restore "$ts" --entities=db --yes >/dev/null 2>&1
	check "the database was restored (marker from the backup is back)" test "$(W option get sgx_kuma_marker)" = in-backup
	check "after a DB restore the site keeps its own push URL" test "$(PHP 'echo SiteGuard_Backup_Health_Kuma::get_url();')" = "$GOOD"
	no_secret "restore log" "$(PHP '$d = SiteGuard_Backup_Options::instance()->get_backup_dir(); $l = glob($d . "/log.*.txt"); rsort($l); echo $l ? file_get_contents($l[0]) : "";')"
}

TESTS=(t_cli_set t_push_up t_push_down t_push_failures t_run_error t_cron t_cli_test_clear t_no_leaks t_admin_page t_multisite_network t_restore_keeps_url)

for INST in "$SINGLE" "$MULTI"; do
	echo "== instance $INST"
	W plugin is-active siteguardbackup >/dev/null 2>&1 || W plugin is-active siteguardbackup --network >/dev/null 2>&1 \
		|| { echo "siteguardbackup not active on $INST" >&2; exit 2; }
	setup_instance
	for t in "${TESTS[@]}"; do
		want "$t" || continue
		echo "-- $t"
		"$t" || true
	done
	W siteguardbackup health_kuma --clear >/dev/null
	if grep -E "PHP (Fatal|Parse)" <<<"$(debug_log)"; then
		bad "PHP fatal errors in debug.log"
	else
		ok "no PHP fatal errors in debug.log"
	fi
done

echo
echo "$PASS passed, $FAIL failed"
for f in "${FAILED[@]}"; do echo "  - $f"; done
[ "$FAIL" -eq 0 ]
