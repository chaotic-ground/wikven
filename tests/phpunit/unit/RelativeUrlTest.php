<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\RelativeUrl;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\RelativeUrl
 */
class RelativeUrlTest extends MediaWikiUnitTestCase {
	public function testDepthZeroLeavesTheHtmlUntouched() {
		$html = '<a href="./index.html"><script src="././modules-static.js"></script></a>';
		$this->assertSame($html, RelativeUrl::reparent($html, 0));
	}

	public function testRootRelativeLinksGainOneLevelPerDepth() {
		$this->assertSame(
			'<a href="../index.html">',
			RelativeUrl::reparent('<a href="./index.html">', 1)
		);
		$this->assertSame(
			'<a href="../../index.html">',
			RelativeUrl::reparent('<a href="./index.html">', 2)
		);
	}

	public function testParentRelativeCanonicalGainsAFurtherLevel() {
		$this->assertSame(
			'<link href="../../Intro.html">',
			RelativeUrl::reparent('<link href="../Intro.html">', 1)
		);
	}

	public function testScriptAndStyleReferencesAreRebased() {
		$this->assertSame(
			'<script src=".././modules-static.js">',
			RelativeUrl::reparent('<script src="././modules-static.js">', 1)
		);
		$this->assertSame(
			'<link href=".././skins.vector.styles.css">',
			RelativeUrl::reparent('<link href="././skins.vector.styles.css">', 1)
		);
	}

	public function testEachSrcsetCandidateIsRebased() {
		$this->assertSame(
			'<img srcset="../img-a.png 1.5x, ../img-b.png 2x">',
			RelativeUrl::reparent('<img srcset="./img-a.png 1.5x, ./img-b.png 2x">', 1)
		);
	}

	public function testCssUrlIsRebasedInAStyleBlock() {
		$this->assertSame(
			'<style>.a{background:url(../logo.png)}</style>',
			RelativeUrl::reparent('<style>.a{background:url(./logo.png)}</style>', 1)
		);
	}

	public function testCssUrlIsRebasedInAStyleAttribute() {
		$this->assertSame(
			'<div style="background:url(../logo.png)">',
			RelativeUrl::reparent('<div style="background:url(./logo.png)">', 1)
		);
	}

	public function testAbsoluteProtocolRelativeAnchorAndDataUrlsAreLeftAlone() {
		$html =
			'<a href="https://example.org/x"><a href="//cdn/x"><a href="/images/logo.png">'
			. '<a href="#top"><img src="data:image/svg+xml,%3Csvg/%3E">';
		$this->assertSame($html, RelativeUrl::reparent($html, 2));
	}

	public function testNonAttributeDotSlashInScriptIsNotRewritten() {
		$html = '<script>var x = {"path": "./y"};</script>';
		$this->assertSame($html, RelativeUrl::reparent($html, 1));
	}

	public function testAnEscapedAttributeShownAsACodeExampleIsNotRewritten() {
		// htmlspecialchars(..., ENT_NOQUOTES), which MediaWiki uses for preformatted text, escapes the
		// angle brackets but not the quotes: the example reads like a real href="./intro" but sits in
		// no real "<...>" tag span, so it must be left exactly as written.
		$html = '<pre>&lt;a href="./intro"&gt;Intro&lt;/a&gt;</pre>';
		$this->assertSame($html, RelativeUrl::reparent($html, 1));
	}

	public function testCssUrlShownAsACodeExampleIsNotRewritten() {
		$html = '<pre>.a{background:url(./bg.png)}</pre>';
		$this->assertSame($html, RelativeUrl::reparent($html, 1));
	}

	public function testDataHrefAndXlinkHrefAreNotRewritten() {
		// \b before "href" also matches inside "data-href" and "xlink:href", neither of which is the
		// href attribute; only a real, whitespace-preceded href/src is a rewrite target.
		$html = '<div data-href="./x"><svg><use xlink:href="./y"></use></svg></div>';
		$this->assertSame($html, RelativeUrl::reparent($html, 1));
	}

