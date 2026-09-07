<?php

namespace MediaWiki\Extension\Wikven;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

/**
 * Where a built site will be published, and so the one place an absolute URL can come from.
 *
 * A sitemap's <loc>, an hreflang alternate and an og:url must be fully qualified, and nothing else
 * in a build knows where the output is going: onGetLocalURL rewrites every link to a file beside
 * the one asking. So a site says where it publishes or those features stay off; a value that says
 * nothing and one this cannot use both hand back ''.
 *
 * The URL work is Guzzle's, which arrives with core.
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
	 * Only http and https, which is wikven's check rather than the library's: a crawler fetches
	 * neither a mailto: nor an irc:. A query, a fragment and a
	 * password are dropped rather than refused. The trailing slash is settled here because every
	 * caller joins a file name to it.
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
	 * Core keeps the two halves apart -- a path lives in $wgArticlePath -- and a site should not
	 * have to. It writes the whole URL once and this hands core the half it understands.
	 */
	public function canonicalServer(): string {
		return $this->base === '' ? '' : (string)( new Uri($this->base) )->withPath('');
	}

	/**
	 * The absolute URL of a file the build wrote, or '' where the site has not said where it is.
	 *
	 * The name arrives from OutputName already encoded, so it is resolved rather than encoded
	 * again. The "./" matters: under RFC 3986 a first segment holding a colon is a scheme, so
	 * "File:Note_icon.svg.html" resolved bare comes back lower-cased and not the page.
	 */
	public function forFile(string $href): string {
		if ($this->base === '') {
			return '';
		}
		return (string)UriResolver::resolve(new Uri($this->base), new Uri('./' . ltrim($href, '/')));
	}
}
