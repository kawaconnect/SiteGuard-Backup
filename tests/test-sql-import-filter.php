<?php
/**
 * Unit tests for the SQL import filters (no WordPress, no database).
 *
 * A backup's SQL dump is attacker-controlled input: an administrator may upload
 * one from anywhere, and on a network a SUBSITE administrator may upload one and
 * import it into their own subsite. These check that
 *
 *   - subsite_import_statement_allowed() lets through only plain DROP / CREATE /
 *     INSERT ... VALUES <literals> on a table under the target prefix,
 *   - insert_values_are_literals() accepts what mysqldump and SiteGuard's own
 *     dumper write and refuses subqueries, function calls and trailing clauses,
 *   - is_dangerous_sql() (used by every restore path) refuses statements that
 *     leave the row or the database.
 *
 *   php tests/test-sql-import-filter.php
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	fwrite(STDERR, "Refusing to run as root. Run as an unprivileged user (see tests/README.md).\n");
	exit(2);
}

define('ABSPATH', sys_get_temp_dir() . '/sg-sql-filter-test/');
define('SGBACKUP_DIR', dirname(__DIR__) . '/');
require __DIR__ . '/../includes/class-database-utility.php';
$U = 'SiteGuard_Backup_Database_Utility';

$failures = 0;
$count = 0;
function check($name, $cond) {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name\n"; }
}
$danger = new ReflectionMethod($U, 'is_dangerous_sql');
$danger->setAccessible(true);
$dangerous = function ($q) use ($danger) { return $danger->invoke(null, $q); };
$allowed = function ($q, $prefixes = array('wp_7_')) use ($U) { return $U::subsite_import_statement_allowed($q, $prefixes); };
$literals = function ($v) use ($U) { return $U::insert_values_are_literals($v); };

// ---- literal rows -----------------------------------------------------------
foreach (array(
	"(1,'a','b')",
	"(1,'it\\'s','x''y'),(2,NULL,'')",
	"(-3,1.5e-10,0x4142,_binary 'ab',TRUE)",
	"(1,'(SELECT user_pass FROM wp_users)')",
	"(1,'a;b -- c /* d */')",
	"(1,\"double \\\" quoted\")",
	"(1,'multi\nline')",
	"(1, X'4142', N'abc')",
) as $v) {
	check('literal rows accepted: ' . substr(str_replace("\n", '\n', $v), 0, 50), true === $literals($v));
}
foreach (array(
	"(1,(SELECT user_pass FROM wp_users LIMIT 1))",
	"(1,LOAD_FILE('/etc/passwd'))",
	"(1,'a') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
	"(1,'a'); DROP TABLE wp_users",
	"(1,@@version)",
	"(1,`col`)",
	"(1,'unterminated)",
	"(1, 2 + user())",
	"(1,CONCAT('a','b'))",
	"",
) as $v) {
	check('non-literal rows refused: ' . substr($v, 0, 50), false === $literals($v));
}
// Large row data stays linear (no PCRE limits): 4 MB of values.
$big = '(1,\'' . str_repeat('ab\\\'cd ', 600000) . '\')';
$t = microtime(true);
check('4 MB of row data is accepted', true === $literals($big));
check('...in well under 2 seconds', (microtime(true) - $t) < 2);

