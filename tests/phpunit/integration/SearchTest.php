<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Search;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Search
 */
class SearchTest extends MediaWikiIntegrationTestCase {
	/**
	 * Both answers are about SifterSearch, which is not installed in this suite, so what is
	 * pinned here is the shape of that: with no search extension there is neither a working box
	 * nor a results page to submit to, whatever the settings around them say.
	 */
	public function testWithoutSifterSearchNothingIsActive() {
		$this->setMwGlobals([
			'wgSifterSearchOutputDir' => '/tmp/pagefind',
			'wgSifterSearchResultsPage' => 'Search'
		]);

		$this->assertFalse(Search::isActive());
		$this->assertFalse(Search::hasResultsPage(), 'a results page needs a search to reach it');
	}

	/**
	 * The page itself is answered whatever the search around it is doing: it is where a search link
	 * lands, and a site that named one named it for that.
	 *
	 * @dataProvider provideResultsPages
	 */
	public function testResultsPageIsTheConfiguredTitle(?string $configured, ?string $expected) {
		$this->setMwGlobals('wgSifterSearchResultsPage', $configured);
		$page = Search::resultsPage();
		$this->assertSame($expected, $page?->getPrefixedText());
	}

	public static function provideResultsPages() {
		return [
			'a page' => ['Search', 'Search'],
			'a page outside the main namespace' => ['Help:Search', 'Help:Search'],
			'none named' => ['', null],
			// SifterSearch reads the setting as a title too, and wires up nothing when it is not one.
			'not a title' => ['<', null],
			'no value, as on a wiki without SifterSearch' => [null, null]
		];
	}

	/**
	 * The bundle's location is what decides which copy of the site a search answers with, so this
	 * is the whole of #399's fix: the copy's directory goes ahead of the bundle's own, and every
	 * URL SifterSearch derives from the path follows it in.
	 *
	 * @dataProvider provideCopyBundlePaths
	 */
	public function testCopyBundlePathPutsTheBundleBesideTheCopy(
		string $bundlePath,
		string $directory,
		?string $expected
	) {
		$this->assertSame($expected, Search::copyBundlePath($bundlePath, $directory));
	}

	public static function provideCopyBundlePaths() {
		return [
			'a site in a subdirectory' => ['/wikven/pagefind/', 'citizen', '/wikven/citizen/pagefind/'],
			'a site at the host root' => ['/pagefind/', 'minerva', '/minerva/pagefind/'],
			// SifterSearch's setting is a directory either way; a site may write it without the
			// trailing slash, and the copy's own is the same path with one segment more.
			'no trailing slash' => ['/wikven/pagefind', 'citizen', '/wikven/citizen/pagefind/'],
			'several directories down' => ['/a/b/pagefind/', 'citizen', '/a/b/citizen/pagefind/'],
			// Nothing here knows where such a bundle's site root is, so it is left where it is and
			// the copy goes on reading the one bundle -- today's behaviour, not a broken path.
			'another host' => ['https://cdn.example/pagefind/', 'citizen', null],
			'protocol-relative' => ['//cdn.example/pagefind/', 'citizen', null],
			'a relative path' => ['pagefind/', 'citizen', null],
			'nothing configured' => ['', 'citizen', null],
			// A bundle claiming the site root itself has no directory of its own to reproduce, and
			// a copy of every page under it would collide with the copy's own pages.
			'the site root' => ['/', 'citizen', null],
			'no copy directory' => ['/wikven/pagefind/', '', null]
		];
	}

	/** The two spellings a bake of the docs site produced of one index, differing only in order. */
	private const ENTRY_KO_FIRST =
		'{"version":"1.5.2","languages":'
			. '{"ko":{"hash":"ko_72ba3bbda7","wasm":null,"page_count":19},'
			. '"en":{"hash":"en_e66da688eb","wasm":"en","page_count":39},'
			. '"km":{"hash":"km_8ad2c8bfcb","wasm":null,"page_count":1}},'
			. '"include_characters":["_","‿","⁀","⁔","︳","︴","﹍","﹎","﹏","＿"]}';
	private const ENTRY_EN_FIRST =
		'{"version":"1.5.2","languages":'
			. '{"en":{"hash":"en_e66da688eb","wasm":"en","page_count":39},'
			. '"ko":{"hash":"ko_72ba3bbda7","wasm":null,"page_count":19},'
			. '"km":{"hash":"km_8ad2c8bfcb","wasm":null,"page_count":1}},'
			. '"include_characters":["_","‿","⁀","⁔","︳","︴","﹍","﹎","﹏","＿"]}';

	/**
	 * The fixtures are the real thing: the two files a single source produced on one runner, which
	 * agreed on every hash and page count and disagreed on the order alone. That is the whole
	 * failure, and normalising is what makes the two bakes byte-identical again (#411).
	 */
	public function testStableIndexEntrySettlesTheLanguageOrder() {
		$this->assertNotSame(
			self::ENTRY_KO_FIRST,
			self::ENTRY_EN_FIRST,
			'fixtures must differ, or this asserts nothing'
		);
		$this->assertSame(
			Search::stableIndexEntry(self::ENTRY_KO_FIRST),
			Search::stableIndexEntry(self::ENTRY_EN_FIRST)
		);
	}

	/**
	 * Only the order of that one map may move. The version and the character list keep their place,
	 * and the characters keep their bytes -- escaping them to ‿ would make this file differ
	 * from an older bake for no reason, which is the very thing being fixed.
	 */
	public function testStableIndexEntryChangesNothingButTheOrder() {
		$stable = Search::stableIndexEntry(self::ENTRY_KO_FIRST);

		$this->assertSame(
			[
				'version' => '1.5.2',
				'languages' => [
					'en' => ['hash' => 'en_e66da688eb', 'wasm' => 'en', 'page_count' => 39],
					'km' => ['hash' => 'km_8ad2c8bfcb', 'wasm' => null, 'page_count' => 1],
					'ko' => ['hash' => 'ko_72ba3bbda7', 'wasm' => null, 'page_count' => 19]
				],
				'include_characters' => ['_', '‿', '⁀', '⁔', '︳', '︴', '﹍', '﹎', '﹏', '＿']
			],
			json_decode((string)$stable, true)
		);
		$this->assertStringContainsString('"include_characters":["_","‿"', (string)$stable);
		$this->assertStringStartsWith('{"version":"1.5.2","languages":{"en"', (string)$stable);
	}

	/**
	 * @dataProvider provideUnrewritableEntries
	 */
	public function testStableIndexEntryLeavesWhatItCannotReadAlone(string $json) {
		$this->assertNull(Search::stableIndexEntry($json));
	}

	public static function provideUnrewritableEntries() {
		return [
			// A bundle whose entry file this does not recognise is not one to rewrite: answering
			// null leaves the file exactly as Pagefind wrote it.
			'not json' => ['<!DOCTYPE html>'],
			'truncated' => ['{"version":"1.5.2","languages":{'],
			'no language map' => ['{"version":"1.5.2"}'],
			'languages is not a map' => ['{"languages":"en"}'],
			// Nothing to order, so nothing to settle; rewriting would only risk the bytes.
			'no languages yet' => ['{"version":"1.5.2","languages":{}}']
		];
	}
}
