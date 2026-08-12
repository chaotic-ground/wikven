<?php

namespace MediaWiki\Extension\Wikven\Webfonts;

/**
 * Turn UniversalLanguageSelector's font-repository data into a static @font-face
 * stylesheet, so an export can ship webfonts without ULS's runtime JavaScript.
 *
 * ULS applies, with no reader interaction, the first font listed for a language,
 * unless that first entry is "system" (the OS font, i.e. no webfont). This class
 * reproduces exactly that default: for each language whose default is a real
 * font, it emits an @font-face for the font and its bold/italic variants, and a
 * :lang() rule that applies it. The alternative fonts a reader could pick from
 * the ULS menu are dropped, since a static page carries no menu.
 *
 * The font url()s are written relative to a caller-supplied base, meant to be the
 * path from the stylesheet's own location to the copied woff2 files. Because the
 * URLs in an external stylesheet resolve against the stylesheet, not the page
 * linking it, they render correctly from any page depth or host subdirectory.
 */
class FontRepository {
	/** @var array<string,string[]> Language code => font family names, in preference order. */
	private array $languages;

	/** @var array<string,array> Family name => font config (woff2 path, variants, weight, style). */
	private array $fonts;

	/**
	 * @param array $repository The decoded $.webfonts.repository object (its languages/fonts maps).
	 */
	public function __construct(array $repository) {
		$this->languages = is_array($repository['languages'] ?? null) ? $repository['languages'] : [];
		$this->fonts = is_array($repository['fonts'] ?? null) ? $repository['fonts'] : [];
	}

	/**
	 * The family ULS applies to $language by default, or null when that default is the
	 * system font (the language's list starts with "system") or the language has no fonts.
	 *
	 * @param string $language
	 * @return string|null
	 */
	public function defaultFamily(string $language): ?string {
		$list = $this->languages[$language] ?? null;
		if (!is_array($list) || $list === []) {
			return null;
		}
		$first = $list[0];
		if (!is_string($first) || $first === 'system') {
			return null;
		}
		return isset($this->fonts[$first]) ? $first : null;
	}

	/**
	 * Build the stylesheet for a set of languages.
	 *
	 * @param string[] $languages Language codes present in the export.
	 * @param string $basePath Path prefix, relative to the stylesheet's own location, under
	 *   which the woff2 files are copied (e.g. "fonts/uls/").
	 * @return array{css: string, files: string[]} The stylesheet text, and the base-relative
	 *   woff2 paths (no "?query") it references, deduped. Both are deterministically ordered.
	 */
	public function build(array $languages, string $basePath): array {
		$prefix = rtrim($basePath, '/') . '/';

		// A unique, sorted language list keeps a rebake byte-identical whatever order pages came in.
		$languages = array_values(array_unique($languages));
		sort($languages);

		$files = [];
		// Family => @font-face block(s), deduped so a font shared by two languages is defined once.
		$faces = [];
		// Language => family, in language order, for the :lang() application rules.
		$apply = [];

		foreach ($languages as $lang) {
			$family = $this->defaultFamily($lang);
			if ($family === null) {
				continue;
			}
			$apply[$lang] = $family;
			if (!isset($faces[$family])) {
				$faces[$family] = $this->faceRules($family, $prefix, $files);
			}
		}

		ksort($faces);
		$css = '';
		foreach ($faces as $block) {
			$css .= $block;
		}
		foreach ($apply as $lang => $family) {
			$css .= $this->applyRule($lang, $family);
		}

		$files = array_values(array_unique($files));
		sort($files);
		return ['css' => $css, 'files' => $files];
	}

	/**
	 * The @font-face rules for $family and its variants, appending each woff2 path to $files.
	 *
	 * @param string $family
	 * @param string $prefix
	 * @param string[] &$files
	 * @return string
	 */
	private function faceRules(string $family, string $prefix, array &$files): string {
		$config = $this->fonts[$family] ?? null;
		if (!is_array($config)) {
			return '';
		}
		$css = $this->face($family, $config, $prefix, $files);
		$variants = is_array($config['variants'] ?? null) ? $config['variants'] : [];
		foreach ($variants as $variantFamily) {
			// A variant is defined under its own family entry (e.g. "Alef Bold") but shares the base
			// family name, so the browser picks it up for bold/italic text of that family.
			if (is_string($variantFamily) && isset($this->fonts[$variantFamily])) {
				$css .= $this->face($family, $this->fonts[$variantFamily], $prefix, $files);
			}
		}
		return $css;
	}

	/**
	 * One @font-face rule declaring $family from a font config entry.
	 *
	 * @param string $family
	 * @param array $config
	 * @param string $prefix
	 * @param string[] &$files
	 * @return string
	 */
	private function face(string $family, array $config, string $prefix, array &$files): string {
		$woff2 = $config['woff2'] ?? null;
		if (!is_string($woff2) || $woff2 === '') {
			return '';
		}
		// The repository appends a "?hash" cache-buster; the file on disk carries none.
		$path = explode('?', $woff2, 2)[0];
		$files[] = $path;

		$weight = isset($config['fontweight']) && is_string($config['fontweight']) ? $config['fontweight'] : 'normal';
		$style = isset($config['fontstyle']) && is_string($config['fontstyle']) ? $config['fontstyle'] : 'normal';
		$name = $this->escape($family);

		return (
			"@font-face{font-family:'$name';"
			. "font-weight:$weight;font-style:$style;font-display:swap;"
			. "src:local('$name'),"
			. "url('"
			. $prefix
			. $path
			. "') format('woff2');}\n"
		);
	}

	/**
	 * The :lang() rule applying $family to text in $lang, with a generic fallback.
	 *
	 * @param string $lang
	 * @param string $family
	 * @return string
	 */
	private function applyRule(string $lang, string $family): string {
		return (
			':lang('
			. $lang
			. '),[lang="'
			. $lang
			. '"]'
			. "{font-family:'"
			. $this->escape($family)
			. "',sans-serif;}\n"
		);
	}

	/**
	 * Escape a font-family name for a single-quoted CSS string.
	 *
	 * @param string $name
	 * @return string
	 */
	private function escape(string $name): string {
		return str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
	}
}
