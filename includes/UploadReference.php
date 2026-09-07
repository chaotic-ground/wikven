<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a page says about a picture, and what the export should say instead.
 *
 * Every picture is copied into the asset directory under a content-addressed name, so every
 * reference moves with it.
 *
 * A page spells the same URL two ways: root-relative in the body, whole in head metadata. A
 * foreign repository answers both whole, so HeadMetadata is asked instead.
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
		// autolink's text as well as the href, so a path admitting them eats the "</a>" after it.
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
	 * No "host" group: every one of these carries one. The query is kept, unlike a stored
	 * picture's, because a thumbnailer answers the URL it was given.
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
	 * @param callable(string):(string|null) $publish Given what the reference names -- what follows
	 *   the upload path, or the whole URL for a hotlink -- the reference the build publishes that
	 *   file under, or null if it could not be published. Asked once per reference; one it cannot
	 *   answer is left as the page wrote it, for the caller to report.
	 */
	public function rewrite(string $html, callable $publish): string {
		$metadata = HeadMetadata::of($html);

		return preg_replace_callback(
			$this->pattern,
			function (array $m) use ($publish, $metadata): string {
				// One name per file, whatever the reference looked like: the pattern leaves out a
				// stored picture's trailing ?query, and the escaping is undone here, so a file
				// written both ways is one path and not two.
				$href = $publish(str_replace('\\/', '/', $m['ref'][0]));
				if ($href === null) {
					return $m[0][0];
				}
				// PREG_OFFSET_CAPTURE gives every match its position, which is the only reason the
				// span check can be asked anything; a group that did not take part is empty here.
				$whole = $this->siteUrl->isKnown() && ( ( $m['host'][0] ?? '' ) !== '' || $metadata->holds($m[0][1]) );
				$out = $whole ? $this->siteUrl->forFile(ltrim($href, './')) : $href;

				return str_contains($m[0][0], '\\/') ? str_replace('/', '\\/', $out) : $out;
			},
			$html,
			flags: PREG_OFFSET_CAPTURE
		);
	}
}
