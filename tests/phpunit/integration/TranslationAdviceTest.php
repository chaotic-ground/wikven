<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationAdvice;
use MediaWikiIntegrationTestCase;

/**
 * The advice with the wiki's own messages behind it, which is how checkTranslations.php builds it.
 * The unit test drives the same class with a message function of its own.
 *
 * @covers \MediaWiki\Extension\Wikven\PageTranslation\TranslationAdvice
 */
class TranslationAdviceTest extends MediaWikiIntegrationTestCase {
	/** A key nobody added to i18n/ renders as its own name in braces, and that is what would ship. */
	public function testEveryMessageTheAdviceAsksForIsOneTheExtensionDeclares() {
		$comment = TranslationAdvice::usingMessages()->comment([
			['kind' => 'stale', 'file' => 'docs/Pages/ko.wikitext', 'unit' => '3', 'lang' => 'ko'],
			['kind' => 'parse', 'file' => 'docs/Pages.wikitext', 'detail' => 'pt-shake-position']
		]);

		$this->assertNotNull($comment);
		$this->assertStringNotContainsString('⧼', $comment);
		$this->assertStringContainsString(TranslationAdvice::MARKER, $comment);
		$this->assertStringContainsString('docs/Pages/ko.wikitext', $comment);
	}

	/** The all-clear goes through the same messages, and it is what most runs post. */
	public function testTheAllClearIsRenderedFromMessagesToo() {
		$body = TranslationAdvice::usingMessages()->allClear();

		$this->assertStringNotContainsString('⧼', $body);
		$this->assertStringContainsString(TranslationAdvice::MARKER, $body);
	}
}
