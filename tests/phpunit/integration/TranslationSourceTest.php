<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\PageTranslation\TranslationSource
 */
class TranslationSourceTest extends MediaWikiIntegrationTestCase {
	/**
	 * isTranslatable() uses the MediaWiki parser's tag extraction, so this is an integration test.
	 *
	 * @dataProvider provideIsTranslatable
	 */
	public function testIsTranslatable(string $text, bool $expected) {
		$this->assertSame($expected, TranslationSource::isTranslatable($text));
	}

	public static function provideIsTranslatable(): array {
		return [
			'a real translate block' => ["<languages/>\n<translate>\n<!--T:1-->\nHi.\n</translate>", true],
			'plain text' => ['Just prose, no markup.', false],
			// The page documenting page translation shows <translate> as an example; it is not a source.
			'translate inside syntaxhighlight' => [
				"x\n<syntaxhighlight>\n<translate>\n<!--T:1-->\nBody\n</translate>\n</syntaxhighlight>",
				false
			],
			'translate inside nowiki' => ['Wrap it in <code><nowiki><translate></nowiki></code>.', false],
			'translate inside pre' => ['<pre><translate>example</translate></pre>', false],
			'a real block alongside an example' => [
				"<translate>Real</translate>\n<syntaxhighlight><translate>x</translate></syntaxhighlight>",
				true
			]
		];
	}
}
