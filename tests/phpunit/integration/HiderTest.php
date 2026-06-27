<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Hooks\Hider;
use MediaWiki\Skin\Skin;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Hider
 */
class HiderTest extends MediaWikiIntegrationTestCase {
	/**
	 * The static export has no per-section edit affordance, so section edit links
	 * are turned off.
	 */
	public function testSectionEditLinksDisabled() {
		$text = '';
		$options = ['enableSectionEditLinks' => true];
		( new Hider() )->onParserOutputPostCacheTransform(null, $text, $options);
		$this->assertFalse($options['enableSectionEditLinks']);
	}

	/**
	 * The toolbox is server-only tools, and without SifterSearch the search
	 * portlet cannot work either; both are emptied (not unset, so skins that read
	 * the keys keep an array), while other sidebar sections are left alone.
	 */
	public function testSidebarToolboxAndSearchEmptied() {
		$sidebar = ['TOOLBOX' => ['tool'], 'SEARCH' => ['box'], 'navigation' => ['keep']];
		( new Hider() )->onSidebarBeforeOutput($this->createMock(Skin::class), $sidebar);
		$this->assertSame([], $sidebar['TOOLBOX']);
		$this->assertSame([], $sidebar['SEARCH'], 'SifterSearch not loaded, so search is dropped');
		$this->assertSame(['keep'], $sidebar['navigation']);
	}

	/**
	 * The navigation always loses the personal tools (no accounts on a static
	 * site). The edit, source and history tabs are kept only when their URLs are
	 * configured and the page has a source file behind them; a generated page
	 * (no source) drops them so they cannot 404.
	 */
	public function testNavigationDropsPersonalToolsAndSourcelessTabs() {
		$dir = $this->getNewTempDirectory();
		file_put_contents("$dir/Real.wikitext", '');
		$this->overrideConfigValue('WikvenSourceDirectory', $dir);
		$this->overrideConfigValue('WikvenEditUrl', 'https://example.org/edit/$1');
		$this->overrideConfigValue('WikvenHistoryUrl', 'https://example.org/history/$1');

		$imported = $this->navigationFor('Real');
		$this->assertSame([], $imported['user-menu'], 'personal tools emptied');
		$this->assertArrayHasKey('edit', $imported['views'], 'imported page keeps its edit tab');
		$this->assertArrayHasKey('history', $imported['views']);
		$this->assertArrayHasKey('view', $imported['views'], 'unrelated tabs are left alone');

		$generated = $this->navigationFor('Version');
		$this->assertArrayNotHasKey('edit', $generated['views'], 'source-less page drops edit');
		$this->assertArrayNotHasKey('ve-edit', $generated['views']);
		$this->assertArrayNotHasKey('viewsource', $generated['views']);
		$this->assertArrayNotHasKey('history', $generated['views']);
		$this->assertArrayHasKey('view', $generated['views']);
	}

	private function navigationFor(string $titleText): array {
		$sktemplate = $this->createMock(SkinTemplate::class);
		$sktemplate->method('getTitle')->willReturn(Title::newFromText($titleText));
		$links = [
			'user-menu' => ['login' => []],
			'user-page' => ['x'],
			'user-interface-preferences' => ['x'],
			'notifications' => ['x'],
			'views' => [
				'view' => ['keep'],
				'edit' => ['x'],
				've-edit' => ['x'],
				'viewsource' => ['x'],
				'history' => ['x']
			]
		];
		( new Hider() )->onSkinTemplateNavigation__Universal($sktemplate, $links);
		return $links;
	}
}
