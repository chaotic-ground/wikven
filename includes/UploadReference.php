<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a rendered page says about a picture MediaWiki stored, and what the export should say instead.
 *
 * Every local picture reaches a page as a URL under $wgUploadPath, a directory the export does not
 * publish: the build copies each file into the asset directory under a content-addressed name, and
 * every reference to it has to be moved with it. That much is one rewrite. What makes it two is
 * that a page says the same URL in more than one way, and the ways are not interchangeable.
 *
 * A page's body gets its pictures from File::getUrl(), a path from the site root. Machine-read
 * metadata -- an og:image, a schema.org image -- gets File::getFullUrl(), which is that same URL
 * expanded against $wgServer and so carries a scheme and a host. MediaWiki has already decided
 * which question was asked, and the answer is in the text: a reference that arrived whole is one
 * something will read away from the page, where a path beside the page means nothing. So a whole
 * URL is answered with a whole URL, and a root-relative one with the file beside the page. There is
 * no hook to ask instead -- File::getUrl() is memoised and FileRepo::getZoneUrl() is a configured
 * string -- but there is no need for one, because nothing has thrown the distinction away yet.
 *
 * The second spelling is the escaping. json_encode() writes a slash as "\/" unless told otherwise,
 * and an extension writing a JSON-LD block calls it plainly, so the same URL reads "\/images\/x.png"
 * there. A pattern that knows only the first spelling walks straight past it and leaves a URL
 * naming a directory the export does not serve -- which is how one page could carry a rewritten
 * og:image and a dead schema.org image for the same file.
 */
final class UploadReference {
	/** A slash as a page can spell it: bare in an attribute, backslash-escaped inside JSON. */
	private const SLASH = '(?:\\\\)?/';

	/** Matches an upload-path reference; group 1 the scheme and host, group 2 the storage path. */
	private readonly string $pattern;

	private readonly SiteUrl $siteUrl;

	/**
	 * @param string $uploadPath $wgUploadPath, the path a stored picture's URL hangs off.
	 * @param SiteUrl $siteUrl Where the export is published, if the site has said.
	 */
	public function __construct(string $uploadPath, SiteUrl $siteUrl) {
		// Neither the host nor the path may hold "<" or ">". {{filepath:}} writes its URL into an
		// autolink's text as well as into the href, and nothing quotes the text, so a path that
		// admits them eats the "</a>" after it and the build aborts over a file nobody can find.
		$slash = self::SLASH;
		$host = '((?:https?:)?' . $slash . $slash . '[^/\s"\\\\<>]+)?';
		$upload = str_replace('/', $slash, preg_quote($uploadPath, '~'));
		$path = '((?:' . $slash . '[^\s"?<>\\\\]+)+)';
		$query = '(?:\?[^\s"<>]*)?';

		$this->pattern = "~$host$upload$path$query~";
		$this->siteUrl = $siteUrl;
	}

	/**
	 * Point every upload-path reference in $html at the file the build published instead.
	 *
	 * @param string $html A rendered page.
	 * @param callable(string):(string|null) $publish Given what a reference names after the upload
	 *   path ("/Card.png", "/thumb/Card.png/100px-Card.png"), the reference the build publishes
	 *   that file under ("./assets/img-*.ext"), or null if it could not be published. Asked once
	 *   per reference rather than per file, so the caller that reads and copies is the one that
	 *   remembers; a reference it cannot answer is left as the page wrote it, for it to report.
	 */
	public function rewrite(string $html, callable $publish): string {
		return preg_replace_callback(
			$this->pattern,
			function (array $m) use ($publish): string {
				// One name per file, whatever the reference looked like: the trailing ?query is left
				// out, so a page's several sizes and cache stamps of one picture are one question,
				// and the escaping is undone, so a file written both ways is one path and not two.
				$href = $publish(str_replace('\\/', '/', $m[2]));
				if ($href === null) {
					return $m[0];
				}
				$whole = $m[1] !== '' && $this->siteUrl->isKnown();
				$out = $whole ? $this->siteUrl->forFile(ltrim($href, './')) : $href;
				return str_contains($m[0], '\\/') ? str_replace('/', '\\/', $out) : $out;
			},
			$html
		);
	}
}