// ---- subsite import allowlist -----------------------------------------------
foreach (array(
	"DROP TABLE IF EXISTS `wp_7_posts`",
	"CREATE TABLE `wp_7_posts` (\n  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `t` text COMMENT 'select from; union',\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
	"INSERT INTO `wp_7_options` VALUES (1,'siteurl','https://example.test','yes')",
	"INSERT INTO `wp_7_options` (`option_id`, `option_name`) VALUES (1,'x'),(2,'y')",
	"SET NAMES utf8mb4",
	"SET FOREIGN_KEY_CHECKS = 0",
	"START TRANSACTION",
	"COMMIT",
) as $q) {
	check('subsite import allows: ' . substr(str_replace("\n", ' ', $q), 0, 55), true === $allowed($q));
}
foreach (array(
	// the reported bypasses
	"INSERT INTO wp_sitemeta (meta_id,site_id,meta_key,meta_value) VALUES (9,1,'site_admins','x') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
	"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (5,'wp_capabilities','a:1:{s:13:\"administrator\";b:1;}')",
	"INSERT INTO `wp_7_options` (option_name, option_value) SELECT user_login, user_pass FROM wp_users",
	"INSERT INTO `wp_7_options` (`option_name`, `option_value`) SELECT user_login, user_pass FROM wp_users",
	"INSERT INTO `wp_7_options` VALUES (1,(SELECT user_pass FROM wp_users LIMIT 1))",
	"INSERT LOW_PRIORITY INTO `wp_7_options` VALUES (1,'a')",
	"INSERT INTO `wp_7_options` VALUES (1,'a') ON DUPLICATE KEY UPDATE option_value='b'",
	"DROP TABLE IF EXISTS `wp_7_x`, `wp_users`, `wp_blogs`",
	"DROP TABLE wp_users",
	"DROP TABLE `wp_users`",
	"DROP TABLE `wp_70_posts`",
	"RENAME TABLE `wp_7_x` TO `wp_users`",
	"UPDATE `wp_users` SET user_pass='x'",
	"UPDATE `wp_7_options` SET option_value='x'",
	"DELETE FROM `wp_7_options`",
	"WITH c AS (SELECT 1) UPDATE wp_users SET user_pass='x'",
	"CREATE TABLE `wp_7_x` AS SELECT * FROM wp_users",
	"CREATE TABLE `wp_7_x` (`a` int) DATA DIRECTORY='/var/www'",
	"CREATE TABLE `wp_7_x` (`a` int) SELECT user_pass FROM wp_users",
	"CREATE TABLE wp_7_x (a int)",
	"CREATE TABLE `otherdb`.`wp_7_x` (`a` int)",
	"INSERT INTO `otherdb`.`wp_7_x` VALUES (1)",
	"SET GLOBAL general_log = 1",
	"SET PASSWORD = 'x'",
	"SET @a = (SELECT user_pass FROM wp_users LIMIT 1)",
	"GRANT ALL ON *.* TO 'x'@'%'",
	"CALL p()",
	"ALTER TABLE `wp_7_x` RENAME TO `wp_users`",
	"TRUNCATE TABLE `wp_7_options`",
	"REPLACE INTO `wp_7_options` VALUES (1,'a')",
	"/*!50000 DROP TABLE wp_users */",
) as $q) {
	check('subsite import refuses: ' . substr($q, 0, 60), false === $allowed($q));
}
// The executors pass the statement with its terminating ";" (and newline).
check('trailing ";" accepted on DROP', true === $allowed("DROP TABLE IF EXISTS `wp_7_posts`;\n"));
check('trailing ";" accepted on CREATE', true === $allowed("CREATE TABLE `wp_7_x` (\n  `a` int\n) ENGINE=InnoDB;"));
check('trailing ";" accepted on INSERT', true === $allowed("INSERT INTO `wp_7_options` (`a`, `b`) VALUES\n('1', 'x'),\n('2', 'y');\n"));
check('trailing ";" accepted on SET', true === $allowed("SET FOREIGN_KEY_CHECKS = 0;"));
check('a second statement after ";" is still refused', false === $allowed("INSERT INTO `wp_7_options` VALUES (1,'a'); DROP TABLE `wp_users`;"));
check('restore accepts INSERT with trailing ";"', false === $dangerous("INSERT INTO `wp_posts` (`ID`, `t`) VALUES\n('1', 'a');\n"));
check('restore refuses stacked statement after INSERT', true === $dangerous("INSERT INTO `wp_posts` VALUES (1,'a'); DROP TABLE x;"));
check('a table under another mapped prefix is allowed', true === $allowed("DROP TABLE `wp_9_posts`", array('wp_7_', 'wp_9_')));
check('an empty prefix allows nothing', false === $allowed("DROP TABLE `wp_7_posts`", array('')));

// ---- is_dangerous_sql (every restore path) ----------------------------------
foreach (array(
	"INSERT INTO `wp_posts` VALUES (1,'Load data into outfile; grant all','x')",
	"INSERT INTO `wp_posts` (`ID`,`post_content`) VALUES (1,'<a href=\"x\">select * from y</a>')",
	"INSERT INTO wp_posts VALUES (1,'a')",
	"INSERT IGNORE INTO `wp_posts` VALUES (1,'a')",
	"REPLACE INTO `wp_posts` VALUES (1,'a')",
	"CREATE TABLE `wp_posts` (`a` text COMMENT 'used to load data, select rows') ENGINE=InnoDB",
	"DROP TABLE IF EXISTS `wp_posts`",
	"ALTER TABLE `wp_posts` ADD PRIMARY KEY (`ID`)",
	"UPDATE `wp_options` SET option_value='a.b' WHERE option_name='x'",
	"DELETE FROM `wp_options` WHERE option_name='x'",
	"SET NAMES utf8mb4",
) as $q) {
	check('restore accepts: ' . substr($q, 0, 60), false === $dangerous($q));
}
foreach (array(
	"INSERT INTO wp_posts (a,b) SELECT user_login, user_pass FROM otherdb.wp_users",
	"INSERT INTO `wp_posts` VALUES (1,LOAD_FILE('/etc/passwd'))",
	"INSERT INTO `wp_posts` VALUES (1,(SELECT 1))",
	"INSERT INTO `wp_options` VALUES (1,'a') ON DUPLICATE KEY UPDATE option_value='b'",
	"INSERT INTO `otherdb`.`wp_posts` VALUES (1)",
	"DROP TABLE `wp_x`, `otherdb`.`wp_posts`",
	"RENAME TABLE `a` TO `otherdb`.`a`",
	"UPDATE `wp_options` o JOIN `otherdb`.`wp_users` u SET u.user_pass='x'",
	"UPDATE `wp_options`, `otherdb`.`wp_users` SET user_pass='x'",
	"CREATE TABLE `wp_x` AS SELECT * FROM otherdb.wp_users",
	"CREATE TABLE `wp_x` (`a` int) DATA DIRECTORY='/tmp'",
	"GRANT ALL ON *.* TO 'x'@'%'",
	"USE otherdb",
	"SELECT 1 INTO OUTFILE '/tmp/x'",
	"SET GLOBAL general_log=1",
) as $q) {
	check('restore refuses: ' . substr($q, 0, 60), true === $dangerous($q));
}

echo "\n$count checks, $failures failure(s)\n";
exit($failures ? 1 : 0);
