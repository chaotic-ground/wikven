<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\AssetLocalizer;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\AssetLocalizer
 */
class AssetLocalizerTest extends MediaWikiIntegrationTestCase {
	/**
	 * Direct skin/resource/extension asset url()s in dumped CSS are the most
	 * regex-fragile part of the static export: if they stop matching, the output
	 * silently points at paths that only exist inside a live MediaWiki. Assert
	 * that every reference form the build emits is rewritten to a local copy,
	 * that look-alike paths which must NOT match are left untouched, and that the
	 * referenced bytes are actually copied out.
	 */
	public function testLocalizeAssetsRewritesDirectAssetPaths() {
		$mwRoot = $this->getNewTempDirectory();
		mkdir("$mwRoot/skins/Vector/images", 0777, true);
		file_put_contents("$mwRoot/skins/Vector/images/arrow.svg", '<svg>arrow</svg>');
		// AssetLocalizer reads the install root from $GLOBALS['IP'].
		$this->setMwGlobals('IP', $mwRoot);

		$dir = $this->getNewTempDirectory();
		$css = "$dir/styles.css";
		file_put_contents($css, implode("\n", [
			// Plain, the JSON-escaped form combined-mode JS bundles use, and a
			// cache-busting query: all reference the same asset and must rewrite.
			'.a{background:url(/skins/Vector/images/arrow.svg)}',
			'.b{background:url(\/skins/Vector/images/arrow.svg)}',
			'.c{background:url(/skins/Vector/images/arrow.svg?a1b2)}',
			// A non-image under skins/ and a path outside the rewritten roots: kept.
			'.d{background:url(/skins/Vector/skin.css)}',
			'.e{background:url(/static/logo.svg)}'
		])
			. "\n");

		$rl = $this->getServiceContainer()->getResourceLoader();
		AssetLocalizer::localizeAssets($rl, $dir, [$css], 'en', 'vector');

		$out = file_get_contents($css);
		$this->assertStringNotContainsString('/skins/Vector/images/arrow.svg', $out, 'all three forms rewritten');
		$this->assertSame(3, preg_match_all('~url\(\./img-[0-9a-f]{12}\.svg\)~', $out), 'rewritten to local copies');
		$this->assertStringContainsString('url(/skins/Vector/skin.css)', $out, 'non-image left untouched');
		$this->assertStringContainsString(
			'url(/static/logo.svg)',
			$out,
			'path outside skins/resources/extensions left untouched'
		);

		// The three references share one decoded path, so one copy is dumped.
		$copies = glob("$dir/img-*.svg");
		$this->assertCount(1, $copies, 'deduplicated to a single dumped copy');
		$this->assertSame('<svg>arrow</svg>', file_get_contents($copies[0]), 'asset bytes copied verbatim');
	}

	/**
	 * A JS bundle injects its CSS into the document, so a "./img-*.svg" url() would
	 * resolve against the page and 404 on any subpage. In $inline mode the image
	 * must instead be embedded as a data: URI (depth- and base-path-safe) with no
	 * file written out.
	 */
	public function testLocalizeAssetsInlinesAssetsAsDataUris() {
		$mwRoot = $this->getNewTempDirectory();
		mkdir("$mwRoot/skins/Vector/images", 0777, true);
		file_put_contents("$mwRoot/skins/Vector/images/arrow.svg", '<svg>arrow</svg>');
		$this->setMwGlobals('IP', $mwRoot);

		$dir = $this->getNewTempDirectory();
		$js = "$dir/modules-static.js";
		file_put_contents($js, '.a{background:url(/skins/Vector/images/arrow.svg)}' . "\n");

		$rl = $this->getServiceContainer()->getResourceLoader();
		AssetLocalizer::localizeAssets($rl, $dir, [$js], 'en', 'vector', true);

		$out = file_get_contents($js);
		// CSSMin percent-encodes printable content like this SVG rather than base64-encoding it.
		$expected = 'url(data:image/svg+xml,%3Csvg%3Earrow%3C/svg%3E)';
		$this->assertStringContainsString($expected, $out, 'asset inlined as a data: URI');
		$this->assertStringNotContainsString('url(./img-', $out, 'no relative file reference emitted');
		$this->assertCount(0, glob("$dir/img-*.svg"), 'no image file written in inline mode');
	}

	/**
	 * A skin that ships its own typeface (Citizen does) points @font-face at a path only a live
	 * MediaWiki serves, so the export renders in a fallback font and 404s once per face. The font
	 * is copied out beside the stylesheet -- but never inlined, which would put the whole typeface
	 * in base64 into every page's JavaScript.
	 */
	public function testLocalizeAssetsCopiesFontsForStylesheetsOnly() {
		$mwRoot = $this->getNewTempDirectory();
		mkdir("$mwRoot/skins/Citizen/resources/fonts", 0777, true);
		file_put_contents("$mwRoot/skins/Citizen/resources/fonts/Roboto.woff2", 'wOF2fake');
		$this->setMwGlobals('IP', $mwRoot);

		$rule = '@font-face{src:url(/skins/Citizen/resources/fonts/Roboto.woff2) format("woff2")}';
		$rl = $this->getServiceContainer()->getResourceLoader();

		$cssDir = $this->getNewTempDirectory();
		file_put_contents("$cssDir/styles.css", $rule . "\n");
		AssetLocalizer::localizeAssets($rl, $cssDir, ["$cssDir/styles.css"], 'en', 'citizen');

		$css = file_get_contents("$cssDir/styles.css");
		$this->assertMatchesRegularExpression('~url\(\./font-[0-9a-f]{12}\.woff2\)~', $css, 'rewritten');
		$copies = glob("$cssDir/font-*.woff2");
		$this->assertCount(1, $copies, 'the font is copied beside the stylesheet');
		$this->assertSame('wOF2fake', file_get_contents($copies[0]), 'font bytes copied verbatim');

		$jsDir = $this->getNewTempDirectory();
		file_put_contents("$jsDir/modules-static.js", $rule . "\n");
		AssetLocalizer::localizeAssets($rl, $jsDir, ["$jsDir/modules-static.js"], 'en', 'citizen', true);

		$this->assertStringContainsString(
			'url(/skins/Citizen/resources/fonts/Roboto.woff2)',
			file_get_contents("$jsDir/modules-static.js"),
			'left alone rather than inlined into the bundle'
		);
	}
}
