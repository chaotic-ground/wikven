<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\PageTranslation\TranslationSource
 */
class TranslationSourceTest extends MediaWikiUnitTestCase {
	public function testATranslateBlockMarksThePageTranslatable() {
		$this->assertTrue(
			TranslationSource::isTranslatable("<languages/>\n<translate>\n<!--T:1-->\nHi.\n</translate>")
		);
	}

	public function testPlainTextIsNotTranslatable() {
		$this->assertFalse(TranslationSource::isTranslatable("Just prose, no markup."));
	}

	public function testTranslateInsideSyntaxHighlightIsIgnored() {
		// The page documenting page translation shows <translate> as an example; it is not a source.
		$text = "How to mark a page:\n"
			. "<syntaxhighlight lang=\"wikitext\">\n<languages/>\n<translate>\n<!--T:1-->\nBody.\n</translate>\n</syntaxhighlight>";
		$this->assertFalse(TranslationSource::isTranslatable($text));
	}

	public function testTranslateInsideNowikiIsIgnored() {
		$this->assertFalse(
			TranslationSource::isTranslatable("Wrap content in <code><nowiki><translate></nowiki></code>.")
		);
	}

	public function testTranslateInsidePreIsIgnored() {
		$this->assertFalse(TranslationSource::isTranslatable("<pre><translate>example</translate></pre>"));
	}

	public function testARealTranslateBlockCountsEvenAlongsideAnExample() {
		$text = "<translate>\n<!--T:1-->\nReal.\n</translate>\n"
			. "<syntaxhighlight lang=\"wikitext\"><translate>example</translate></syntaxhighlight>";
		$this->assertTrue(TranslationSource::isTranslatable($text));
	}
}
