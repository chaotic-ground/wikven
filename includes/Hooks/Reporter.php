<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Exception\MWExceptionHandler;
use Throwable;

class Reporter implements \MediaWiki\Hook\SetupAfterCacheHook {
	/** What core ends a run with when a throwable reaches its handler (T177414). */
	private const FAILED = 255;

	/**
	 * @inheritDoc
	 *
	 * Make a run that died of an Error say so in its exit status.
	 *
	 * A PHP Error -- a missing class, a TypeError, an argument count -- is a Throwable and not an
	 * Exception, so it goes past MaintenanceRunner's catch and reaches MWExceptionHandler, whose
	 * guard against a script claiming success is a register_shutdown_function() that exits 255.
	 * The standalone binary discards a status set from a shutdown function, and only that: three
	 * lines of PHP measure it, exit(7) at the top of a file giving 7 under the binary and the same
	 * exit(7) from a shutdown function giving 0.
	 *
	 * So this closes the gap where it opens, with the same 255 said from the exception handler
	 * itself. It costs nothing under a real PHP binary, where both routes already answer 255.
	 *
	 * It matters because the binary re-invokes itself for every step of a build through that path
	 * -- embedded FrankenPHP leaves PHP_BINARY empty, so a step runs as `<self> php-cli` -- and a
	 * step that died of an Error hands back 0. Measured on a bake with an Error thrown right after
	 * runJobs: the binary printed the backtrace, wrote no page at all, and finished with
	 * "wikven: done" and exit 0. SkinPass covers a skin pass that dies halfway through rendering;
	 * every other step of a build is this.
	 *
	 * Installed here rather than in WikvenSettings.php because installHandler() runs in Setup.php
	 * after LocalSettings.php and would replace anything put there. This hook is the first thing
	 * wikven runs after it; an Error before this point is core's to report, and under a real PHP
	 * binary core does.
	 */
	public function onSetupAfterCache(): void {
		// A web request has a response to write and a status of its own, and a test has PHPUnit's
		// handler, which core is careful not to replace either.
		if (MW_ENTRY_POINT !== 'cli' || defined('MW_PHPUNIT_TEST')) {
			return;
		}
		// Named rather than read back out of set_exception_handler(): this stands in front of the
		// handler installHandler() installed a few lines earlier in Setup.php, and that is the one
		// it installs.
		set_exception_handler(self::handler(
			MWExceptionHandler::handleUncaughtException(...),
			static function (int $status): void {
				exit($status);
			}
		));
	}

	/**
	 * An exception handler that reports the throwable and then ends the process.
	 *
	 * @param callable $report Handed the throwable, to log and print as core would.
	 * @param callable $stop Handed the status to end the run with.
	 * @return callable Takes the Throwable, as an exception handler is given it.
	 */
	public static function handler(callable $report, callable $stop): callable {
		return static function (Throwable $error) use ($report, $stop): void {
			// Reported first: that is what logs the error and prints the backtrace, and whoever
			// reads the run needs those more than they need the status.
			$report($error);
			$stop(self::FAILED);
		};
	}
}
