<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\LazyModules;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\LazyModules
 */
class LazyModulesTest extends MediaWikiUnitTestCase {
	private const ON = ['collapsible' => true, 'sortable' => true];

	public function testAPageWithNeitherNeedsNothing() {
		$this->assertSame([], LazyModules::forPage('<p>Ordinary prose.</p>', self::ON));
	}

	public function testACollapsibleNeedsTheCollapsibleModule() {
		$this->assertSame(
			['jquery.makeCollapsible'],
			LazyModules::forPage('<div class="mw-collapsible mw-collapsed">hidden</div>', self::ON)
		);
	}

	public function testASortableTableNeedsTheSorter() {
		$this->assertSame(
			['jquery.tablesorter'],
			LazyModules::forPage('<table class="wikitable sortable"><tr><td>a</td></tr></table>', self::ON)
		);
	}

	public function testAPageWithBothNeedsBoth() {
		$html = '<table class="sortable"></table><div class="mw-collapsible"></div>';
		$this->assertSame(['jquery.makeCollapsible', 'jquery.tablesorter'], LazyModules::forPage($html, self::ON));
	}

	/**
	 * The flag is what a skin turns off through SkinPageReadyConfig. With it off core never looks,
	 * so neither does this: bundling the module would ship a feature the site asked not to have.
	 */
	public function testAFlagTurnedOffIsObeyed() {
		$html = '<table class="sortable"></table><div class="mw-collapsible"></div>';
		$this->assertSame(
			['jquery.makeCollapsible'],
			LazyModules::forPage($html, ['collapsible' => true, 'sortable' => false])
		);
		$this->assertSame([], LazyModules::forPage($html, []), 'an absent flag is an off flag');
	}

	/**
	 * Core's own toggle and content wrappers are named after the class that makes them, so a
	 * substring match would find a collapsible in a page that has none of its own.
	 */
	public function testAWrapperClassIsNotTheClassItself() {
		$this->assertFalse(LazyModules::hasClass('<div class="mw-collapsible-content">x</div>', 'mw-collapsible'));
		$this->assertTrue(LazyModules::hasClass('<div class="a mw-collapsible b">x</div>', 'mw-collapsible'));
	}

	public function testTheTagIsPartOfTheSelector() {
		$onADiv = '<div class="sortable">not a table</div>';
		$this->assertSame([], LazyModules::forPage($onADiv, self::ON), 'core looks for table.sortable');
		$this->assertTrue(LazyModules::hasClass($onADiv, 'sortable'), 'and for the class on anything');
	}

	/**
	 * @dataProvider provideMarkup
	 */
	public function testItReadsTheClassOutOfRealMarkup(string $html, bool $expected) {
		$this->assertSame($expected, LazyModules::hasClass($html, 'sortable', 'table'));
	}

	public static function provideMarkup(): array {
		return [
			'class first' => ['<table class="sortable">', true],
			'class after other attributes' => ['<table id="t" class="wikitable sortable">', true],
			'single quotes' => ["<table class='sortable jquery-tablesorter'>", true],
			'uppercase tag' => ['<TABLE CLASS="sortable">', true],
			// The word appears, but not as a class of a table, which is what core searches for.
			'the word in prose' => ['<p>A sortable table</p><table class="wikitable">', false],
			'a longer class that contains it' => ['<table class="unsortable">', false],
			'on the row rather than the table' => ['<table><tr class="sortable">', false]
		];
	}
}
