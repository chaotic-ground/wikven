<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\FailOnCategories;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\FailOnCategories
 */
class FailOnCategoriesTest extends MediaWikiUnitTestCase {
	/** The ordinary answer: the site named categories and every one of them is empty. */
	public function testACategoryThatIsEmptyIsNoFailure() {
		$this->assertSame([], FailOnCategories::failures(['Notes with no text' => []]));
	}

	/** A site that named none is a site that asked nothing. */
	public function testNothingNamedIsNoFailure() {
		$this->assertSame([], FailOnCategories::failures([]));
	}

	/**
	 * The pages are named because the category is not in the export to be looked at: a reader who
	 * is told only that something is wrong has nowhere to go.
	 */
	public function testACategoryWithPagesInItNamesThem() {
		$this->assertSame(
			[
				'Wikven: Category:Notes with no text holds 2 page(s), and WikvenFailOnCategories says '
					. 'it must hold none: Commands, Commands/ko'
			],
			FailOnCategories::failures(['Notes with no text' => ['Commands', 'Commands/ko']])
		);
	}

	/** Each category is its own complaint, so one does not hide behind another. */
	public function testEveryCategoryThatIsNotEmptySaysSo() {
		$failures = FailOnCategories::failures([
			'Notes with no text' => ['Commands'],
			'Pages with script errors' => ['Lua modules']
		]);

		$this->assertCount(2, $failures);
		$this->assertStringContainsString('Category:Notes with no text', $failures[0]);
		$this->assertStringContainsString('Category:Pages with script errors', $failures[1]);
	}

	/**
	 * One source line reaches every skin's copy of a page and every language of it, so a list can
	 * run long over a single mistake. The first few are what finds it; the count is what sizes it.
	 */
	public function testALongListStopsAndCountsTheRest() {
		$pages = [];
		for ($page = 1; $page <= 12; $page++) {
			$pages[] = "Page $page";
		}

		$failure = FailOnCategories::failures(['Notes with no text' => $pages])[0];

		$this->assertStringContainsString('holds 12 page(s)', $failure);
		$this->assertStringContainsString(
			'Page 1, Page 2, Page 3, Page 4, Page 5, Page 6, Page 7, Page 8 and 4 more',
			$failure
		);
		$this->assertStringNotContainsString('Page 9', $failure);
	}

	/** Exactly as many as it will name is not "and 0 more". */
	public function testAListThatFitsIsNotCounted() {
		$pages = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

		$this->assertStringEndsWith(
			'none: A, B, C, D, E, F, G, H',
			FailOnCategories::failures(['Notes with no text' => $pages])[0]
		);
	}
}
