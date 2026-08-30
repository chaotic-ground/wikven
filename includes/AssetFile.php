<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Where something the build generated ends up: the href a page links it by, and the file it is.
 *
 * $wgWikvenAssetDirectory puts every generated file anywhere under the output root, so a step that
 * links one has to answer the same question the step that wrote it answered. Deriving the link and
 * the path together is what keeps the two answers from parting: a link written for one directory
 * while the file is looked for in another leaves the page with no stylesheet at all, and nothing
 * anywhere to say so.
 *
 * The href is written relative to the output root, as everything wikven writes is; a page exported
 * into a subdirectory has RelativeUrl::reparent() add a "../" per level to it.
 */
class AssetFile {
	/**
	 * @param string $htmlDirectory The output directory, as $wgWikvenHtmlDirectory gives it.
	 * @param string $assetDirectory The asset directory under it, as $wgWikvenAssetDirectory gives it.
	 * @param string $name The file's name, e.g. "site.styles.css" or "startup-static.js".
	 * @return array{href: string, path: string} The href to link it by, and the path it lives at.
	 */
	public static function locate(string $htmlDirectory, string $assetDirectory, string $name): array {
		$directory = self::directory($assetDirectory);
		$htmlDirectory = rtrim($htmlDirectory, '/');
		return [
			'href' => $directory === '' ? "./$name" : "./$directory/$name",
			'path' => $directory === '' ? "$htmlDirectory/$name" : "$htmlDirectory/$directory/$name"
		];
	}

	/**
	 * The directory the assets live in, as a path under the output root, or "" for the root itself.
	 *
	 * Both spellings of the output directory itself -- "" and "." -- name the root, and neither is a
	 * path segment of its own.
	 *
	 * @param string $assetDirectory As $wgWikvenAssetDirectory gives it.
	 */
	public static function directory(string $assetDirectory): string {
		$directory = trim($assetDirectory, '/');
		return $directory === '.' ? '' : $directory;
	}

	/**
	 * The absolute directory the assets are written into, which is the output root when there is none.
	 *
	 * @param string $htmlDirectory The output directory, as $wgWikvenHtmlDirectory gives it.
	 * @param string $assetDirectory As $wgWikvenAssetDirectory gives it.
	 */
	public static function path(string $htmlDirectory, string $assetDirectory): string {
		$htmlDirectory = rtrim($htmlDirectory, '/');
		$directory = self::directory($assetDirectory);
		return $directory === '' ? $htmlDirectory : "$htmlDirectory/$directory";
	}

	/**
	 * How many levels the asset directory is below the output root.
	 *
	 * A stylesheet in it reaches a file at the root -- the bundled webfonts, which are copied to a
	 * fixed directory there -- by stepping up this many times.
	 *
	 * @param string $assetDirectory As $wgWikvenAssetDirectory gives it.
	 */
	public static function depth(string $assetDirectory): int {
		$directory = self::directory($assetDirectory);
		return $directory === '' ? 0 : substr_count($directory, '/') + 1;
	}
}
