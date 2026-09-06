<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a rendered page says about a picture it shows, and what the export should say instead.
 *
 * A page's pictures come from one of two places, and the export publishes both the same way: the
 * build copies each file into the asset directory under a content-addressed name, and every
 * reference to it has to be moved with it. A file this wiki stored reaches the page as a URL under
 * $wgUploadPath, a directory the export does not publish; a file a foreign repository serves --
 * Wikimedia Commons through InstantCommons -- reaches it as that repository's own URL, which the
 * export must stop depending on. Hence the two named constructors: one pattern each, one answer
 * for both.
 *
 * That much is a rewrite. What makes it a decision is that a page says the same URL in more than
 * one way, and the ways are not interchangeable.
 *
 * A page's body gets a stored picture from File::getUrl(), a path from the site root. Machine-read
 * metadata -- an og:image, a schema.org image -- gets File::getFullUrl(), which is that same URL
 * expanded against $wgServer and so carries a scheme and a host. MediaWiki has already decided
 * which question was asked, and the answer is in the text: a reference that arrived whole is one
 * something will read away from the page, where a path beside the page means nothing. So a whole
 * URL is answered with a whole URL, and a root-relative one with the file beside the page. There is
 * no hook to ask instead -- File::getUrl() is memoised and FileRepo::getZoneUrl() is a configured
 * string -- but there is no need for one, because nothing has thrown the distinction away yet.
 *
 * A foreign repository throws it away. Its files are somewhere else whoever is asking, so both
 * calls answer with the same whole URL and the shape of a hotlink says nothing about who reads it.
 * Answering every one of them whole would drag the body's pictures onto the published host, and
 * answering every one of them beside the page is what left an og:image naming "./assets/img-*.jpg"
 * -- a card no crawler can resolve. What still knows is where in the page the reference sits, so
 * that is asked: HeadMetadata. It is asked of a stored picture too, and only ever agrees with the
 * shape there, which is the point -- the rule is "read away from the page", and the shape was
 * always the proxy for it.
 *
 * The second spelling is the escaping. json_encode() writes a slash as "\/" unless told otherwise,
 * and an extension writing a JSON-LD block calls it plainly, so the same URL reads "\/images\/x.png"
 * there. A pattern that knows only the first spelling walks straight past it and leaves a URL
 * naming a directory, or a host, the export does not serve -- which is how one page could carry a
 * rewritten og:image and a dead schema.org image for the same file.
 */
final class UploadReference {
	/** A slash as a page can spell it: bare in an attribute, backslash-escaped inside JSON. */
	private const SLASH = '(?:\\\\)?/';

	/**
	 * Matches one reference. Group "ref" is what the caller is asked about; the optional group
	 * "host" is the scheme and host, where a pattern has one to tell whole references from
	 * root-relative ones.
	 */
	private readonly string $pattern;

	private readonly SiteUrl $siteUrl;

	private function __construct(string $pattern, SiteUrl $siteUrl) {
		$this->pattern = $pattern;
		$this->siteUrl = $siteUrl;
	}

	/**
	 * References to pictures this wiki stored, which the page names under the upload path.
	 *
	 * @param string $uploadPath $wgUploadPath, the path a stored picture's URL hangs off.
	 * @param SiteUrl $siteUrl Where the export is published, if the site has said.
	 */
	public static function stored(string $uploadPath, SiteUrl $siteUrl): self {
		// Neither the host nor the path may hold "<" or ">". {{filepath:}} writes its URL into an
		// autolink's text as well as into the href, and nothing quotes the text, so a path that
		// admits them eats the "</a>" after it and the build aborts over a file nobody can find.
		$slash = self::SLASH;
		$host = '(?<host>(?:https?:)?' . $slash . $slash . '[^/\s"\\\\<>]+)?';
		$upload = str_replace('/', $slash, preg_quote($uploadPath, '~'));
		$path = '(?<ref>(?:' . $slash . '[^\s"?<>\\\\]+)+)';
		$query = '(?:\?[^\s"<>]*)?';

		return new self("~$host$upload$path$query~", $siteUrl);
	}

	/**
	 * References to pictures a foreign repository serves, which the page names at that host.
	 *
	 * There is no "host" group: every one of these carries a scheme and a host, so a group holding
	 * it would say "whole" about the body's pictures as loudly as about the head's, and mean
	 * nothing. Where they are read is decided by where they sit instead.
	 *
	 * The query is part of the reference rather than trimmed off it, unlike a stored picture's: a
	 * repository's thumbnailer answers the URL it was given, and what a page asked for is the only
	 * description of the file the export has.
	 *
	 * @param string $host The repository's file host, e.g. "upload.wikimedia.org".
	 * @param SiteUrl $siteUrl Where the export is published, if the site has said.
	 */
	public static function hotlinked(string $host, SiteUrl $siteUrl): self {
		$slash = self::SLASH;
		$ref = '(?<ref>(?:https?:)?' . $slash . $slash . preg_quote($host, '~') . '(?:' . $slash . '[^\s"<>\\\\]+)+)';

		return new self("~$ref~", $siteUrl);
	}

	/**
	 * Point every reference in $html at the file the build published instead.
	 *
	 * @param string $html A rendered page.
	 * @param callable(string):(string|null) $publish Given what the reference names -- for a stored
	 *   picture what follows the upload path ("/Card.png", "/thumb/Card.png/100px-Card.png"), for a
	 *   hotlinked one the whole URL -- the reference the build publishes that file under
	 *   ("./assets/img-*.ext"), or null if it could not be published. Asked once per reference
	 *   rather than per occurrence, so the caller that reads and copies is the one that remembers;
	 *   a reference it cannot answer is left as the page wrote it, for it to report.
	 */
	public function rewrite(string $html, callable $publish): string {
		$metadata = HeadMetadata::of($html);

		return preg_replace_callback(
			$this->pattern,
			function (array $m) use ($publish, $metadata): string {
				// One name per file, whatever the reference looked like: a stored picture's
				// trailing ?query is left out by the pattern, so a page's several sizes and cache
				// stamps of one picture are one question, and the escaping is undone here, so a
				// file written both ways is one path and not two.
				$href = $publish(str_replace('\\/', '/', $m['ref'][0]));
				if ($href === null) {
					return $m[0][0];
				}
				// PREG_OFFSET_CAPTURE gives every match its position, which is the only reason the
				// span check above can be asked anything; a group the pattern does not have, or one
				// that did not take part, is absent or empty here.
				$whole = $this->siteUrl->isKnown() && ( ( $m['host'][0] ?? '' ) !== '' || $metadata->holds($m[0][1]) );
				$out = $whole ? $this->siteUrl->forFile(ltrim($href, './')) : $href;

				return str_contains($m[0][0], '\\/') ? str_replace('/', '\\/', $out) : $out;
			},
			$html,
			flags: PREG_OFFSET_CAPTURE
		);
	}
}
