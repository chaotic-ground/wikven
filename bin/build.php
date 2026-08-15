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
if (getenv('SSL_CERT_FILE') === false && getenv('SSL_CERT_DIR') === false) {
	// openssl reports the exact bundle path/dir it was compiled to look for, ahead of the guessed
	// distro paths below, which cover systems where that compiled-in default isn't actually there.
	$locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
	$candidates = array_filter([
		$locations['default_cert_file'] ?? null,
		'/etc/ssl/certs/ca-certificates.crt',
		'/etc/pki/tls/certs/ca-bundle.crt',
		'/etc/ssl/cert.pem'
	]);
	foreach ($candidates as $ca) {
		if (is_file($ca)) {
			putenv("SSL_CERT_FILE=$ca");
			$_ENV['SSL_CERT_FILE'] = $ca;
			break;
		}
	}
	// No single bundle file found; fall back to openssl's compiled-in certs directory, if present.
	$certDir = $locations['default_cert_dir'] ?? '';
	if (getenv('SSL_CERT_FILE') === false && $certDir !== '' && is_dir($certDir)) {
		putenv("SSL_CERT_DIR=$certDir");
		$_ENV['SSL_CERT_DIR'] = $certDir;
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

// 3. Fetch third-party extensions/skins, apply any extension schema updates, then 4. build.
$run(["$ip/extensions/Wikven/maintenance/fetchExtensions.php"]);
$run(['update', '--quick']);
$run(["$ip/extensions/Wikven/maintenance/build.php"]);

// Drop the per-page history the file cache emits, as the Docker run does. Done without a shell: this
// is the entry point that has to work on a host with no distro toolchain -- it hunts for its own
// executable and a CA bundle above for the same reason -- and each removal's result is checked so a
// failure here is reported instead of leaving dist/history/ published under a misleading "done".
$history = "$work/dist/history";
if (is_dir($history)) {
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($history, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($entries as $entry) {
		// isDir() follows symlinks and rmdir() would then fail on the link itself; unlink handles both.
		$ok = ( $entry->isDir() && !$entry->isLink() ) ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		if (!$ok) {
			fwrite(STDERR, 'wikven: could not remove ' . $entry->getPathname() . "\n");
			exit(1);
		}
	}
	if (!rmdir($history)) {
		fwrite(STDERR, "wikven: could not remove $history\n");
		exit(1);
	}
}

fwrite(STDERR, "wikven: done -> $work/dist\n");
