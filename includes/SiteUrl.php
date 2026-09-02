<?php

namespace MediaWiki\Extension\Wikven;

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
		if ($base === '') {
			return '';
		}
		$parts = parse_url($base);
		if (!isset($parts['scheme']) || !isset($parts['host'])) {
			return '';
		}
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		return $parts['scheme'] . '://' . $parts['host'] . $port;
	}

	/**
	 * The absolute URL of a file the build wrote, or '' where the site has not said where it is.
	 *
	 * The name is the one OutputName settled, already url-encoded for a static server; it is joined
	 * rather than re-encoded, because encoding it twice is how a percent sign becomes %25.
	 */
	public static function forFile(string $href): string {
		$base = self::base();
		return $base === '' ? '' : $base . ltrim($href, '/');
	}

	/**
	 * A written value read as a base, or '' where it is not one this can use.
	 *
	 * Only http and https are accepted. A crawler is the reader of everything this produces, and it
	 * fetches neither a file:// nor a mailto:, so a base in another scheme would produce a document
	 * full of URLs nothing can follow.
	 */
	public static function normalize(string $written): string {
		$trimmed = trim($written);
		if ($trimmed === '') {
			return '';
		}
		$parts = parse_url($trimmed);
		if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
			return '';
		}
		if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			return '';
		}
		return rtrim($trimmed, '/') . '/';
	}
}
