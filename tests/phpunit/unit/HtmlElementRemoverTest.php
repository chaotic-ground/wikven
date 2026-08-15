<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\HtmlElementRemover;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\HtmlElementRemover
 */
class HtmlElementRemoverTest extends MediaWikiUnitTestCase {
	private function byId(): callable {
		return static function ($name, array $attrs) {
			return ( $attrs['id'] ?? null ) === 'vector-appearance';
		};
	}

	public function testRemovesAMatchedElementAndItsSubtree() {
		$html = '<body><div id="vector-appearance"><div>nested</div>text</div><p>keep</p></body>';
		$this->assertSame(
			'<body><p>keep</p></body>',
			HtmlElementRemover::remove($html, $this->byId())
		);
	}

	public function testMatchesRegardlessOfTagName() {
		// Vector may render the targeted chrome as <nav>, <aside>, or <form> instead of <div>; a
		// matcher hardcoded to "<div" would silently leave it in place.
		$html = '<body><nav id="vector-appearance"><span>x</span></nav><p>keep</p></body>';
		$this->assertSame(
			'<body><p>keep</p></body>',
			HtmlElementRemover::remove($html, $this->byId())
		);
	}

	public function testAGreaterThanInsideAnAttributeValueIsNotTheTagEnd() {
		$html = '<body><div id="vector-appearance" data-note="a > b">x</div><p>keep</p></body>';
		$this->assertSame(
			'<body><p>keep</p></body>',
			HtmlElementRemover::remove($html, $this->byId())
		);
	}

	public function testAVoidOrSelfClosedElementInsideTheSubtreeDoesNotDesyncDepth() {
		$html =
			'<body><div id="vector-appearance"><input type="search"><svg><path d="M0 0"/></svg></div>'
			. '<p>keep</p></body>';
		$this->assertSame(
			'<body><p>keep</p></body>',
			HtmlElementRemover::remove($html, $this->byId())
		);
	}

	public function testClassMatchingIsByTokenNotSubstring() {
		$matches = static function ($name, array $attrs) {
			$classes = isset($attrs['class']) ? preg_split('/\s+/', trim($attrs['class'])) : [];
			return in_array('vector-search-box-vue', $classes, true);
		};

		$html = '<body><div class="vector-search-box-vue extra"><span>x</span></div><p>keep</p></body>';
		$this->assertSame('<body><p>keep</p></body>', HtmlElementRemover::remove($html, $matches));

		// A class that merely contains the marker as a substring must not match.
		$lookalike = '<body><div class="my-vector-search-box-vue-extra">x</div><p>keep</p></body>';
		$this->assertSame($lookalike, HtmlElementRemover::remove($lookalike, $matches));
	}

	public function testTwoSiblingMatchesAreBothRemoved() {
		$html = '<body><div id="vector-appearance">a</div><p>mid</p><div id="vector-appearance">b</div></body>';
		$this->assertSame(
			'<body><p>mid</p></body>',
			HtmlElementRemover::remove($html, $this->byId())
		);
	}

	public function testNoMatchLeavesTheHtmlUntouched() {
		$html = '<body><div class="other">x</div></body>';
		$this->assertSame($html, HtmlElementRemover::remove($html, $this->byId()));
	}
}
