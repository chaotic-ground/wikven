<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a page is called in the output, and what a link has to say to reach it.
 *
 * The two disagreed: the cache named "Vector_%28skin%29.html" while links said
 * "Vector_(skin).html", and a build that exited 0 gave a 404. A static server url-decodes the
 * path it asks for, so a link is not an escaped name but href(name).
 */
class OutputName {
	/**
	 * Names as the titles are written: "Vector_(skin).html", "File:Bakery_oven.jpg.html".
	 *
	 * The prettiest urls, and what this documentation site is published under. Windows cannot hold
	 * a colon in a file name, so such a site wants ENCODED instead.
	 */
	public const READABLE = 'readable';

	/**
	 * Names with every escape the file cache made left in place: "File%3ABakery_oven.jpg.html".
	 *
	 * Nothing but letters, digits, "%", ".", "-", "_" and a subpage "/", so any filesystem can
	 * hold it. The cost is in the urls, which carry that "%" doubled:
	 * "./File%253ABakery_oven.jpg.html".
	 */
	public const ENCODED = 'encoded';

	/**
	 * The escapes a readable name keeps: " * ? \ -- and only those.
	 *
	 * The characters $wgLegalTitleChars allows that a Windows path cannot, other than ":", which
	 * ENCODED escapes, and "/", which becomes a real directory either way.
	 */
	private const KEPT = ['%22', '%2A', '%3F', '%5C'];

	/**
	 * Both spellings, as a site writes them.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return [self::READABLE, self::ENCODED];
	}

	/**
	 * The spelling this build writes, falling back to readable.
	 *
	 * An unrecognised value reads as readable, which is what a site that said nothing would have
	 * got; SiteConfig::lint() has already named it by then.
	 */
	public static function current(): string {
		$configured = $GLOBALS['wgWikvenFileNames'] ?? self::READABLE;
		return in_array($configured, self::all(), true) ? (string)$configured : self::READABLE;
	}

	/**
	 * The file a title is written to, relative to the output root, ".html" included.
	 *
	 * @param string $namespaceText The title's namespace in the content language, '' for the main one.
	 * @param string $dbkey The title's database key, underscores and all.
	 * @param ?string $scheme One of all(); the site's own by default.
	 */
	public static function of(string $namespaceText, string $dbkey, ?string $scheme = null): string {
		// urlencode is the file cache's own escaping, so this asks the same question rename.php
		// answers from the other side. The ".html" is the cache's too, so both directions hand
		// back a whole file name.
		return self::assemble($namespaceText, urlencode($dbkey), $scheme ?? self::current()) . '.html';
	}

	/**
	 * The same file, worked out from the name the file cache gave it.
	 *
	 * rename.php reads the cache directory with no titles to hand. A name with no "ns<N>%3A" was
	 * not written by the cache and is left as it is.
	 *
	 * @param string $cacheName A base name, e.g. "ns6%3ABakery_oven%2Ejpg.html".
	 * @param callable(int):string $namespaceText Namespace number to its text in the content language.
	 * @param ?string $scheme One of all(); the site's own by default.
	 */
	public static function fromCache(string $cacheName, callable $namespaceText, ?string $scheme = null): string {
		if (!preg_match('/^ns(\d+)%3A/', $cacheName, $matches)) {
			return $cacheName;
		}
		return self::assemble(
			$namespaceText((int)$matches[1]),
			substr($cacheName, strlen($matches[0])),
			$scheme ?? self::current()
		);
	}

	/**
	 * The link that reaches a file, which is that file's name url-encoded.
	 *
	 * Only "%", "?" and "#" are escaped, and "%" first: its own "%25" is not escaped again, so a
	 * kept "%3F" comes out as "%253F".
	 */
	public static function href(string $file): string {
		return str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $file);
	}

	/**
	 * The file a link reaches: href() read backwards.
	 *
	 * "%3F" and "%23" are undone before "%25", so a "%253F" -- a link to a file whose own name has
	 * "%3F" in it -- comes back as "%3F" rather than as "?".
	 */
	public static function file(string $href): string {
		return str_replace('%25', '%', str_replace(['%3F', '%23'], ['?', '#'], $href));
	}

	/**
	 * A namespace and an already-escaped body, in the site's spelling.
	 *
	 * The namespace arrives as the content language spells it and the body as the cache left it,
	 * so both go through one spelling rather than coming out half escaped.
	 */
	private static function assemble(string $namespaceText, string $body, string $scheme): string {
		$body = self::spell($body, $scheme);
		if ($namespaceText === '') {
			return $body;
		}
		$separator = $scheme === self::ENCODED ? '%3A' : ':';
		return self::spell(urlencode($namespaceText), $scheme) . $separator . $body;
	}

	/**
	 * One escaped string, written as the site spells it.
	 *
	 * Two escapes are undone either way: "%2E", without which a name cannot end in ".html", and
	 * "%2F", the subpage separator, exported as a real directory.
	 */
	private static function spell(string $escaped, string $scheme): string {
		$escaped = str_replace(['%2E', '%2F'], ['.', '/'], $escaped);
		return $scheme === self::ENCODED ? $escaped : self::readable($escaped);
	}

	/** Every escape undone but the ones a Windows path could not hold; see KEPT. */
	private static function readable(string $body): string {
		return preg_replace_callback(
			'/%([0-9A-Fa-f]{2})/',
			static function (array $escape): string {
				$upper = '%' . strtoupper($escape[1]);
				// Byte by byte, which is how the escaping was done: a multi-byte character comes
				// back a byte at a time and reassembles itself in the string being built.
				return in_array($upper, self::KEPT, true) ? $upper : chr((int)hexdec($escape[1]));
			},
			$body
		);
	}
}
