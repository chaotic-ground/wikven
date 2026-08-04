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

	/**
	 * hasFixedDisplayTitle() shares isTranslatable()'s tag extraction, so it is an integration test too.
	 *
	 * @dataProvider provideHasFixedDisplayTitle
	 */
	public function testHasFixedDisplayTitle(string $text, bool $expected) {
		$this->assertSame($expected, TranslationSource::hasFixedDisplayTitle($text));
	}

	public static function provideHasFixedDisplayTitle(): array {
		return [
			'no magic word' => ["<translate>\n<!--T:1-->\nHi.\n</translate>", false],
			'a real magic word' => ["<translate>Hi.</translate>\n{{DISPLAYTITLE:Wikven}}", true],
			'spaced out' => ['{{ DISPLAYTITLE : Wikven }}', true],
			// The page documenting page translation shows the magic word; that is prose, not a title.
			'shown inside nowiki' => ['Set it with <nowiki>{{DISPLAYTITLE:Wikven}}</nowiki>.', false],
			'shown inside syntaxhighlight' => [
				"<syntaxhighlight lang=\"wikitext\">\n{{DISPLAYTITLE:Wikven}}\n</syntaxhighlight>",
				false
			]
		];
	}

	/** @dataProvider provideTranslatableTitle */
	public function testTranslatableTitle(string $baseFile, string $text, ?string $expected) {
		$this->assertSame($expected, TranslationSource::translatableTitle($baseFile, '/src', $text));
	}

	public static function provideTranslatableTitle(): array {
		$page = "<translate>\n<!--T:1-->\nHi.\n</translate>";
		return [
			'the title the file imports as' => ['/src/Getting Started.wikitext', $page, 'Getting Started'],
			'a one-word title' => ['/src/Pages.wikitext', $page, 'Pages'],
			'a namespaced page keeps its prefix' => [
				'/src/MediaWiki:Sidebar-docs.wikitext',
				$page,
				'MediaWiki:Sidebar-docs'
			],
			// A page that fixes its own display title fixes it in every language, so nothing is asked for.
			'a page with its own display title' => [
				'/src/index.wikitext',
				$page . "\n{{DISPLAYTITLE:Wikven}}",
				null
			],
			// Nothing to be relative to, so no title can be derived.
			'a file outside the source directory' => ['/elsewhere/Pages.wikitext', $page, null]
		];
	}
}
