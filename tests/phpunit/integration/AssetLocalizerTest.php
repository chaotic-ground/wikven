<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Output\AssetLocalizer;
use MediaWiki\ResourceLoader\ImageModule;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWikiIntegrationTestCase;
use Wikimedia\AtEase\AtEase;

/**
 * @covers \MediaWiki\Extension\Wikven\Output\AssetLocalizer
 */
class AssetLocalizerTest extends MediaWikiIntegrationTestCase {
	/**
	 * The ResourceLoader service, with $GLOBALS['IP'] then pointed at a fixture install root.
	 *
	 * The container reads the real install the first time it builds the ResourceLoader, so build
	 * it while $IP is still the real one.
	 */
	private function resourceLoaderRootedAt(string $mwRoot): ResourceLoader {
		$rl = $this->getServiceContainer()->getResourceLoader();
		$this->setMwGlobals('IP', $mwRoot);
		return $rl;
	}

	/**
	 * Direct skin/resource/extension asset url()s in dumped CSS are the most regex-fragile part of
	 * the static export: if they stop matching, the output silently points at paths that only exist
	 * inside a live MediaWiki.
	 */
	public function testLocalizeAssetsRewritesDirectAssetPaths() {
		$mwRoot = $this->getNewTempDirectory();
		mkdir("$mwRoot/skins/Vector/images", 0777, true);
		file_put_contents("$mwRoot/skins/Vector/images/arrow.svg", '<svg>arrow</svg>');
		$rl = $this->resourceLoaderRootedAt($mwRoot);

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
	 * A JS bundle injects its CSS into the document, so a "./img-*.svg" url() would resolve against
	 * the page and 404 on any subpage. In $inline mode the image must be embedded instead.
	 */
	public function testLocalizeAssetsInlinesAssetsAsDataUris() {
		$mwRoot = $this->getNewTempDirectory();
		mkdir("$mwRoot/skins/Vector/images", 0777, true);
		file_put_contents("$mwRoot/skins/Vector/images/arrow.svg", '<svg>arrow</svg>');
		$rl = $this->resourceLoaderRootedAt($mwRoot);

		$dir = $this->getNewTempDirectory();
		$js = "$dir/modules-static.js";
		file_put_contents($js, '.a{background:url(/skins/Vector/images/arrow.svg)}' . "\n");

		AssetLocalizer::localizeAssets($rl, $dir, [$js], 'en', 'vector', true);

		$out = file_get_contents($js);
		// CSSMin percent-encodes printable content like this SVG rather than base64-encoding it.
		$expected = 'url(data:image/svg+xml,%3Csvg%3Earrow%3C/svg%3E)';
		$this->assertStringContainsString($expected, $out, 'asset inlined as a data: URI');
		$this->assertStringNotContainsString('url(./img-', $out, 'no relative file reference emitted');
		$this->assertCount(0, glob("$dir/img-*.svg"), 'no image file written in inline mode');
	}

	/**
	 * A skin that ships its own typeface points @font-face at a path only a live MediaWiki serves.
	 * The font is copied out beside the stylesheet -- but never inlined, which would put the whole
	 * typeface in base64 into every page's JavaScript.
	 */
	public function testLocalizeAssetsCopiesFontsForStylesheetsOnly() {
		$mwRoot = $this->getNewTempDirectory();
		mkdir("$mwRoot/skins/Citizen/resources/fonts", 0777, true);
		file_put_contents("$mwRoot/skins/Citizen/resources/fonts/Roboto.woff2", 'wOF2fake');
		$rl = $this->resourceLoaderRootedAt($mwRoot);

		$rule = '@font-face{src:url(/skins/Citizen/resources/fonts/Roboto.woff2) format("woff2")}';

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

	/**
	 * A real icon SVG carries attributes, so the inlined URI has spaces between them. CSSMin leaves
	 * those bare because it quotes its url(); the one emitted here is unquoted.
	 */
	public function testLocalizeAssetsEncodesSpacesInInlinedAssets() {
		$mwRoot = $this->getNewTempDirectory();
		mkdir("$mwRoot/skins/Vector/images", 0777, true);
		file_put_contents("$mwRoot/skins/Vector/images/icon.svg", '<svg width="20" height="20"/>');
		$rl = $this->resourceLoaderRootedAt($mwRoot);

		$dir = $this->getNewTempDirectory();
		$js = "$dir/modules-static.js";
		file_put_contents($js, '.a{mask-image:url(/skins/Vector/images/icon.svg)}' . "\n");

		AssetLocalizer::localizeAssets($rl, $dir, [$js], 'en', 'vector', true);

		$out = file_get_contents($js);
		$this->assertSame(
			1,
			preg_match('~url\((data:[^)]*)\)~', $out, $m),
			'the reference is still a single url()'
		);
		$this->assertStringNotContainsString(' ', $m[1], 'no bare space inside the url()');
		$this->assertSame(
			'<svg width="20" height="20"/>',
			rawurldecode(substr($m[1], strlen('data:image/svg+xml,'))),
			'and it still decodes back to the asset'
		);
	}

	/** An image module of our own, so the test does not ride on what core happens to ship. */
	private function registerImageModule(ResourceLoader $rl): void {
		$images = $this->getNewTempDirectory();
		file_put_contents("$images/arrow.svg", '<svg xmlns="http://www.w3.org/2000/svg">arrow</svg>');
		$rl->register('wikven.testImages', [
			'class' => ImageModule::class,
			'prefix' => 'wikven-test',
			'localBasePath' => $images,
			// A skin asks for the variant its palette needs, and the request carries it.
			'images' => ['arrow' => ['file' => 'arrow.svg', 'variants' => ['invert']]],
			// Keyed twice: a variant list is looked up by skin, with 'default' only marking that the
			// list is keyed that way rather than standing in for a skin that is missing from it.
			'variants' => [
				'default' => ['invert' => ['color' => '#fff']],
				'vector' => ['invert' => ['color' => '#fff']]
			]
		]);
	}

	/** A stylesheet holding one url(), written where the pass would write it. */
	private function stylesheet(string $directory, string $url): string {
		$path = "$directory/styles.css";
		file_put_contents($path, ".a{background:url($url)}\n");
		return $path;
	}

	/**
	 * The other half of this class: a url() answered by load.php is a request to a server the export
	 * does not have, so the image is rendered once and written beside the stylesheet.
	 */
	public function testAnImageServedByLoadPhpIsRenderedIntoTheOutput() {
		$rl = $this->resourceLoaderRootedAt($this->getNewTempDirectory());
		$this->registerImageModule($rl);
		$directory = $this->getNewTempDirectory();
		$css = $this->stylesheet(
			$directory,
			'/load.php?modules=wikven.testImages&image=arrow&format=original&version=1a2b3'
		);

		AssetLocalizer::localizeAssets($rl, $directory, [$css], 'en', 'vector');

		$out = file_get_contents($css);
		$this->assertStringNotContainsString('load.php', $out);
		$this->assertSame(1, preg_match('~url\(\./(img-[0-9a-f]{12}\.svg)\)~', $out, $rewritten));
		$this->assertStringContainsString('arrow', file_get_contents("$directory/{$rewritten[1]}"));
	}

	/** A JS bundle's CSS resolves against the page rather than the file, so its images are embedded. */
	public function testAnImageServedByLoadPhpIsInlinedForABundle() {
		$rl = $this->resourceLoaderRootedAt($this->getNewTempDirectory());
		$this->registerImageModule($rl);
		$directory = $this->getNewTempDirectory();
		$css = $this->stylesheet(
			$directory,
			'\\u0027/load.php?modules=wikven.testImages\\u0026image=arrow\\u0026format=original\\u0027'
		);

		AssetLocalizer::localizeAssets($rl, $directory, [$css], 'en', 'vector', true);

		$out = file_get_contents($css);
		$this->assertStringContainsString('url(data:image/svg+xml', $out);
		$this->assertStringNotContainsString('load.php', $out);
		$this->assertSame([], glob("$directory/img-*"), 'nothing is written beside a bundle');
	}

	/**
	 * What is left where the reference cannot be turned into an image: a request naming no module,
	 * and one naming a module that answers with something other than an image.
	 *
	 * @dataProvider provideReferencesThatAreNotImages
	 */
	public function testAReferenceThatIsNotAnImageIsLeftWhereItIs(string $url) {
		$rl = $this->resourceLoaderRootedAt($this->getNewTempDirectory());
		$this->registerImageModule($rl);
		$directory = $this->getNewTempDirectory();
		$css = $this->stylesheet($directory, $url);

		AssetLocalizer::localizeAssets($rl, $directory, [$css], 'en', 'vector');

		$this->assertStringContainsString($url, file_get_contents($css));
		$this->assertSame([], glob("$directory/img-*"));
	}

	public static function provideReferencesThatAreNotImages(): array {
		return [
			'no module named' => ['/load.php?image=arrow&format=original'],
			'a module that is not images' => ['/load.php?modules=startup&image=arrow&only=scripts'],
			// Whatever a stylesheet said, it is not a URL anything can read a query out of.
			'not a URL at all' => ['http://:80x/load.php?modules=wikven.testImages&image=arrow']
		];
	}

	/** A file the pass listed but cannot read is passed over, leaving the others to be rewritten. */
	public function testAFileThatCannotBeReadIsPassedOver() {
		$rl = $this->resourceLoaderRootedAt($this->getNewTempDirectory());
		$this->registerImageModule($rl);
		$directory = $this->getNewTempDirectory();
		$css = $this->stylesheet(
			$directory,
			'/load.php?modules=wikven.testImages&image=arrow&format=original'
		);

		// The read warns on its way to the false this is about, and PHPUnit fails a test that warns.
		AtEase::suppressWarnings();
		try {
			AssetLocalizer::localizeAssets($rl, $directory, ["$directory/gone.css", $css], 'en', 'vector');
		} finally {
			AtEase::restoreWarnings();
		}

		$this->assertStringNotContainsString('load.php', file_get_contents($css));
	}

	/** A skin asking for a variant gets that variant, not the plain image under its name. */
	public function testAVariantIsRenderedAsTheVariant() {
		$rl = $this->resourceLoaderRootedAt($this->getNewTempDirectory());
		$this->registerImageModule($rl);
		$directory = $this->getNewTempDirectory();
		$css = $this->stylesheet(
			$directory,
			'/load.php?modules=wikven.testImages&image=arrow&variant=invert&format=original'
		);

		AssetLocalizer::localizeAssets($rl, $directory, [$css], 'en', 'vector');

		$this->assertSame(1, preg_match('~url\(\./(img-[0-9a-f]{12}\.svg)\)~', file_get_contents($css), $out));
		$this->assertStringContainsString('#fff', file_get_contents("$directory/{$out[1]}"));
	}

	/**
	 * A dumped stylesheet is the site's own MediaWiki:Common.css as much as it is a skin's, so a
	 * url() is not a path to read a file at until it is shown to be under the install.
	 */
	public function testADirectPathThatIsNotAFileUnderTheInstallIsLeftAlone() {
		$mwRoot = $this->getNewTempDirectory();
		mkdir("$mwRoot/skins/Vector/images", 0777, true);
		file_put_contents("$mwRoot/skins/Vector/images/empty.svg", '');
		$rl = $this->resourceLoaderRootedAt($mwRoot);
		$directory = $this->getNewTempDirectory();
		$css = "$directory/styles.css";
		$text =
			implode("\n", [
				'.a{background:url(/skins/../../etc/shadow.svg)}',
				'.b{background:url(/skins/Vector/images/gone.svg)}',
				'.c{background:url(/skins/Vector/images/empty.svg)}'
			]) . "\n";
		file_put_contents($css, $text);

		AssetLocalizer::localizeAssets($rl, $directory, [$css], 'en', 'vector');

		$this->assertSame($text, file_get_contents($css));
		$this->assertSame([], glob("$directory/img-*"));
	}
}
