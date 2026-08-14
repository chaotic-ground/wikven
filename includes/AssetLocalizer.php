<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;

/** Rewrites image url()s in dumped CSS/JS to local copies, avoiding load.php references. */
class AssetLocalizer {
	/**
	 * Rewrite image url()s in the given dumped CSS/JS files to point at copies in $dir.
	 *
	 * A CSS file resolves its url()s relative to itself, so a "./img-*.svg" next to it works from
	 * any page depth. A JS bundle instead injects its CSS into the document, where url()s resolve
	 * against the page, breaking on subpages ("index/ko.html" would fetch "index/img-*.svg"). Pass
	 * $inline for JS bundles to embed the images as data: URIs, which are depth- and base-path-safe.
	 */
	public static function localizeImages(
		ResourceLoader $rl,
		string $dir,
		array $files,
		string $lang,
		string $skin,
		bool $inline = false
	): void {
		$mwRoot = rtrim((string)( $GLOBALS['IP'] ?? '' ), '/');
		$map = [];
		foreach ($files as $file) {
			$text = file_get_contents($file);
			if ($text === false) {
				continue;
			}

			// (1) ResourceLoader image endpoint.
			$text = preg_replace_callback(
				// The delimiter is a literal quote in a plain stylesheet, but inside a JS bundle it is the
				// escaped " / ' CSSMin emits whenever a url() has to be quoted.
				'~url\(\s*(?:\\\\u002[27]|[\'"])?([^)\'"]*load\.php\?[^)\'"]*image=[^)\'"]*?)'
				. '(?:\\\\u002[27]|[\'"])?\s*\)~',
				static function ($m) use (&$map, $rl, $dir, $lang, $skin, $inline) {
					// Decode the JSON-string escapes used inside JS bundles.
					$url = self::decodeJsonString($m[1]);
					if (!array_key_exists($url, $map)) {
						$map[$url] = self::dumpRlImage($rl, $url, $dir, $lang, $skin, $inline);
					}
					return $map[$url] !== null ? 'url(' . $map[$url] . ')' : $m[0];
				},
				$text
			);

			// (2) Direct skin/resource/extension asset paths.
			if ($mwRoot !== '') {
				$text = preg_replace_callback(
					'~url\(\s*[\'"]?(\\\\?/(?:skins|resources|extensions)/[^)\'"?]+'
					. '\.(?:svg|png|gif|jpe?g))(?:\?[^)\'"]*)?[\'"]?\s*\)~',
					static function ($m) use (&$map, $mwRoot, $dir, $inline) {
						$path = self::decodeJsonString($m[1]);
						if (!array_key_exists($path, $map)) {
							$map[$path] = self::copyAsset($mwRoot, $path, $dir, $inline);
						}
						return $map[$path] !== null ? 'url(' . $map[$path] . ')' : $m[0];
					},
					$text
				);
			}

			file_put_contents($file, $text, LOCK_EX);
		}
	}

	/**
	 * Undo the escaping a URL picked up from being embedded in a JS bundle's JSON string literal.
	 *
	 * ResourceLoader escapes those with JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT, so
	 * json_decode is the exact inverse and covers every escape those flags produce. Text that is not a
	 * JSON string body -- a plain CSS file, where nothing was escaped in the first place -- passes
	 * through unchanged.
	 */
	private static function decodeJsonString(string $text): string {
		$decoded = json_decode('"' . $text . '"');
		return is_string($decoded) ? $decoded : $text;
	}

	/** Encode raw image bytes as a data: URI usable unquoted inside url(). */
	private static function dataUri(string $bytes, string $mime): string {
		return 'data:' . $mime . ';base64,' . base64_encode($bytes);
	}

	/**
	 * Dump a decoded /load.php?...image=... reference ($url) to a local image file.
	 *
	 * @return string|null Relative url() target (./img-*.svg), or null if not an image.
	 */
	private static function dumpRlImage(
		ResourceLoader $rl,
		string $url,
		string $dir,
		string $lang,
		string $skin,
		bool $inline = false
	): ?string {
		$qs = parse_url($url, PHP_URL_QUERY);
		if (!$qs) {
			return null;
		}
		parse_str($qs, $p);
		if (empty($p['modules']) || !isset($p['image'])) {
			return null;
		}

		$query = [
			'modules' => $p['modules'],
			'image' => $p['image'],
			'format' => $p['format'] ?? 'original',
			'lang' => $lang,
			'skin' => $skin
		];
		if (isset($p['variant'])) {
			$query['variant'] = $p['variant'];
		}

		$bytes = ModuleRenderer::render($rl, new Context($rl, new FauxRequest($query)));

		$isSvg = str_contains($bytes, '<svg');
		$isPng = strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0;
		if (!$isSvg && !$isPng) {
			return null;
		}

		if ($inline) {
			return self::dataUri($bytes, $isSvg ? 'image/svg+xml' : 'image/png');
		}

		// Hash without the cache-busting version so filenames are stable across rebuilds.
		$key = preg_replace('/[&?]version=[^&]*/', '', $url);
		$name = 'img-' . substr(md5($key), 0, 12) . ( $isSvg ? '.svg' : '.png' );
		file_put_contents("$dir/$name", $bytes, LOCK_EX);
		return "./$name";
	}

	/**
	 * Copy a direct asset path ($path, absolute web path like /skins/.../foo.svg) into $dir.
	 *
	 * @return string|null Relative url() target, or null if the file is missing.
	 */
	private static function copyAsset(string $mwRoot, string $path, string $dir, bool $inline = false): ?string {
		$src = $mwRoot . $path;
		if (!is_readable($src)) {
			return null;
		}
		$bytes = file_get_contents($src);
		if ($bytes === false || $bytes === '') {
			return null;
		}
		$ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'svg';

		if ($inline) {
			$mimes = [
				'svg' => 'image/svg+xml',
				'png' => 'image/png',
				'gif' => 'image/gif',
				'jpg' => 'image/jpeg',
				'jpeg' => 'image/jpeg'
			];
			return self::dataUri($bytes, $mimes[strtolower($ext)] ?? 'application/octet-stream');
		}

		$name = 'img-' . substr(md5($path), 0, 12) . ".$ext";
		file_put_contents("$dir/$name", $bytes, LOCK_EX);
		return "./$name";
	}
}
