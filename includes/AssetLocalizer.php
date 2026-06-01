<?php

namespace MediaWiki\Extension\Wikven;

use FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;

/**
 * Rewrites ResourceLoader image-endpoint references (url(/load.php?...image=...))
 * inside dumped CSS and JS bundles to locally dumped image files, so the static
 * output never calls load.php for an icon.
 *
 * Handles both plain CSS (url(/load.php?a&image=b)) and the JSON-escaped form
 * embedded in combined-mode JS bundles (url(\/load.php?a&image=b)).
 */
class AssetLocalizer {

	/**
	 * @param ResourceLoader $rl
	 * @param string $dir Directory the files live in and where images are dumped.
	 * @param string[] $files Absolute paths of CSS/JS files to rewrite in place.
	 * @param string $lang
	 * @param string $skin
	 */
	public static function localizeImages( ResourceLoader $rl, $dir, array $files, $lang, $skin ) {
		$map = [];
		foreach ( $files as $file ) {
			$text = file_get_contents( $file );
			if ( $text === false ) {
				continue;
			}
			$new = preg_replace_callback(
				'~url\(\s*[\'"]?([^)\'"]*load\.php\?[^)\'"]*image=[^)\'"]*?)[\'"]?\s*\)~',
				static function ( $m ) use ( &$map, $rl, $dir, $lang, $skin ) {
					// Decode the JSON-string escapes used inside JS bundles.
					$url = str_replace(
						[ '\\/', '\\u0026', '\\u003d', '\\u003D' ],
						[ '/', '&', '=', '=' ],
						$m[1]
					);
					if ( !array_key_exists( $url, $map ) ) {
						$map[$url] = self::dumpImage( $rl, $url, $dir, $lang, $skin );
					}
					return $map[$url] !== null ? 'url(' . $map[$url] . ')' : $m[0];
				},
				$text
			);
			if ( $new !== null && $new !== $text ) {
				file_put_contents( $file, $new, LOCK_EX );
			}
		}
	}

	/**
	 * Dump a single ResourceLoader image-endpoint URL to a local file.
	 *
	 * @param ResourceLoader $rl
	 * @param string $url Decoded /load.php?...image=... reference.
	 * @param string $dir
	 * @param string $lang
	 * @param string $skin
	 * @return string|null Relative url() target (./img-*.svg), or null if not an image.
	 */
	private static function dumpImage( ResourceLoader $rl, $url, $dir, $lang, $skin ) {
		$qs = parse_url( $url, PHP_URL_QUERY );
		if ( !$qs ) {
			return null;
		}
		parse_str( $qs, $p );
		if ( empty( $p['modules'] ) || !isset( $p['image'] ) ) {
			return null;
		}

		$query = [
			'modules' => $p['modules'],
			'image' => $p['image'],
			'format' => $p['format'] ?? 'original',
			'lang' => $lang,
			'skin' => $skin,
		];
		if ( isset( $p['variant'] ) ) {
			$query['variant'] = $p['variant'];
		}

		ob_start();
		$rl->respond( new Context( $rl, new FauxRequest( $query ) ) );
		$bytes = ob_get_clean();

		$isSvg = $bytes !== false && strpos( $bytes, '<svg' ) !== false;
		$isPng = $bytes !== false && strncmp( $bytes, "\x89PNG\r\n\x1a\n", 8 ) === 0;
		if ( !$isSvg && !$isPng ) {
			return null;
		}

		// Hash without the cache-busting version so filenames are stable across rebuilds.
		$key = preg_replace( '/[&?]version=[^&]*/', '', $url );
		$name = 'img-' . substr( md5( $key ), 0, 12 ) . ( $isSvg ? '.svg' : '.png' );
		file_put_contents( "$dir/$name", $bytes, LOCK_EX );
		return "./$name";
	}
}
