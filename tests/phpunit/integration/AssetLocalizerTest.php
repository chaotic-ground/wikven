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
	public function testLocalizeImagesRewritesDirectAssetPaths() {
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
		AssetLocalizer::localizeImages($rl, $dir, [$css], 'en', 'vector');

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
	public function testLocalizeImagesInlinesAssetsAsDataUris() {
		$mwRoot = $this->getNewTempDirectory();
		mkdir("$mwRoot/skins/Vector/images", 0777, true);
		file_put_contents("$mwRoot/skins/Vector/images/arrow.svg", '<svg>arrow</svg>');
		$this->setMwGlobals('IP', $mwRoot);

		$dir = $this->getNewTempDirectory();
		$js = "$dir/modules-static.js";
		file_put_contents($js, '.a{background:url(/skins/Vector/images/arrow.svg)}' . "\n");

		$rl = $this->getServiceContainer()->getResourceLoader();
		AssetLocalizer::localizeImages($rl, $dir, [$js], 'en', 'vector', true);

		$out = file_get_contents($js);
		$expected = 'url(data:image/svg+xml;base64,' . base64_encode('<svg>arrow</svg>') . ')';
		$this->assertStringContainsString($expected, $out, 'asset inlined as a data: URI');
		$this->assertStringNotContainsString('url(./img-', $out, 'no relative file reference emitted');
		$this->assertCount(0, glob("$dir/img-*.svg"), 'no image file written in inline mode');
	}
}
