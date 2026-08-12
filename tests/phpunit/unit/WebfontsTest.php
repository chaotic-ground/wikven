<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Webfonts;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Webfonts
 */
class WebfontsTest extends MediaWikiUnitTestCase {
	/**
	 * The repository file as ULS generates it, cut down to the entries these tests need but keeping
	 * its shape: the JS wrapper, the cache-busting query on every path, and the two kinds of language
	 * entry, one with a system font in front and one without.
	 */
	private function repositoryFile(): string {
		return <<<'JS'
			// Do not edit! This file is generated from data/fontrepo by scripts/compile-font-repo.php
			( function () {
				$.webfonts = $.webfonts || {};
				$.webfonts.repository = {
				"base": "../data/fontrepo/fonts/",
				"languages": {
					"bug": [
						"Saweri"
					],
					"en": [
						"system",
						"ComicNeue",
						"OpenDyslexic"
					],
					"he": [
						"system",
						"Alef"
					],
					"hbo": [
						"Alef"
					]
				},
				"fonts": {
					"Saweri": {
						"woff2": "saweri/saweri.woff2?fe482"
					},
					"Alef": {
						"woff2": "Alef/Alef-Regular.woff2?a2499",
						"variants": {
							"bold": "Alef Bold"
						}
					},
					"Alef Bold": {
						"fontweight": "bold",
						"woff2": "Alef/Alef-Bold.woff2?7c873"
					},
					"ComicNeue": {
						"woff2": "ComicNeue/ComicNeue-Regular.woff2?11111"
					},
					"OpenDyslexic": {
						"woff2": "OpenDyslexic/OpenDyslexic-Regular.woff2?22222"
					}
				}
				};
			}() );
			JS;
	}

	private function repository(): array {
		return Webfonts::repository($this->repositoryFile());
	}

	public function testTheGeneratedFileIsReadAsData() {
		$repository = $this->repository();
		$this->assertIsArray($repository);
		$this->assertSame(['Saweri'], $repository['languages']['bug']);
		$this->assertSame('saweri/saweri.woff2?fe482', $repository['fonts']['Saweri']['woff2']);
	}

	/** Anything that is not the generated shape is refused, rather than half-read. */
	public function testSomethingElseIsRefused() {
		$this->assertNull(Webfonts::repository(''));
		$this->assertNull(Webfonts::repository('$.webfonts.repository = notAnObject;'));
		$this->assertNull(Webfonts::repository('$.webfonts.repository = {"languages": {}};'));
	}

	/** A language with no system font gets its font: this is the case the feature exists for. */
	public function testALanguageWithoutASystemFontIsShipped() {
		$this->assertSame(['Saweri'], Webfonts::familiesFor($this->repository(), ['bug']));
	}

	/**
	 * A language whose list starts with "system" is left alone. Its remaining entries are reader
	 * preferences, so shipping them would cost payload for a font nobody has asked to see.
	 */
	public function testASystemFirstLanguageShipsNothing() {
		$this->assertSame([], Webfonts::familiesFor($this->repository(), ['en', 'he']));
	}

	public function testAnUnlistedLanguageShipsNothing() {
		$this->assertSame([], Webfonts::familiesFor($this->repository(), ['ko', 'zh']));
	}

	/** Bold and italic faces are separate entries, and text in the script may well be bold. */
	public function testVariantsComeAlong() {
		$this->assertSame(['Alef', 'Alef Bold'], Webfonts::familiesFor($this->repository(), ['hbo']));
	}

	public function testEachLanguageIsCountedOnce() {
		$families = Webfonts::familiesFor($this->repository(), ['bug', 'bug', 'hbo']);
		$this->assertSame(['Alef', 'Alef Bold', 'Saweri'], $families);
	}

	/** The cache-busting query belongs in the URL the browser asks for, not in the file's name. */
	public function testFilePathsLoseTheQuery() {
		$files = Webfonts::filesFor($this->repository(), ['Saweri', 'Alef', 'Alef Bold']);
		$this->assertSame(
			[
				'Saweri' => 'saweri/saweri.woff2',
				'Alef' => 'Alef/Alef-Regular.woff2',
				'Alef Bold' => 'Alef/Alef-Bold.woff2'
			],
			$files
		);
	}

	public function testAFamilyWithNoFileIsSkipped() {
		$this->assertSame([], Webfonts::filesFor($this->repository(), ['NoSuchFamily']));
	}

	/** font.ini names the license text that has to travel with the font. */
	public function testTheLicenseFileIsReadFromFontIni() {
		$ini = "[Saweri]\nlanguages=bug*\nversion=2\nlicense=GPL-3.0-only\nlicensefile=gpl-3.0.txt\n";
		$this->assertSame('gpl-3.0.txt', Webfonts::licenseFile($ini));
	}

	public function testAFamilyThatNamesNoLicenseFileGivesNull() {
		$this->assertNull(Webfonts::licenseFile("[Akkadian]\nlanguages=akk*\nversion=1\n"));
		$this->assertNull(Webfonts::licenseFile(''));
	}

	/** The value names a file in the repository's licenses directory, so a path is not one. */
	public function testAPathIsRefusedAsALicenseFile() {
		$this->assertNull(Webfonts::licenseFile("[X]\nlicensefile=../../../etc/passwd\n"));
		$this->assertNull(Webfonts::licenseFile("[X]\nlicensefile=sub/dir/OFL.txt\n"));
	}
}
