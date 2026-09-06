<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a sitemap that is past the protocol's limits says about itself.
 *
 * One sitemap is capped twice over -- 50,000 URLs and 50MB -- and a site over either has to split
 * across several files. Splitting is not built (#626); this is only the sentence a build says
 * about a sitemap it wrote anyway.
 *
 * The count is the cap a realistic site reaches first: this project's own bake writes 83 bytes a
 * URL, so 50,000 of them come to about 4 MB. Reaching 50 MB first takes locations around 900
 * characters.
 */
class SitemapLimits {
	/** URLs one sitemap may name before it has to be split across several. */
	public const URLS = 50_000;

	/**
	 * Bytes one sitemap may reach, uncompressed.
	 *
	 * The protocol spells the number out -- "50MB (52,428,800 bytes)" -- so the megabyte here is
	 * 1024 * 1024. Uncompressed is what it caps and the only measurement there is: the document is
	 * plain XML, and whatever serves it compresses it or does not.
	 */
	public const BYTES = 50 * 1024 * 1024;

	/**
	 * What is wrong with a sitemap of this size, or null where it is inside both caps.
	 *
	 * Both caps are weighed into one sentence, so a file over both is a single complaint. The file
	 * is written either way, so a site that grows past a cap still has something on disk and a
	 * message explaining it.
	 *
	 * @param string $file What was written, so the complaint names the thing to go and look at.
	 * @param int $urls How many pages it names.
	 * @param int $bytes How long the document is, uncompressed.
	 * @return string|null Null where the sitemap is within both caps, which is every site so far.
	 */
	public static function exceeded(string $file, int $urls, int $bytes): ?string {
		$past = [];
		if ($urls > self::URLS) {
			$past[] = sprintf('names %d pages, over the %d a single sitemap may hold', $urls, self::URLS);
		}
		if ($bytes > self::BYTES) {
			$past[] = sprintf('is %d bytes, over the %d a single sitemap may be', $bytes, self::BYTES);
		}
		if ($past === []) {
			return null;
		}
		return sprintf(
			'Wikven: %s %s. It is written anyway and a crawler will reject it; splitting it is not built yet.',
			$file,
			implode(' and ', $past)
		);
	}
}
