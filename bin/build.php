<?php
/** Standalone-binary entry point: builds the whole static site in one `./wikven build` run. */

['run' => $run, 'work' => $work, 'ip' => $ip] = require __DIR__ . '/prepare.php';

@mkdir("$work/dist", 0777, true);

// Apply the extension schema updates the installer does not create, then bake.
$run(['update', '--quick']);
$run(["$ip/extensions/Wikven/maintenance/build.php"]);

// Drop any per-page history left in the output, as the Docker run does. The file cache no longer
// emits one (SkippedHistoryAction), so this is a guard over a workdir that was built before that or
// by other means, and normally finds nothing. Done without a shell: this
// is the entry point that has to work on a host with no distro toolchain -- the prelude hunts for
// its own executable and a CA bundle for the same reason -- and each removal's result is checked so a
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
