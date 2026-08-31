<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a page is called in the output, and what a link has to say to reach it.
 *
 * These are two questions and they had two answers, which is how they came to disagree. The build
 * writes each page through MediaWiki's file cache, which names a file by url-encoding "ns<N>:<dbkey>"
 * and escaping the dots; rename.php then puts a readable namespace back and restores the dots and
 * the subpage slashes. Every link, meanwhile, was written from the title as MediaWiki spells it. For
 * a title made of letters the two agree by luck. For "Vector (skin)" one said Vector_%28skin%29.html
 * and the other said Vector_(skin).html, and the reader got a 404 out of a build that exited 0.
 *
 * The 404 is not the whole of it. A static server url-decodes the path it is asked for, so a link
 * saying "Vector_%28skin%29.html" asks for the file "Vector_(skin).html" -- the percent-escapes in a
 * link are not a way of naming a file that has percent signs in it. A file really called
 * "Vector_%28skin%29.html" is reachable only at "Vector_%2528skin%2529.html". So a name and a link
 * cannot simply be made to match by escaping the link; the link is the *url-encoding of the name*,
 * and that is the relationship this class keeps:
 *
 *     file  = the page's name on disk, in whichever spelling the site asked for
 *     href  = href(file), which is the only string that reaches it
 *
 * Everything that writes a link calls href(); rename.php and the passes that read the output call
 * the name side. One rule, asked in two directions, instead of two rules that agreed by accident.
 */
class OutputName {
	/**
	 * Names as the titles are written: "Vector_(skin).html", "File:Bakery_oven.jpg.html".
	 *
	 * The prettiest urls, and what this documentation site is published under. Windows cannot hold
	 * a file whose name has a colon in it, so a site whose output directory is a Windows filesystem
	 * -- a bind mount from Docker Desktop, say -- cannot write a page outside the main namespace
	 * this way, and wants ENCODED instead.
	 */
	public const READABLE = 'readable';

	/**
	 * Names with every escape the file cache made left in place: "File%3ABakery_oven.jpg.html".
	 *
	 * Nothing but letters, digits, "%", ".", "-", "_" and the "/" of a subpage, so any filesystem
	 * can hold it. The cost is in the urls, which carry that "%" doubled: the link to that page
	 * reads "./File%253ABakery_oven.jpg.html", for the reason the class comment gives.
	 */
	public const ENCODED = 'encoded';

	/**
	 * The escapes a readable name keeps: " * ? \ -- and only those.
	 *
	 * These are the characters MediaWiki lets a title carry ($wgLegalTitleChars) that a Windows
	 * path cannot, other than the two that are answered elsewhere: ":", which READABLE keeps and
	 * ENCODED exists to escape, and "/", which is a subpage separator and becomes a real directory
	 * either way. Keeping them escaped costs nothing legible -- a page called "What?" is rare, and
	 * "What%3F.html" is still a name a person can read -- and it saves the sites that never have
	 * one from needing ENCODED at all.
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
	 * got; SiteConfig::lint() has already named it by then. Read from the global rather than
	 * injected config, as BuildFor beside it is.
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
		// answers from the other side and the two cannot drift apart. The extension is the cache's
		// too -- HTMLFileCache writes .html -- and is added here so both directions hand back a
		// whole file name rather than one of them expecting a caller to finish it.
		return self::assemble($namespaceText, urlencode($dbkey), $scheme ?? self::current()) . '.html';
	}

	/**
	 * The same file, worked out from the name the file cache gave it.
	 *
	 * rename.php reads the cache directory and has no titles to hand, only "ns6%3ABakery_oven%2Ejpg.html";
	 * fillMinervaMenu.php walks the same directory before that pass has run. A name with no "ns<N>%3A"
	 * prefix was not written by the cache -- it is left exactly as it is.
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
	 * Only three characters are escaped, because only three cannot stand in the path of a url: "%",
	 * which would otherwise be read as the start of an escape and turn the name into a different
	 * one; "?", which would start a query string; and "#", which would start a fragment. Everything
	 * else a name here can hold -- "(", "&", "+", ":", a Korean syllable -- is a character a path
	 * may carry as it is, and escaping it would only make the url harder to read.
	 *
	 * "%" is escaped first and its own "%25" is not escaped again, which is what makes a kept "%3F"
	 * come out as "%253F" and reach the file that is really called "What%3F.html".
	 */
	public static function href(string $file): string {
		return str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $file);
	}

	/**
	 * The file a link reaches: href() read backwards.
	 *
	 * The passes that resolve links have to look for the file a link names, and a link is not that
	 * name. "%3F" and "%23" are undone before "%25" so that a "%253F" -- a link to a file whose own
	 * name has "%3F" in it -- comes back as "%3F" rather than as "?".
	 */
	public static function file(string $href): string {
		return str_replace('%25', '%', str_replace(['%3F', '%23'], ['?', '#'], $href));
	}

	/**
	 * A namespace and an already-escaped body, in the site's spelling.
	 *
	 * The namespace is escaped here rather than handed in escaped, because it arrives as the
	 * content language spells it and the body arrives as the file cache left it. Both then go
	 * through one spelling, which is what keeps a name in a namespace whose own text is not plain
	 * letters -- "도움말", "MediaWiki・トーク" -- from coming out half escaped and half not under
	 * ENCODED, where the body's own bytes are escaped and the namespace's were not.
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
	 * Two escapes are undone in both spellings. "%2E" is the file cache's own doing -- it escapes
	 * every dot to keep an extension from being mistaken for the file's -- and a name with no dot in
	 * it cannot end in ".html". "%2F" is the subpage separator, and a subpage is exported into a
	 * real directory, so it has to be a real slash before anything counts the depth. A readable name
	 * then gives up the rest of its escaping too, but for KEPT.
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
