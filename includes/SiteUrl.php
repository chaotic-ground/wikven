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
 * cannot work out where its output is going. A site says so or the absolute-URL features stay off,
 * which is the honest answer: a sitemap naming pages a crawler cannot resolve is worse than no
 * sitemap.
 *
 * A value that says nothing, and one this cannot use, are the same thing here: both give an
 * instance that knows no base and hands back '' from everything. Nothing about the pages changes
 * either way; only what is written about them.
 *
 * Where the value comes from is the caller's business. Reading $wgWikvenSiteUrl is one line in
 * WikvenSettings.php, and keeping it there is what lets this be built from a written string in a
 * test, from configuration in a maintenance script, and from the settings file before MediaWiki has
 * services to ask.
 *
 * The URL work is Guzzle's. It arrives with MediaWiki -- core requires guzzlehttp/guzzle, which
 * requires guzzlehttp/psr7 -- and includes/AutoLoader.php loads vendor before Setup.php reaches
 * LocalSettings, so it is there for the settings file that the extension's own autoloader is not
 * yet up for. Hand-rolled parsing was tried and got four cases wrong that a site can plausibly
 * write: a base carrying a query or a fragment had the trailing slash appended after it, a
 * mixed-case host went to a crawler unnormalised as a second origin, and a password in the base was
 * copied into every URL built from it.
 */
final class SiteUrl {
	/** The published base, ending in a slash, or '' where the site has not said. */
	private readonly string $base;

	// Written out rather than promoted: a promoted property leaves the constructor body empty, and
	// mago writes an empty body as "{}" where phpcs wants the closing brace on a line of its own.
	private function __construct(string $base) {
		$this->base = $base;
	}

	/**
	 * A written value read as a base, usable or not.
	 *
	 * Only http and https are accepted, and that check is wikven's rather than the library's: a URL
	 * parser's job is to read what it is given, so Guzzle reads a mailto: or an irc: as the valid
	 * URL it is. A crawler is the reader of everything built here and fetches neither, so a base in
	 * another scheme would produce a document full of URLs nothing can follow.
	 *
	 * A query, a fragment and a password are dropped rather than refused. Each is a thing a site can
	 * reasonably write into the address bar it copied from, none of them survives being a directory
	 * that file names hang off, and refusing the whole base over one would leave the site with no
	 * sitemap at all.
	 *
	 * The trailing slash is settled here rather than at each use: a base and a file name are joined
	 * by every caller, and "https://example.org/wiki" with "index.html" is a different page from
	 * "https://example.org/wiki/" with the same, in the direction that loses the last path segment.
	 */
	public static function fromWritten(string $written): self {
		$trimmed = trim($written);
		if ($trimmed === '') {
			return new self('');
		}
		try {
			$uri = new Uri($trimmed);
		} catch (InvalidArgumentException) {
			return new self('');
		}
		if (!in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
			return new self('');
		}
		return new self(
			(string)$uri->withUserInfo('')
				->withQuery('')
				->withFragment('')
				->withPath(rtrim($uri->getPath(), '/') . '/')
		);
	}

	/** Whether the site said where it is published, and so whether an absolute URL can be made. */
	public function isKnown(): bool {
		return $this->base !== '';
	}

	/** The site's public base, ending in a slash, or '' where the site has not said. */
	public function base(): string {
		return $this->base;
	}

	/**
	 * The scheme and host of the base, for $wgCanonicalServer, or '' where there is no base.
	 *
	 * Core keeps the two halves apart -- $wgCanonicalServer is scheme and host, a path lives in
	 * $wgArticlePath -- and a site should not have to. It writes the whole URL once and this hands
	 * core the half it understands, so what core and any extension build from it is right about the
	 * host even where the path is wikven's to add.
	 */
	public function canonicalServer(): string {
		return $this->base === '' ? '' : (string)( new Uri($this->base) )->withPath('');
	}

	/**
	 * The absolute URL of a file the build wrote, or '' where the site has not said where it is.
	 *
	 * The name arrives from OutputName already encoded for a static server, so it is resolved rather
	 * than encoded again -- encoding it twice is how a percent sign becomes %25.
	 *
	 * The "./" is what makes that resolution mean what it says. Under RFC 3986 a reference whose
	 * first segment holds a colon is a URL with a scheme, and the default file-name spelling keeps
	 * colons: "File:Note_icon.svg.html" is a real name on wikven's own documentation site. Resolved
	 * bare it stays "file:Note_icon.svg.html" -- relative, lower-cased, and silently not the page.
	 */
	public function forFile(string $href): string {
		if ($this->base === '') {
			return '';
		}
		return (string)UriResolver::resolve(new Uri($this->base), new Uri('./' . ltrim($href, '/')));
	}
}
