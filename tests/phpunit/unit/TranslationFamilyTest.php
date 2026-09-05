<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationFamily;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\PageTranslation\TranslationFamily
 */
class TranslationFamilyTest extends MediaWikiUnitTestCase {
	public function testEveryLanguageAnswersWithItsOwnPage() {
		$this->assertSame(
			['en' => 'Development', 'ko' => 'Development/ko', 'km' => 'Development/km'],
			TranslationFamily::byLanguage('Development', 'en', ['Development/ko', 'Development/km'])
		);
	}

	/**
	 * The rule this class exists for. Translate makes a translation page for the source's own
	 * language, and it is the source page's article again at a second address; hreflang cannot say
	 * a language is at two of them, so the source page keeps the language.
	 */
	public function testTheSourceLanguagesOwnTranslationPageDoesNotTakeTheLanguage() {
		$this->assertSame(
			['en' => 'Development', 'ko' => 'Development/ko'],
			TranslationFamily::byLanguage('Development', 'en', ['Development/en', 'Development/ko'])
		);
	}

	/** A page with no translations is its own language and nothing more. */
	public function testASourcePageAloneIsOneLanguage() {
		$this->assertSame(['ko' => 'Bread'], TranslationFamily::byLanguage('Bread', 'ko', []));
	}

	/** The source page is not written in English on every wiki. */
	public function testTheSourceLanguageIsTheOneThePageWasWrittenIn() {
		$this->assertSame(
			['ko' => 'Bread', 'en' => 'Bread/en'],
			TranslationFamily::byLanguage('Bread', 'ko', ['Bread/en', 'Bread/ko'])
		);
	}

	public function testATranslationPageOwnsItsOwnContent() {
		$this->assertSame('Development/ko', TranslationFamily::owner('Development/ko', 'Development', 'en'));
	}

	public function testTheSourceLanguagesTranslationPageSaysTheSourcePageOwnsIt() {
		$this->assertSame('Development', TranslationFamily::owner('Development/en', 'Development', 'en'));
	}

	/** What the source page carries past its own name is nothing, which is not a language. */
	public function testTheSourcePageOwnsItself() {
		$this->assertSame('Development', TranslationFamily::owner('Development', 'Development', 'en'));
	}

	/** A subpage is a page of its own, and a name is all there is to go on. */
	public function testALanguageIsWhatTheNameCarriesPastTheSourcePages() {
		$this->assertSame('ko', TranslationFamily::languageOf('Development/ko', 'Development'));
		$this->assertSame('', TranslationFamily::languageOf('Development', 'Development'));
	}
}
