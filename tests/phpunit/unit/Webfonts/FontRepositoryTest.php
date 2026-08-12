<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit\Webfonts;

use MediaWiki\Extension\Wikven\Webfonts\FontRepository;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Webfonts\FontRepository
 */
class FontRepositoryTest extends MediaWikiUnitTestCase {
	/** A trimmed-down repository shaped like ULS's generated ext.uls.webfonts.repository. */
	private function repository(): array {
		return [
			'base' => '../data/fontrepo/fonts/',
			'languages' => [
				'am' => ['AbyssinicaSIL'],
				'ti' => ['AbyssinicaSIL'],
				'he' => ['Alef'],
				'af' => ['system', 'OpenDyslexic'],
				'xx' => ['MissingFont'],
			],
			'fonts' => [
				'AbyssinicaSIL' => ['woff2' => 'AbyssinicaSIL/AbyssinicaSIL-R.woff2?2942e'],
				'Alef' => ['woff2' => 'Alef/Alef-Regular.woff2?a2499', 'variants' => ['bold' => 'Alef Bold']],
				'Alef Bold' => ['fontweight' => 'bold', 'woff2' => 'Alef/Alef-Bold.woff2?7c873'],
				'OpenDyslexic' => ['woff2' => 'OpenDyslexic/OpenDyslexic.woff2?9f0e1'],
			],
		];
	}

	public function testDefaultFamilyPicksTheFirstRealFont() {
		$repo = new FontRepository($this->repository());
		$this->assertSame('AbyssinicaSIL', $repo->defaultFamily('am'));
		$this->assertSame('Alef', $repo->defaultFamily('he'));
	}

	public function testDefaultFamilyIsNullWhenSystemFontLeadsOrFontIsUnknown() {
		$repo = new FontRepository($this->repository());
		// "af" defaults to the system font, so ULS applies no webfont.
		$this->assertNull($repo->defaultFamily('af'));
		// The listed family is not in the fonts map.
		$this->assertNull($repo->defaultFamily('xx'));
		// No entry for the language at all.
		$this->assertNull($repo->defaultFamily('zz'));
	}

	public function testBuildEmitsFacesApplyRulesAndFileList() {
		$built = ( new FontRepository($this->repository() ) )
			->build(['am', 'he', 'af', 'xx', 'ti'], 'fonts/uls/');

		$css = $built['css'];

		// @font-face for the applied fonts, with a base-relative, query-stripped url().
		$this->assertStringContainsString(
			"@font-face{font-family:'AbyssinicaSIL';font-weight:normal;font-style:normal;font-display:swap;"
			. "src:local('AbyssinicaSIL'),url('fonts/uls/AbyssinicaSIL/AbyssinicaSIL-R.woff2') format('woff2');}",
			$css
		);
		// The bold variant is declared under the same family name.
		$this->assertStringContainsString(
			"@font-face{font-family:'Alef';font-weight:bold;font-style:normal;font-display:swap;"
			. "src:local('Alef'),url('fonts/uls/Alef/Alef-Bold.woff2') format('woff2');}",
			$css
		);
		// :lang() application rules for the languages whose default is a real font.
		$this->assertStringContainsString(":lang(am),[lang=\"am\"]{font-family:'AbyssinicaSIL',sans-serif;}", $css);
		$this->assertStringContainsString(":lang(he),[lang=\"he\"]{font-family:'Alef',sans-serif;}", $css);

		// The woff2 files to copy: deduped, query-stripped, sorted; OpenDyslexic excluded (af is system).
		$this->assertSame(
			[
				'AbyssinicaSIL/AbyssinicaSIL-R.woff2',
				'Alef/Alef-Bold.woff2',
				'Alef/Alef-Regular.woff2',
			],
			$built['files']
		);
	}

	public function testSystemDefaultLanguagesContributeNothing() {
		$built = ( new FontRepository($this->repository() ) )->build(['af', 'xx'], 'fonts/uls/');
		$this->assertSame('', $built['css']);
		$this->assertSame([], $built['files']);
	}

	public function testAFontSharedByTwoLanguagesIsDefinedOnce() {
		// Both "am" and "ti" default to AbyssinicaSIL.
		$built = ( new FontRepository($this->repository() ) )->build(['am', 'ti'], 'fonts/uls/');
		$this->assertSame(1, substr_count($built['css'], "@font-face{font-family:'AbyssinicaSIL';"));
		$this->assertStringContainsString(":lang(am),[lang=\"am\"]", $built['css']);
		$this->assertStringContainsString(":lang(ti),[lang=\"ti\"]", $built['css']);
	}

	public function testBuildIsDeterministicRegardlessOfLanguageOrder() {
		$repo = new FontRepository($this->repository());
		$this->assertSame(
			$repo->build(['am', 'he', 'ti'], 'fonts/uls/'),
			$repo->build(['ti', 'he', 'am', 'am'], 'fonts/uls/')
		);
	}

	public function testBasePathIsNormalisedWithASingleTrailingSlash() {
		$built = ( new FontRepository($this->repository() ) )->build(['am'], '../fonts/uls');
		$this->assertStringContainsString("url('../fonts/uls/AbyssinicaSIL/AbyssinicaSIL-R.woff2')", $built['css']);
	}
}
