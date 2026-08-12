<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Picks which UniversalLanguageSelector webfonts a site needs, so the export can carry them.
 *
 * ULS keeps its fonts as woff2 files in the extension tree and describes them in a generated file,
 * data compiled from data/fontrepo rather than anything a server computes. That makes the choice a
 * pure function of the site's languages, which is what this class is.
 */
class Webfonts {
	/** The module carrying the repository data, which ULS loads on demand rather than per page. */
	public const REPOSITORY_MODULE = 'ext.uls.webfonts.repository';

	/** Where the generated repository description sits, relative to the ULS extension directory. */
	public const REPOSITORY_FILE = 'resources/js/ext.uls.webfonts.repository.js';

	/** Where the woff2 files sit, relative to the ULS extension directory. */
	public const FONTS_DIR = 'data/fontrepo/fonts';

	/** Where the license texts a font.ini names sit, relative to the ULS extension directory. */
	public const LICENSES_DIR = 'data/fontrepo/licenses';

	/** Where the fonts land under the output directory, and what the base path points at. */
	public const OUTPUT_DIR = 'fonts/uls';

	/**
	 * Read the repository description as data.
	 *
	 * The file is JavaScript, but the object it assigns is JSON: quoted keys, no trailing commas, no
	 * expressions. Taking it as JSON keeps the build from executing anything ULS ships.
	 *
	 * @param string $contents The file's contents.
	 * @return array{languages: array<string, string[]>, fonts: array<string, array>}|null
	 *   Null if the file is not the shape this expects, so the caller can say so rather than guess.
	 */
	public static function repository(string $contents): ?array {
		if (!preg_match('/\$\.webfonts\.repository\s*=\s*(\{.*?\});/s', $contents, $matches)) {
			return null;
		}
		$data = json_decode($matches[1], true);
		if (!is_array($data) || !isset($data['languages']) || !isset($data['fonts'])) {
			return null;
		}
		return ['languages' => $data['languages'], 'fonts' => $data['fonts']];
	}

	/**
	 * The font families to ship for the given languages.
	 *
	 * A language whose list starts with "system" has a font already: the entries after it are reader
	 * preferences (a dyslexia-friendly face, a historical-kana face) that ULS only applies when
	 * someone picks one, so shipping them would cost a site hundreds of kilobytes to no effect. What
	 * is left is the case this feature exists for, a script with no system font, where the first
	 * entry is the font that stops the page rendering as tofu.
	 *
	 * @param array $repository As returned by repository().
	 * @param string[] $languages Language codes the site exports.
	 * @return string[] Family names, sorted, including the variants a shipped family declares.
	 */
	public static function familiesFor(array $repository, array $languages): array {
		$families = [];
		foreach ($languages as $language) {
			$listed = $repository['languages'][$language] ?? [];
			if (!$listed || $listed[0] === 'system') {
				continue;
			}
			foreach ($listed as $family) {
				if ($family !== 'system') {
					$families[$family] = true;
				}
			}
		}

		// A family names its bold and italic faces as separate entries; a page that renders bold
		// text in the script needs them too.
		$queue = array_keys($families);
		while ($queue) {
			$family = array_pop($queue);
			foreach ($repository['fonts'][$family]['variants'] ?? [] as $variant) {
				if (!isset($families[$variant])) {
					$families[$variant] = true;
					$queue[] = $variant;
				}
			}
		}

		$names = array_keys($families);
		sort($names);
		return $names;
	}

	/**
	 * The woff2 file each family is served from, relative to the font directory.
	 *
	 * The repository appends a cache-busting query to every path ("Alef/Alef-Regular.woff2?a2499").
	 * It belongs in the URL the browser asks for, not in the name of the file on disk.
	 *
	 * @param array $repository As returned by repository().
	 * @param string[] $families As returned by familiesFor().
	 * @return array<string, string> Family name => relative path, without the query.
	 */
	public static function filesFor(array $repository, array $families): array {
		$files = [];
		foreach ($families as $family) {
			$path = $repository['fonts'][$family]['woff2'] ?? null;
			if (is_string($path) && $path !== '') {
				$files[$family] = strtok($path, '?');
			}
		}
		return $files;
	}

	/**
	 * The license file a family's font.ini names, if it names one.
	 *
	 * Whoever turns this on publishes the fonts from their own site, so the license text travels
	 * with them. font.ini records it per family ("licensefile=OFL.txt").
	 *
	 * @param string $ini Contents of a family's font.ini.
	 * @return string|null The file name, or null when the family declares none.
	 */
	public static function licenseFile(string $ini): ?string {
		$parsed = parse_ini_string($ini, true, INI_SCANNER_RAW);
		if (!is_array($parsed)) {
			return null;
		}
		foreach ($parsed as $section) {
			if (is_array($section) && isset($section['licensefile']) && is_string($section['licensefile'])) {
				$name = trim($section['licensefile']);
				// Only a bare file name: the value names a file in the repository's licenses dir.
				if ($name !== '' && !str_contains($name, '/') && !str_contains($name, '..')) {
					return $name;
				}
			}
		}
		return null;
	}
}
