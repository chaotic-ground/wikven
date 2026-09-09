<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use DeferrableUpdate;
use MediaWiki\Extension\Wikven\Hooks\Sparer;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Title\Title;
use MediaWikiUnitTestCase;

/** @covers \MediaWiki\Extension\Wikven\Hooks\Sparer */
class SparerTest extends MediaWikiUnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		if (!defined('NS_TRANSLATIONS')) {
			// Translate's own number for it, and the only one it has ever had.
			define('NS_TRANSLATIONS', 1198);
		}
	}

	/** One update stands in for WikiSEO's; the other for every update that has to survive. */
	private function updates(): array {
		return [
			new class implements DeferrableUpdate {
				public function doUpdate() {}
			},
			$this->createMock(DeferrableUpdate::class)
		];
	}

	private function sparer(DeferrableUpdate $described, ?HookContainer $hookContainer = null): Sparer {
		return new Sparer(
			$hookContainer ?? $this->createMock(HookContainer::class),
			get_class($described)
		);
	}

	private function title(int $namespace): Title {
		$title = $this->createMock(Title::class);
		$title->method('getNamespace')->willReturn($namespace);
		return $title;
	}

	public function testDropsTheDescriptionUpdateForATranslationUnit() {
		$updates = $this->updates();
		$sparer = $this->sparer($updates[0]);
		$survivor = $updates[1];
		$sparer->onRevisionDataUpdates($this->title(NS_TRANSLATIONS), null, $updates);
		$this->assertSame([$survivor], $updates);
	}

	public function testLeavesEveryOtherPageAlone() {
		$updates = $this->updates();
		$sparer = $this->sparer($updates[0]);
		$before = $updates;
		$sparer->onRevisionDataUpdates($this->title(NS_MAIN), null, $updates);
		$this->assertSame($before, $updates);
	}

	public function testRegistersItselfWhereItWillRunLast() {
		$updates = $this->updates();
		$hookContainer = $this->createMock(HookContainer::class);
		$hookContainer->expects($this->once())
			->method('register')
			->with('RevisionDataUpdates', $this->isType('array'));
		$this->sparer($updates[0], $hookContainer)->onSetupAfterCache();
	}
}
