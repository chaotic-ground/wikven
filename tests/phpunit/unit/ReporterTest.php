<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use Error;
use MediaWiki\Extension\Wikven\Hooks\Reporter;
use MediaWikiUnitTestCase;
use Throwable;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Reporter
 */
class ReporterTest extends MediaWikiUnitTestCase {
	public function testTheRunEndsWithTheStatusCoreWouldHaveUsed() {
		$reported = null;
		$stopped = [];
		$handler = Reporter::handler(
			static function (Throwable $error) use (&$reported): void {
				$reported = $error;
			},
			static function (int $status) use (&$stopped): void {
				$stopped[] = $status;
			}
		);
		$error = new Error('a class the export needed was missing');

		$handler($error);

		$this->assertSame([255], $stopped);
		$this->assertSame($error, $reported);
	}

	public function testTheErrorIsReportedBeforeTheRunEnds() {
		$order = [];
		$handler = Reporter::handler(
			static function (Throwable $unusedError) use (&$order): void {
				$order[] = 'reported';
			},
			static function (int $unusedStatus) use (&$order): void {
				$order[] = 'stopped';
			}
		);

		$handler(new Error('boom'));

		$this->assertSame(['reported', 'stopped'], $order);
	}

	public function testACommandLineRunGetsTheHandler() {
		$installed = [];

		Reporter::install('cli', false, static function (callable $handler) use (&$installed): void {
			$installed[] = $handler;
		});

		$this->assertCount(1, $installed);
	}

	public function testAWebRequestKeepsItsOwn() {
		$installed = [];

		Reporter::install('index', false, static function (callable $unused) use (&$installed): void {
			$installed[] = $unused;
		});

		$this->assertSame([], $installed);
	}

	public function testPhpunitKeepsItsOwn() {
		$installed = [];

		Reporter::install('cli', true, static function (callable $unused) use (&$installed): void {
			$installed[] = $unused;
		});

		$this->assertSame([], $installed);
	}
}
