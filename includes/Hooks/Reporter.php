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
	 * MWExceptionHandler's guard is a shutdown function exiting 255, which the binary reads the
	 * status too early to see, so a step that died handed back 0.
	 */
	public function onSetupAfterCache(): void {
		self::install(MW_ENTRY_POINT, defined('MW_PHPUNIT_TEST'), 'set_exception_handler');
	}

	/**
	 * Put the handler in front of core's, where this is a run whose status is worth correcting.
	 *
	 * $set is the one thing here no test reaches: calling the installed handler means a real exit.
	 *
	 * @param string $entryPoint MW_ENTRY_POINT, naming what kind of run this is.
	 * @param bool $underTest Whether PHPUnit is running, which owns the handler while it is.
	 * @param callable $set Given the handler to install, as set_exception_handler is.
	 */
	public static function install(string $entryPoint, bool $underTest, callable $set): void {
		// A web request has a response to write and a status of its own, and a test has PHPUnit's
		// handler, which core is careful not to replace either.
		if ($entryPoint !== 'cli' || $underTest) {
			return;
		}
		// Named rather than read back out of set_exception_handler(): this stands in front of the
		// handler installHandler() installed a few lines earlier in Setup.php, and that is the one
		// it installs.
		$set(self::handler(
			MWExceptionHandler::handleUncaughtException(...),
			static function (int $status): never {
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
