<?php
/**
 * What the standalone binary's entry points share: everything `build` and `translate` both need
 * before an embedded maintenance script can run.
 *
 * The binary is one executable with an app tree unpacked beside it, so every run starts by finding
 * itself, working out where the site lives, and installing a MediaWiki for the run. Both commands
 * need all of that; only what they do afterwards differs, which is what their own files hold.
 *
 * Returns the handles a caller finishes with:
 *   run  - run an embedded maintenance script, exiting with its status if it fails
 *   self - this executable, for a caller that wants a step's status rather than the exit
 *   work - the working directory, resolved to an absolute path
 *   ip   - the extracted embed root, which is MediaWiki's $IP
 *
 * @return array{run: callable, self: string, work: string, ip: string}
 */
// __DIR__ is the extracted, writable embed root (MediaWiki's $IP).
$ip = __DIR__;

// FrankenPHP leaves PHP_BINARY empty in CLI, so resolve the running binary via /proc/self/exe.
$self = PHP_BINARY;
if ($self === '' || !is_executable($self)) {
	$self = @readlink('/proc/self/exe') ?: '';
}
if ($self === '' || !is_executable($self)) {
	fwrite(STDERR, "wikven: cannot locate the wikven executable to run its steps\n");
	exit(1);
}

$work = getenv('WIKVEN_WORKDIR');
if ($work === false || $work === '') {
	$work = getcwd();
}
// Resolved to an absolute path before anything else reads it. The skin passes are spawned with the
// MediaWiki root as their working directory -- inside the binary that is the temporary directory the
// embedded app is unpacked into -- and they inherit this variable. A relative value, which is what
// the documented `WIKVEN_WORKDIR=. ./wikven build` gives, therefore means something different in a
// pass than it did here: the pass opens an empty database beside the unpacked app rather than the
// one the orchestrator just filled, and dies on "no such table: page". Under Docker the workdir is
// /workspace and this never came up.
$resolved = realpath($work);
$work = $resolved !== false ? $resolved : rtrim($work, '/');
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
@mkdir("$work/.cache", 0777, true);

// Start clean: the embed root may persist between runs, so drop any prior install.
@unlink("$ip/LocalSettings.php");
foreach (glob("$work/.cache/*.sqlite") ?: [] as $stale) {
	@unlink($stale);
}

// Run embedded maintenance scripts by re-invoking the binary (run.php relative, targets absolute).
// A step that fails ends the run here, which is right for the steps this file takes: none of them
// is anything a caller asked for, so a failure in one is a failure to start rather than a result.
$run = static function (array $args) use ($self, $ip) {
	$cmd = array_merge([$self, 'php-cli', 'maintenance/run.php'], $args);
	passthru(implode(' ', array_map('escapeshellarg', $cmd)), $code);
	if ($code !== 0) {
		fwrite(STDERR, "wikven: step failed (exit $code)\n");
		exit($code);
	}
};

// Install MediaWiki: creates the SQLite schema and LocalSettings.php in $ip.
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

// Wire in WikvenSettings (the extracted root is writable).
file_put_contents("$ip/LocalSettings.php", "\nrequire_once '$ip/WikvenSettings.php';\n", FILE_APPEND);

// Third-party extensions and skins, fetched before anything boots WikvenSettings and looks for
// them on disk. A translate run needs them for the same reason a build does: the site's own
// configuration is read either way, and an extension it lists is loaded either way.
$run(["$ip/extensions/Wikven/maintenance/fetchExtensions.php"]);

return ['run' => $run, 'self' => $self, 'work' => $work, 'ip' => $ip];
