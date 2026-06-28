<?php
/** Standalone-binary entry point: builds the whole static site in one `./wikven build` run. */

// __DIR__ is the extracted, writable embed root (MediaWiki's $IP).
$ip = __DIR__;

// FrankenPHP leaves PHP_BINARY empty in CLI, so resolve the running binary via /proc/self/exe.
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

// Static binaries ship no CA certs; point openssl at the host bundle for HTTPS unless already set.
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

// Start clean: the embed root may persist between runs, so drop any prior install.
@unlink("$ip/LocalSettings.php");
foreach (glob("$work/.cache/*.sqlite") ?: [] as $stale) {
	@unlink($stale);
}

// Run embedded maintenance scripts by re-invoking the binary (run.php relative, targets absolute).
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
