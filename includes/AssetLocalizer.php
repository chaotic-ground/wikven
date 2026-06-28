<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;

/** Rewrites image url()s in dumped CSS/JS to local copies, avoiding load.php references. */
class AssetLocalizer {
	/** Rewrite image url()s in the given dumped CSS/JS files to point at copies in $dir. */
	public static function localizeImages(
		ResourceLoader $rl,
		string $dir,
		array $files,
		string $lang,
		string $skin
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
				'~url\(\s*[\'"]?([^)\'"]*load\.php\?[^)\'"]*image=[^)\'"]*?)[\'"]?\s*\)~',
				static function ($m) use (&$map, $rl, $dir, $lang, $skin) {
					// Decode the JSON-string escapes used inside JS bundles.
					$url = str_replace(
						['\\/', '\\u0026', '\\u003d', '\\u003D'],
						['/', '&', '=', '='],
						$m[1]
					);
					if (!array_key_exists($url, $map)) {
						$map[$url] = self::dumpRlImage($rl, $url, $dir, $lang, $skin);
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
					static function ($m) use (&$map, $mwRoot, $dir) {
						$path = str_replace('\\/', '/', $m[1]);
						if (!array_key_exists($path, $map)) {
							$map[$path] = self::copyAsset($mwRoot, $path, $dir);
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
	 * Dump a decoded /load.php?...image=... reference ($url) to a local image file.
	 *
	 * @return string|null Relative url() target (./img-*.svg), or null if not an image.
	 */
	private static function dumpRlImage(
		ResourceLoader $rl,
		string $url,
		string $dir,
		string $lang,
		string $skin
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

		ob_start();
		$rl->respond(new Context($rl, new FauxRequest($query)));
		$bytes = ob_get_clean();

		$isSvg = $bytes !== false && str_contains($bytes, '<svg');
		$isPng = $bytes !== false && strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0;
		if (!$isSvg && !$isPng) {
			return null;
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
	private static function copyAsset(string $mwRoot, string $path, string $dir): ?string {
		$src = $mwRoot . $path;
		if (!is_readable($src)) {
			return null;
		}
		$bytes = file_get_contents($src);
		if ($bytes === false || $bytes === '') {
			return null;
		}
		$ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'svg';
		$name = 'img-' . substr(md5($path), 0, 12) . ".$ext";
		file_put_contents("$dir/$name", $bytes, LOCK_EX);
		return "./$name";
	}
}
