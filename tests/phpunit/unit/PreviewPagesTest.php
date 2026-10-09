<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Build\BuildFor;
use MediaWiki\Extension\Wikven\Build\PreviewPages;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Build\PreviewPages
 */
class PreviewPagesTest extends MediaWikiUnitTestCase {
	protected function tearDown(): void {
		unset($GLOBALS['wgWikvenBuildFor'], $GLOBALS['wgWikvenPreviewExtraPages']);
		parent::tearDown();
	}

	/** A site renders none of them: the cost #408 took out stays out. */
	public function testASiteRendersNoneOfThem() {
		$GLOBALS['wgWikvenPreviewExtraPages'] = ['Special:Version'];
		$this->assertSame([], PreviewPages::entries());
	}

	public function testASkinPreviewRendersTheNamesItLists() {
		$GLOBALS['wgWikvenBuildFor'] = BuildFor::SKIN_PREVIEW;
		$GLOBALS['wgWikvenPreviewExtraPages'] = ['Special:Version', 3, 'index?action=history'];
		$this->assertSame(['Special:Version', 'index?action=history'], PreviewPages::entries());
	}

	/** @dataProvider provideEntries */
	public function testAnEntryIsATitleAndAQuery(string $entry, string $title, array $params) {
		$this->assertSame([$title, $params], PreviewPages::parse($entry));
	}

	public static function provideEntries(): array {
		return [
			'a special page' => ['Special:RecentChanges', 'Special:RecentChanges', []],
			'a history' => ['index?action=history', 'index', ['action' => 'history']],
			'a diff' => ['index?diff=prev&oldid=1', 'index', ['diff' => 'prev', 'oldid' => '1']],
			// A title can hold a question mark, and only name=value pairs read as a query.
			'a question in a title' => ['What?', 'What?', []],
			'a question in a title, then a query' => ['What??action=history', 'What?', ['action' => 'history']]
		];
	}

	/** A link and an entry agree however the link ordered its query, and whatever its title says. */
	public function testAQueryIsSpelledOneWay() {
		$this->assertSame(
			PreviewPages::query(['diff' => 'prev', 'oldid' => '1']),
			PreviewPages::query(['oldid' => '1', 'title' => 'index', 'diff' => 'prev'])
		);
		$this->assertSame('', PreviewPages::query(['title' => 'index']));
		$this->assertNull(PreviewPages::query(['ids' => ['1', '2']]));
	}

	public function testAQueryFollowsTheTitleAfterAHash() {
		$this->assertSame('index#action=history', PreviewPages::dbkey('index', 'action=history'));
		$this->assertSame('Version', PreviewPages::dbkey('Version', ''));
	}
}