	/**
	 * The printfooter's "Retrieved from" link reaches here as a bare path from the output root:
	 * core expands the page's own URL, and dot-segment removal takes the "./" off it.
	 */
	public function testThePrintFooterLinkIsRebased() {
		$this->assertSame(
			'<div class="printfooter" data-nosnippet="">Retrieved from '
			. '"<a dir="ltr" href="../Manual/Config.html">Manual/Config.html</a>"</div>',
			RelativeUrl::reparent(
				'<div class="printfooter" data-nosnippet="">Retrieved from '
				. '"<a dir="ltr" href="Manual/Config.html">Manual/Config.html</a>"</div>',
				1
			)
		);
	}

	public function testThePrintFooterLinkGainsOneLevelPerDepth() {
		$this->assertSame(
			'<div class="printfooter"><a href="../../A/B/C.html">A/B/C.html</a></div>',
			RelativeUrl::reparent('<div class="printfooter"><a href="A/B/C.html">A/B/C.html</a></div>', 2)
		);
	}

	/** A namespaced title reads like a URL scheme; only "://" marks one that really is. */
	public function testThePrintFooterLinkToANamespacedPageIsRebased() {
		$this->assertSame(
			'<div class="printfooter"><a href="../File:Oven.jpg.html">File:Oven.jpg.html</a></div>',
			RelativeUrl::reparent(
				'<div class="printfooter"><a href="File:Oven.jpg.html">File:Oven.jpg.html</a></div>',
				1
			)
		);
	}

	public function testAPrintFooterLinkThatKeptItsMarkerIsRebasedOnce() {
		$this->assertSame(
			'<div class="printfooter"><a href="../Intro.html">Intro.html</a></div>',
			RelativeUrl::reparent('<div class="printfooter"><a href="./Intro.html">Intro.html</a></div>', 1)
		);
	}

	public function testAnAbsolutePrintFooterLinkIsLeftAlone() {
		$html = '<div class="printfooter"><a href="https://example.org/Intro">https://example.org/Intro</a></div>';
		$this->assertSame($html, RelativeUrl::reparent($html, 1));
	}

	/** Outside the printfooter a bare relative href is indistinguishable from a path in prose. */
	public function testABareRelativeHrefOutsideThePrintFooterIsLeftAlone() {
		$html = '<a href="Manual/Config.html">Config</a>';
		$this->assertSame($html, RelativeUrl::reparent($html, 1));
	}

	public function testAPrintFooterShownAsACodeExampleIsNotRewritten() {
		$html = '<pre>&lt;div class="printfooter"&gt;&lt;a href="Intro.html"&gt;&lt;/a&gt;&lt;/div&gt;</pre>';
		$this->assertSame($html, RelativeUrl::reparent($html, 1));
	}

	public function testMyLanguageResolvesToTheTranslationWhenItExists() {
		$this->assertSame(
			'href="./B/ko.html"',
			RelativeUrl::resolveMyLanguage('href="./Special:MyLanguage/B.html"', 'ko', static function () {
				return true;
			})
		);
	}

	public function testMyLanguageFallsBackToTheSourceTargetWithoutATranslation() {
		$this->assertSame(
			'href="./B.html"',
			RelativeUrl::resolveMyLanguage('href="./Special:MyLanguage/B.html"', 'ko', static function () {
				return false;
			})
		);
	}

	public function testMyLanguageOnASourcePageUsesTheSourceTarget() {
		$this->assertSame(
			'href="./B.html"',
			RelativeUrl::resolveMyLanguage('href="./Special:MyLanguage/B.html"', null, static function () {
				return true;
			})
		);
	}

	public function testMyLanguageKeepsTheLinksExistingRelativePrefix() {
		$this->assertSame(
			'href="../B/ko.html"',
			RelativeUrl::resolveMyLanguage('href="../Special:MyLanguage/B.html"', 'ko', static function () {
				return true;
			})
		);
	}

	public function testMyLanguagePreservesASectionFragment() {
		$this->assertSame(
			'href="../Installation/ko.html#Binary"',
			RelativeUrl::resolveMyLanguage(
				'href="../Special:MyLanguage/Installation.html#Binary"',
				'ko',
				static function () {
					return true;
				}
			)
		);
	}

	public function testResolveMyLanguageLeavesOrdinaryLinksAlone() {
		$html = 'href="./B.html" href="https://example.org/Special:MyLanguage/Y"';
		$this->assertSame($html, RelativeUrl::resolveMyLanguage($html, 'ko', static function () {
			return true;
		}));
	}
}
