<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Output\HtmlListInserter;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Output\HtmlListInserter
 */
class HtmlListInserterTest extends MediaWikiUnitTestCase {
	/** Two lists, so a match has to be the one asked for rather than the first one in the page. */
	private function page(): string {
		return (
			'<body><ul id="p-personal"><li>a</li></ul>'
			. '<ul id="p-navigation" class="toggle-list__list"><li>Home</li></ul>'
			. '<ul id="p-interaction"><li>b</li></ul></body>'
		);
	}

	public function testInsertsAfterTheListAsked() {
		$out = HtmlListInserter::after($this->page(), 'p-navigation', '<ul id="mine"></ul>');

		$this->assertStringContainsString(
			'<ul id="p-navigation" class="toggle-list__list"><li>Home</li></ul><ul id="mine"></ul>',
			$out
		);
		$this->assertStringContainsString('<ul id="p-personal"><li>a</li></ul>', $out);
		$this->assertStringContainsString('<ul id="p-interaction"><li>b</li></ul>', $out);
	}

	public function testLeavesThePageAloneWhenTheListIsMissing() {
		$page = $this->page();

		$this->assertSame($page, HtmlListInserter::after($page, 'p-nowhere', '<ul></ul>'));
	}

	/** An unclosed list would otherwise put the markup at the end of the document. */
	public function testLeavesThePageAloneWhenTheListNeverCloses() {
		$page = '<ul id="p-navigation"><li>Home</li>';

		$this->assertSame($page, HtmlListInserter::after($page, 'p-navigation', '<ul></ul>'));
	}

	/**
	 * inside() fills an element rather than following one: Minerva renders its menu as an empty
	 * <ul> that the build writes the entries into.
	 */
	public function testInsideFillsTheElementAsked() {
		$page = '<div id="mw-mf-page-left"><ul id="p-navigation"></ul></div>';

		$out = HtmlListInserter::inside($page, 'p-navigation', '<li>Home</li>', 'ul');

		$this->assertSame(
			'<div id="mw-mf-page-left"><ul id="p-navigation"><li>Home</li></ul></div>',
			$out
		);
	}

	public function testInsideLeavesThePageAloneWhenTheElementIsMissing() {
		$page = '<div id="mw-mf-page-left"><ul id="p-navigation"></ul></div>';

		$this->assertSame($page, HtmlListInserter::inside($page, 'p-nowhere', '<li>Home</li>', 'ul'));
	}

	/** An element that never closes would otherwise take the markup to the end of the document. */
	public function testInsideLeavesThePageAloneWhenTheElementNeverCloses() {
		$page = '<div id="mw-mf-page-left"><ul id="p-navigation">';

		$this->assertSame($page, HtmlListInserter::inside($page, 'p-navigation', '<li>Home</li>', 'ul'));
	}
}
