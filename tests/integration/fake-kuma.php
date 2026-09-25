<?php
/**
 * A tiny HTTPS stand-in for Uptime Kuma's push endpoint, for health-kuma.sh.
 *
 *   php fake-kuma.php <cert.pem> <key.pem> <log file>
 *
 * Listens on :443 and appends one JSON line per request to <log file>:
 * {"method","path","status","msg","ping","ua"}. Answers like Kuma:
 *   /api/push/good...   200 {"ok":true}
 *   /api/push/paused... 404 {"ok":false,"msg":"Monitor not found or not active."}
 *   /api/push/slow...   waits 20 s, then 200 (the plugin gives up after 10 s)
 *   /api/push/redir<x>  302 to /api/push/good<x> (the plugin must not follow)
 *   anything else       404
 *   everything          503 while /tmp/kuma-down holds "1" (Kuma itself down)
 * Runs as a throwaway container on the instance mesh; test use only.
 */

if ('cli' !== PHP_SAPI) exit;
list(, $cert, $key, $log) = $argv;

$ctx = stream_context_create(array('ssl' => array(
	'local_cert' => $cert,
	'local_pk'   => $key,
	'verify_peer' => false,
)));
$server = stream_socket_server('tls://0.0.0.0:443', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$server) {
	fwrite(STDERR, "listen failed: $errstr\n");
	exit(1);
}
fwrite(STDERR, "fake kuma listening\n");

while (true) {
	$conn = @stream_socket_accept($server, -1);
	if (!$conn) continue;
	stream_set_timeout($conn, 5);
	$ua = '';
	$request_line = fgets($conn);
	while (($line = fgets($conn)) !== false && trim($line) !== '') {
		if (stripos($line, 'User-Agent:') === 0) $ua = trim(substr($line, 11));
	}
	$parts = explode(' ', trim((string) $request_line));
	$target = isset($parts[1]) ? $parts[1] : '';
	$path = (string) parse_url($target, PHP_URL_PATH);
	parse_str((string) parse_url($target, PHP_URL_QUERY), $q);
	file_put_contents($log, json_encode(array(
		'method' => isset($parts[0]) ? $parts[0] : '',
		'path'   => $path,
		'status' => isset($q['status']) ? $q['status'] : null,
		'msg'    => isset($q['msg']) ? $q['msg'] : null,
		'ping'   => isset($q['ping']) ? $q['ping'] : null,
		'ua'     => $ua,
	)) . "\n", FILE_APPEND);

	$code = 404;
	$body = '{"ok":false,"msg":"Not found"}';
	$extra = '';
	if ('1' === trim((string) @file_get_contents('/tmp/kuma-down'))) {
		$code = 503; $body = 'Service Unavailable';
	} elseif (preg_match('#^/api/push/good#', $path)) {
		$code = 200; $body = '{"ok":true}';
	} elseif (preg_match('#^/api/push/paused#', $path)) {
		$body = '{"ok":false,"msg":"Monitor not found or not active."}';
	} elseif (preg_match('#^/api/push/slow#', $path)) {
		sleep(20);
		$code = 200; $body = '{"ok":true}';
	} elseif (preg_match('#^/api/push/redir(.*)$#', $path, $m)) {
		$code = 302; $body = '{"ok":true}';
		$extra = 'Location: https://kuma.test/api/push/good' . $m[1] . '?' . (string) parse_url($target, PHP_URL_QUERY) . "\r\n";
	}
	@fwrite($conn, "HTTP/1.1 $code X\r\n" . $extra . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
	@fclose($conn);
}
