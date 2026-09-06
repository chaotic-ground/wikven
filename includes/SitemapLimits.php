<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a sitemap that is past the protocol's limits says about itself.
 *
 * One sitemap is capped twice over -- "no more than 50,000 URLs and must be no larger than 50MB
 * (52,428,800 bytes)" -- and a site over either has to split across several files with a sitemap
 * index naming them. Splitting is not built (#626). This is only the sentence a build says about a
 * sitemap it wrote anyway, and it exists because until now only the count was ever asked about.
 *
 * The count is the cap a realistic site reaches first. Measured from the file this project's own
 * documentation bake produces: 74 URLs in 6,180 bytes, 83 bytes each, so 50,000 URLs of that shape
 * would come to about 4.0 MB -- an order of magnitude under the byte cap, and the count itself a
 * factor of roughly 675 away. Reaching 50 MB while still under 50,000 URLs takes about 1,000 bytes
 * per URL, a location around 900 characters; deep subpage titles in a non-Latin script,
 * percent-encoded, could plausibly get there. Such a site wrote an oversized sitemap, had it
 * rejected, and heard nothing at all, because the only cap it passed was the one nothing checked.
 */
class SitemapLimits {
	/** URLs one sitemap may name before it has to be split across several. */
	public const URLS = 50_000;

	/**
	 * Bytes one sitemap may reach, uncompressed.
	 *
	 * The protocol spells the number out -- "50MB (52,428,800 bytes)" -- so the megabyte here is
	 * 1024 * 1024 rather than 1,000,000 and nobody has to guess which was meant. Uncompressed is
	 * the measurement it caps, and it is also the only one there is to take here: the document is
	 * written as plain XML, and whatever serves it compresses it or does not.
	 */
	public const BYTES = 50 * 1024 * 1024;

	/**
	 * What is wrong with a sitemap of this size, or null where it is inside both caps.
	 *
	 * Both caps are weighed into one sentence, so a file over both is a single complaint rather
	 * than two that differ by a clause. The tail is the same either way: the file is written
	 * regardless. That was chosen so a site which grows past a cap still has something on disk and
	 * a message explaining it, rather than a sitemap that silently vanishes at some page count; the
	 * opposite argument -- that a file a crawler rejects is worth nothing, and its presence hides
	 * the problem from anyone not reading build logs -- is for whoever builds splitting to weigh.
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
