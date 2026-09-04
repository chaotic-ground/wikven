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
	 * What a picture the build made local is called, from the reference pages had for it.
	 *
	 * Content-addressed so one picture referenced twice is stored once, and so a rebuild of an
	 * unchanged site writes the same name (#411). Kept here rather than in the step that stores
	 * them because two things now have to agree on it: storeImages names the file, and a site's
	 * social image has to be named in a head tag before that step has run. A rule kept twice is a
	 * rule that drifts, and the drift here is a head tag pointing at a file nobody wrote.
	 *
	 * @param string $key What identifies the picture: the storage path for a local file, the whole
	 *   URL for a remote one. Two references to one picture must give the same key.
	 * @param string|null $from Where to read the extension from, when that is not the key itself.
	 */
	public static function imageName(string $key, ?string $from = null): string {
		return 'img-' . substr(md5($key), 0, 12) . '.' . self::extension($from ?? $key);
	}

	/** A safe lowercase file extension, defaulting to "img". */
	public static function extension(string $url): string {
		$ext = strtolower((string)pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
		return preg_match('/^[a-z0-9]+$/', $ext) ? $ext : 'img';
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
