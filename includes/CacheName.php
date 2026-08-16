<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Language\Language;

/**
 * The output path a rebuildFileCache file ends up at.
 *
 * The cache names a page "ns<N>%3A<Title>.html" and escapes the dots and slashes in the title;
 * rename.php turns that into the file the site is served from. Anything writing a link before that
 * pass runs has to name the destination as rename.php will leave it, not as it finds it.
 */
class CacheName {
	public static function toOutputPath(string $basename, Language $contentLanguage): string {
		if (preg_match('/^ns(\d+)%3A/', $basename, $matches)) {
			$text = $contentLanguage->getNsText((int)$matches[1]);
			$basename = ltrim(preg_replace("/^ns$matches[1]%3A/", "$text:", $basename), ':');
		}
		return str_replace(['%2E', '%2F'], ['.', '/'], $basename);
	}
}
