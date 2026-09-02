<?php

namespace MediaWiki\Extension\Wikven;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

/**
 * Where a built site will be published, and so the one place an absolute URL for a page can come
 * from.
 *
 * A published export needs absolute URLs for things that are about the site rather than in it: a
 * sitemap's <loc>, an hreflang alternate, an og:url. Their formats require them -- the sitemap
 * protocol says a URL "must begin with the protocol", and hreflang alternates "must be
 * fully-qualified" -- so none of them can be written with the relative links the pages themselves
 * carry.
 *
 * Nothing else in a build knows this. The install runs against http://localhost:4000 and
 * Hooks\Main::onGetLocalURL rewrites every local URL to a file beside the one asking, which is what
 * makes an export work from any directory and any host. Both are right, and both mean the build
 * cannot work out where its output is going. A site says so here or the absolute-URL features stay
 * off, which is the honest answer: a sitemap naming pages a crawler cannot resolve is worse than no
 * sitemap.
 *
 * Empty is the default and means exactly that. Nothing about the pages changes either way; only
 * what is written about them.
 *
 * The URL work is Guzzle's. It arrives with MediaWiki -- core requires guzzlehttp/guzzle, which
 * requires guzzlehttp/psr7 -- and includes/AutoLoader.php loads vendor before Setup.php reaches
 * LocalSettings, so this is usable from the settings file that the extension's own autoloader is
 * not yet up for. Hand-rolled parsing was tried and got four cases wrong that a site can plausibly
 * write: a base carrying a query or a fragment had the trailing slash appended after it, a
 * mixed-case host went to a crawler unnormalised as a second origin, and a password in the base
 * was copied into every URL built from it.
 */
class SiteUrl {
	/**
	 * The site's public base, ending in a slash, or '' where the site has not said.
	 *
	 * Trailing slashes are settled here rather than at each use: a base and a file name are joined
	 * by every caller, and "https://example.org/wiki" with "index.html" is a different page from
	 * "https://example.org/wiki/" with the same, in the direction that loses the last path segment.
	 */
	public static function base(): string {
		return self::normalize((string)( $GLOBALS['wgWikvenSiteUrl'] ?? '' ));
	}

	/** Whether the site said where it is published, and so whether an absolute URL can be made. */
	public static function known(): bool {
		return self::base() !== '';
	}

	/**
	 * The scheme and host of the base, for $wgCanonicalServer, or '' where there is no base.
	 *
	 * Core keeps the two halves apart -- $wgCanonicalServer is scheme and host, a path lives in
	 * $wgArticlePath -- and a site should not have to. It writes the whole URL once and this hands
	 * core the half it understands, so what core and any extension build from it is right about the
	 * host even where the path is wikven's to add.
	 */
	public static function canonicalServer(): string {
		$base = self::base();
		return $base === '' ? '' : (string)( new Uri($base) )->withPath('');
	}

	/**
	 * The absolute URL of a file the build wrote, or '' where the site has not said where it is.
	 *
	 * The name arrives from OutputName already encoded for a static server, so it is resolved
	 * rather than encoded again -- encoding it twice is how a percent sign becomes %25.
	 *
	 * The "./" is what makes that resolution mean what it says. Under RFC 3986 a reference whose
	 * first segment holds a colon is a URL with a scheme, and the default file-name spelling keeps
	 * colons: "File:Note_icon.svg.html" is a real name on wikven's own documentation site. Resolved
	 * bare it stays "file:Note_icon.svg.html" -- relative, lower-cased, and silently not the page.
	 */
	public static function forFile(string $href): string {
		$base = self::base();
		if ($base === '') {
			return '';
		}
		return (string)UriResolver::resolve(new Uri($base), new Uri('./' . ltrim($href, '/')));
	}

	/**
	 * A written value read as a base, or '' where it is not one this can use.
	 *
	 * Only http and https are accepted, and that check stays wikven's: a URL library's job is to
	 * parse what it is given, so Guzzle reads a mailto: or an irc: as the valid URLs they are. A
	 * crawler is the reader of everything this produces and fetches neither, so a base in another
	 * scheme would produce a document full of URLs nothing can follow.
	 *
	 * A query, a fragment and a password are dropped rather than refused. Each is a thing a site
	 * can reasonably write into the address bar it copied from, none of them survives being a
	 * directory that file names hang off, and refusing the whole base over one would leave the site
	 * with no sitemap at all.
	 */
	public static function normalize(string $written): string {
		$trimmed = trim($written);
		if ($trimmed === '') {
			return '';
		}
		try {
			$uri = new Uri($trimmed);
		} catch (InvalidArgumentException) {
			return '';
		}
		if (!in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
			return '';
		}
		return (string)$uri->withUserInfo('')
			->withQuery('')
			->withFragment('')
			->withPath(rtrim($uri->getPath(), '/') . '/');
	}
}
