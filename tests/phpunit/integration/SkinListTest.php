<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Build\SkinList;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * The switcher's entries: one per enabled skin, each pointing at that skin's copy of the page.
 *
 * @covers \MediaWiki\Extension\Wikven\Build\SkinList
 */
class SkinListTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues([
			'WikvenSkins' => ['vector-2022', 'citizen', 'minerva'],
			'WikvenMainSkin' => 'vector-2022'
		]);
	}

	/**
	 * A skin whose name and title are the page being rendered, as a hook hands one over. Its
	 * messages are the wiki's, except that "fancy" is a skin declaring a name of its own.
	 */
	private function skin(string $name, ?Title $title): Skin {
		$skin = $this->createMock(Skin::class);
		$skin->method('getSkinName')->willReturn($name);
		$skin->method('getTitle')->willReturn($title);
		$skin->method('msg')->willReturnCallback(static function (string $key, ...$parameters) {
			return $key === 'skinname-fancy'
				? wfMessage('rawmessage', 'Fancy Skin')
				: wfMessage($key, ...$parameters);
		});
		return $skin;
	}

	/**
	 * The main skin is written at the root and every other one in a directory of its own, so a
	 * page rendered in the main skin reaches the others by going down and they reach it by going up.
	 */
	public function testEachSkinPointsAtItsOwnCopyOfThePage() {
		$entries = SkinList::entries($this->skin('vector-2022', Title::makeTitle(NS_HELP, 'Searching')));

		$this->assertSame(
			['t-wikven-skin-vector-2022', 't-wikven-skin-citizen', 't-wikven-skin-minerva'],
			array_column($entries, 'id')
		);
		$this->assertSame(
			[null, './citizen/Help:Searching.html', './minerva/Help:Searching.html'],
			array_column($entries, 'href')
		);
		$this->assertSame([true, false, false], array_column($entries, 'active'));
	}

	/** A page rendered in a skin of its own is one directory down, so every link starts by leaving it. */
	public function testAPageInASkinDirectoryReachesTheOthersFromAboveIt() {
		$entries = SkinList::forPage($this->skin('citizen', null), 'Installation.html', 'citizen');

		$this->assertSame(
			['../Installation.html', null, '../minerva/Installation.html'],
			array_column($entries, 'href')
		);
		$this->assertSame([false, true, false], array_column($entries, 'active'));
	}

	/** Nothing to switch between: one skin is the whole site, and a menu of one is chrome. */
	public function testASiteWithOneSkinHasNoSwitcher() {
		$this->overrideConfigValue('WikvenSkins', ['vector-2022']);

		$this->assertSame([], SkinList::forPage($this->skin('vector-2022', null), 'Index.html', 'vector-2022'));
	}

	/**
	 * A special page and a link with no page behind it cannot be exported, so neither has a copy
	 * in another skin to point at.
	 */
	public function testAPageThatCannotExistHasNoEntries() {
		$this->assertSame([], SkinList::entries($this->skin('vector-2022', null)));
		$this->assertSame(
			[],
			SkinList::entries($this->skin('vector-2022', Title::makeTitle(NS_SPECIAL, 'Search')))
		);
	}

	/**
	 * The label is what a reader picks a skin by: the name the skin declares for itself, and
	 * failing that its directory name spelled as a name rather than as a slug.
	 */
	public function testASkinIsLabelledWithTheNameItDeclares() {
		$this->overrideConfigValue('WikvenSkins', ['vector-2022', 'fancy', 'no-such-skin']);

		$labels = array_column(
			SkinList::forPage($this->skin('vector-2022', null), 'Index.html', 'vector-2022'),
			'text'
		);

		$this->assertSame('Fancy Skin', $labels[1]);
		$this->assertSame('No Such Skin', $labels[2]);
	}
}
