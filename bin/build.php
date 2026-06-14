<?php
/**
 * Standalone-binary entry point: build the whole static site in one invocation.
 * The `build` Caddy subcommand (see caddy/) runs this through php-cli, so the
 * user-facing command is:
 *
 *   WIKVEN_WORKDIR=/path/to/work  ./wikven build
 *
 * Reads $WIKVEN_WORKDIR/src (input), writes $WIKVEN_WORKDIR/dist (output), and
 * keeps ephemeral state in $WIKVEN_WORKDIR/.cache. WIKVEN_WORKDIR defaults to the
 * current directory.
 *
 * The binary embeds MediaWiki; this script drives the same steps the Docker
 * `run` script does (install, then load WikvenSettings, then fetch third-party
 * components, then build) by re-invoking the binary once per step. It is shipped
 * to the embed root by Dockerfile.binary, so php-cli finds it as "build.php".
 */

// __DIR__ is the extracted, writable embed root (MediaWiki's $IP).
$ip = __DIR__;

// Re-invoking the build steps means running the wikven binary again. FrankenPHP
// leaves PHP_BINARY empty in CLI mode, so resolve the running executable via
// /proc/self/exe (read inside PHP to get the real path; the literal string
// "/proc/self/exe" must not be passed to a subshell, where it would resolve to
// the shell instead).
$self = PHP_BINARY;
if ($self === '' || !is_executable($self)) {
	$self = @readlink('/proc/self/exe') ?: '';
}
if ($self === '' || !is_executable($self)) {
	fwrite(STDERR, "wikven: cannot locate the wikven executable to run build steps\n");
	exit(1);
}

$work = getenv('WIKVEN_WORKDIR');
if ($work === false || $work === '') {
	$work = getcwd();
}
$work = rtrim($work, '/');
putenv("WIKVEN_WORKDIR=$work");
$_ENV['WIKVEN_WORKDIR'] = $work;

// Static binaries bundle no CA certificates; point openssl at the host bundle so
// InstantCommons (HTTPS) and git-over-HTTPS work, unless the user set it already.
if (getenv('SSL_CERT_FILE') === false) {
	foreach ([
		'/etc/ssl/certs/ca-certificates.crt',
		'/etc/pki/tls/certs/ca-bundle.crt',
		'/etc/ssl/cert.pem'
	] as $ca) {
		if (is_file($ca)) {
			putenv("SSL_CERT_FILE=$ca");
			$_ENV['SSL_CERT_FILE'] = $ca;
			break;
		}
	}
}

if (!is_dir("$work/src")) {
	fwrite(STDERR, "wikven: no source directory at $work/src\n");
	exit(1);
}
@mkdir("$work/dist", 0777, true);
@mkdir("$work/.cache", 0777, true);

// Start each run from a clean slate: the embed root may persist between runs
// (same checksum extracts to the same /tmp dir), so drop any prior install.
@unlink("$ip/LocalSettings.php");
foreach (glob("$work/.cache/*.sqlite") ?: [] as $stale) {
	@unlink($stale);
}

// Run an embedded maintenance script by re-invoking the binary. The script path
// for php-cli is resolved relative to the embed root, so maintenance/run.php is
// passed relatively while the scripts it runs are passed as absolute paths.
$run = static function (array $args) use ($self, $ip) {
	$cmd = array_merge([$self, 'php-cli', 'maintenance/run.php'], $args);
	passthru(implode(' ', array_map('escapeshellarg', $cmd)), $code);
	if ($code !== 0) {
		fwrite(STDERR, "wikven: build step failed (exit $code)\n");
		exit($code);
	}
};

// 1. Install MediaWiki: creates the SQLite schema and LocalSettings.php in $ip.
$run([
	'install',
	'--dbtype',
	'sqlite',
	'--dbpath',
	"$work/.cache",
	'--scriptpath',
	'',
	'--pass',
	'adminpassword',
	'MediaWiki',
	'Admin'
]);

// 2. Wire in WikvenSettings (the extracted root is writable).
file_put_contents("$ip/LocalSettings.php", "\nrequire_once '$ip/WikvenSettings.php';\n", FILE_APPEND);

// 3. Fetch third-party extensions/skins, then 4. build the static site.
$run(["$ip/extensions/Wikven/maintenance/fetchExtensions.php"]);
$run(["$ip/extensions/Wikven/maintenance/build.php"]);

// Drop the per-page history the file cache emits, as the Docker run does.
$history = "$work/dist/history";
if (is_dir($history)) {
	passthru('rm -rf ' . escapeshellarg($history));
}

fwrite(STDERR, "wikven: done -> $work/dist\n");
