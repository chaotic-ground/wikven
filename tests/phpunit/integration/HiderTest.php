<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\BuildFor;
use MediaWiki\Extension\Wikven\Hooks\Hider;
use MediaWiki\Skin\Skin;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Hider
 */
class HiderTest extends MediaWikiIntegrationTestCase {
	/** The handler as extension.json builds it, holding the wiki's own configuration. */
	private function hider(): Hider {
		return new Hider($this->getServiceContainer()->getMainConfig());
	}

	/**
	 * The static export has no per-section edit affordance, so section edit links
	 * are turned off.
	 */
	public function testSectionEditLinksDisabled() {
		$text = '';
		$options = ['enableSectionEditLinks' => true];
		$this->hider()->onParserOutputPostCacheTransform(null, $text, $options);
		$this->assertFalse($options['enableSectionEditLinks']);
	}

	/**
	 * The toolbox is server-only tools, and without SifterSearch the search
	 * portlet cannot work either; both are emptied (not unset, so skins that read
	 * the keys keep an array), while other sidebar sections are left alone.
	 */
	public function testSidebarToolboxAndSearchEmptied() {
		$sidebar = ['TOOLBOX' => ['tool'], 'SEARCH' => ['box'], 'navigation' => ['keep']];
		$this->hider()->onSidebarBeforeOutput($this->createMock(Skin::class), $sidebar);
		$this->assertSame([], $sidebar['TOOLBOX']);
		$this->assertSame([], $sidebar['SEARCH'], 'SifterSearch not loaded, so search is dropped');
		$this->assertSame(['keep'], $sidebar['navigation']);
	}

	/**
	 * The navigation always loses the personal tools. The edit, source and history tabs are kept
	 * only when their URLs are configured and the page has a source file behind them; a generated
	 * page drops them so they cannot 404.
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

		$this->assertSame([], $imported['actions'], 'the watch star has no watchlist to reach');

		$generated = $this->navigationFor('Version');
		$this->assertArrayNotHasKey('edit', $generated['views'], 'source-less page drops edit');
		$this->assertArrayNotHasKey('ve-edit', $generated['views']);
		$this->assertArrayNotHasKey('viewsource', $generated['views']);
		$this->assertArrayNotHasKey('history', $generated['views']);
		$this->assertArrayHasKey('view', $generated['views']);
	}

	/**
	 * The discussion tab goes, wherever a skin reads it from: a static export carries no
	 * discussion, and core has no setting that turns talk namespaces off (T35298). The subject tab
	 * beside it stays.
	 */
	public function testNavigationDropsTheDiscussionTab() {
		$links = $this->navigationFor('Real');
		$this->assertSame(['main'], array_keys($links['associated-pages']));
		$this->assertSame(['user'], array_keys($links['namespaces']), 'the legacy copy too');
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
			],
			'actions' => ['watch' => ['x'], 'unwatch' => ['x']],
			// The tab menus, in both the shapes core fills: the modern one, and the legacy copy a
			// skin still asking for `namespaces` is rendered from.
			'associated-pages' => ['main' => ['keep'], 'talk' => ['x']],
			'namespaces' => ['user' => ['keep'], 'user_talk' => ['x']]
		];
		$this->hider()->onSkinTemplateNavigation__Universal($sktemplate, $links);
		return $links;
	}

	/**
	 * A skin preview keeps every affordance this class would take away.
	 *
	 * The whole of Hider is an argument about what a static host can answer for, which a skin
	 * author baking pages to look at their skin is not asking.
	 */
	public function testASkinPreviewIsLeftTheChromeTheSkinDrew() {
		$this->overrideConfigValue('WikvenBuildFor', BuildFor::SKIN_PREVIEW);
		$hider = $this->hider();

		$text = '';
		$options = ['enableSectionEditLinks' => true];
		$hider->onParserOutputPostCacheTransform(null, $text, $options);
		$this->assertTrue($options['enableSectionEditLinks']);

		$sidebar = ['TOOLBOX' => ['tool'], 'SEARCH' => ['box']];
		$hider->onSidebarBeforeOutput($this->createMock(Skin::class), $sidebar);
		$this->assertSame(['TOOLBOX' => ['tool'], 'SEARCH' => ['box']], $sidebar);

		$links = [
			'user-menu' => ['logout'],
			'actions' => ['watch' => []],
			'associated-pages' => ['talk' => []],
			'views' => ['edit' => [], 'history' => []]
		];
		$hider->onSkinTemplateNavigation__Universal($this->createMock(SkinTemplate::class), $links);
		$this->assertSame(['logout'], $links['user-menu'], 'the skin draws its own personal menu');
		$this->assertArrayHasKey('watch', $links['actions']);
		$this->assertArrayHasKey('talk', $links['associated-pages']);
		$this->assertSame(['edit' => [], 'history' => []], $links['views']);
	}
}
