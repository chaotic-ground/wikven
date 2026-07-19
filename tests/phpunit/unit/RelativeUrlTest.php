<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\RelativeUrl;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\RelativeUrl
 */
class RelativeUrlTest extends MediaWikiUnitTestCase {
	public function testDepthZeroLeavesTheHtmlUntouched() {
		$html = 'href="./index.html" src="././modules-static.js"';
		$this->assertSame($html, RelativeUrl::reparent($html, 0));
	}

	public function testRootRelativeLinksGainOneLevelPerDepth() {
		$this->assertSame('href="../index.html"', RelativeUrl::reparent('href="./index.html"', 1));
		$this->assertSame('href="../../index.html"', RelativeUrl::reparent('href="./index.html"', 2));
	}

	public function testParentRelativeCanonicalGainsAFurtherLevel() {
		$this->assertSame('href="../../Intro.html"', RelativeUrl::reparent('href="../Intro.html"', 1));
	}

	public function testScriptAndStyleReferencesAreRebased() {
		$this->assertSame('src=".././modules-static.js"', RelativeUrl::reparent('src="././modules-static.js"', 1));
		$this->assertSame(
			'href=".././skins.vector.styles.css"',
			RelativeUrl::reparent('href="././skins.vector.styles.css"', 1)
		);
	}

	public function testEachSrcsetCandidateIsRebased() {
		$this->assertSame(
			'srcset="../img-a.png 1.5x, ../img-b.png 2x"',
			RelativeUrl::reparent('srcset="./img-a.png 1.5x, ./img-b.png 2x"', 1)
		);
	}

	public function testCssUrlIsRebased() {
		$this->assertSame('url(../logo.png)', RelativeUrl::reparent('url(./logo.png)', 1));
	}

	public function testAbsoluteProtocolRelativeAnchorAndDataUrlsAreLeftAlone() {
		$html =
			'href="https://example.org/x" href="//cdn/x" href="/images/logo.png" '
			. 'href="#top" src="data:image/svg+xml,%3Csvg/%3E"';
		$this->assertSame($html, RelativeUrl::reparent($html, 2));
	}

	public function testNonAttributeDotSlashInScriptIsNotRewritten() {
		$html = '<script>var x = {"path": "./y"};</script>';
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
