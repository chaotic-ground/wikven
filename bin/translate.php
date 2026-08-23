<?php
/**
 * Standalone-binary entry point for `./wikven translate <mark|scaffold|check|stamp>`.
 *
 * The four helpers are maintenance scripts of the extension: they need a booted MediaWiki to
 * autoload Translate and read the site's configuration, and then they work on the source tree
 * directly and exit without building anything. That is the shape the Docker entry point runs them
 * in too, so a run means the same thing on either product; what differs here is that the boot is
 * the binary's own, which is what the prelude below arranges.
 */

/** The scripts behind the subcommands, which are the subcommands the binary accepts. */
$scripts = [
	'mark' => 'markTranslations.php',
	'scaffold' => 'scaffoldTranslations.php',
	'check' => 'checkTranslations.php',
	'stamp' => 'stampTranslations.php'
];

// Read the subcommand before the prelude installs a MediaWiki: a typo should come back at once
// rather than after the boot, as it does under Docker.
$args = array_slice($argv, 1);
$subcommand = array_shift($args) ?? '';
if (!isset($scripts[$subcommand])) {
	fwrite(
		STDERR,
		$subcommand === ''
			? "wikven: 'translate' needs a subcommand: mark, scaffold, check or stamp\n"
			: "wikven: unknown translate subcommand '$subcommand'; expected mark, scaffold, check or stamp\n"
	);
	exit(2);
}

['self' => $self, 'work' => $work, 'ip' => $ip] = require __DIR__ . '/prepare.php';

// --source names the working directory's own tree rather than leaving the scripts on their
// configured default, which is what the Docker entry point does with the mounted one. Everything
// the caller passed follows it, so --all, --gate and a single file name reach the script as
// written.
$command = array_merge(
	[
		$self,
		'php-cli',
		'maintenance/run.php',
		"$ip/extensions/Wikven/maintenance/{$scripts[$subcommand]}",
		'--source',
		"$work/src"
	],
	$args
);
passthru(implode(' ', array_map('escapeshellarg', $command)), $code);

// The helper's own status, not a translation of it. `check --gate` exits non-zero on a source page
// nobody can translate, and that is the answer a caller asked for: a CI step scripting on it has to
// see the failure, and a stale translation, which never gates, has to keep exiting zero.
exit($code);
