<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Where a stylesheet the build dumped ends up: the href a page links it by, and the file it is.
 *
 * $wgWikvenStyleDirectory puts the dumped CSS anywhere under the output root, so a step that links
 * one of those files has to answer the same question the step that wrote it answered. Deriving the
 * link and the path together is what keeps the two answers from parting: a link written for one
 * directory while the file is looked for in another leaves the page with no stylesheet at all, and
 * nothing anywhere to say so.
 */
class StyleFile {
	/**
	 * @param string $htmlDirectory The output directory, as $wgWikvenHtmlDirectory gives it.
	 * @param string $styleDirectory The style directory under it, as $wgWikvenStyleDirectory gives it.
	 * @param string $name The stylesheet's file name, e.g. "site.styles.css".
	 * @return array{href: string, path: string} The href to link the stylesheet by, relative to the
	 *   output root as everything wikven writes is, and the path it lives at on disk.
	 */
	public static function locate(string $htmlDirectory, string $styleDirectory, string $name): array {
		$directory = trim($styleDirectory, '/');
		// Both spellings of the output directory itself; neither is a path segment of its own.
		$atRoot = $directory === '' || $directory === '.';
		$htmlDirectory = rtrim($htmlDirectory, '/');
		return [
			'href' => $atRoot ? "./$name" : "./$directory/$name",
			'path' => $atRoot ? "$htmlDirectory/$name" : "$htmlDirectory/$directory/$name"
		];
	}
}
