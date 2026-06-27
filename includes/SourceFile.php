<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

/**
 * The mapping between a source file and the wiki page it imports as.
 *
 * A page's content model is derived from its title, so any title MediaWiki
 * gives a non-wikitext model — MediaWiki:Common.css (sanitized-css),
 * MediaWiki:Common.js (javascript), a .json config page, a Module: Scribunto
 * page, a Template /styles.css page, or whatever a loaded extension registers
 * (mustache, ...) — *is* the page under its own name. The file keeps that name,
 * so editors highlight and lint it natively and the title round-trips exactly.
 * Every other page has no content model of its own, so it gets a ".wikitext"
 * marker that both flags the file as a page to import and is stripped to recover
 * the title. Files MediaWiki would treat as plain wikitext under their own name
 * (logo.png, Bakery oven.jpg, .wikven.yaml) are assets, not pages.
 *
 * The non-wikitext set is never hard-coded here: it is whatever the running
 * wiki's content-model registry and ContentHandlerDefaultModelFor hooks resolve,
 * so loading an extension that adds a model is enough to make its files import.
 *
 * filenameToTitle() and titleToFilename() are inverses, so the same convention
 * drives both the importer (file -> page) and the edit/history links the static
 * export points back at the source (page -> file).
 */
class SourceFile {
	private const MARKER = 'wikitext';

	/**
	 * Whether a file under the source directory (given by its path relative to
	 * it) is a page to import, as opposed to an asset like an image or the
	 * .wikven.yaml config.
	 */
	public static function isPageFile(string $relativePath): bool {
		return str_ends_with($relativePath, '.' . self::MARKER) || self::titleHasOwnContentModel($relativePath);
	}

	/**
	 * Map a source path (relative to the source directory) to its page title:
	 * drop the ".wikitext" marker if present, otherwise keep the path verbatim
	 * because the title carries its own content model. A nested file becomes a
	 * subpage, so "Template:Foo/styles.css" imports as "Template:Foo/styles.css".
	 */
	public static function filenameToTitle(string $relativePath): string {
		$suffix = '.' . self::MARKER;
		if (str_ends_with($relativePath, $suffix)) {
			return substr($relativePath, 0, -strlen($suffix));
		}
		return $relativePath;
	}

	/**
	 * Map a page title back to its source path (the inverse of
	 * filenameToTitle()): keep the title verbatim when it carries its own
	 * content model, otherwise append the ".wikitext" marker.
	 */
	public static function titleToFilename(string $title): string {
		if (self::titleHasOwnContentModel($title)) {
			return $title;
		}
		return $title . '.' . self::MARKER;
	}

	/**
	 * The source file name of a page, percent-encoded for use as the $1 in the
	 * edit/history/view-source URL templates. Built from the prefixed title text
	 * (spaces, not the DB key's underscores) so it matches the on-disk file name,
	 * then encoded so characters legal in a title but unsafe in a URL path
	 * (spaces, '#', '?', '%', non-ASCII) cannot break or truncate the link. The
	 * subpage separator '/' and the namespace separator ':' are kept readable.
	 */
	public static function titleToParam(string $titleText): string {
		return strtr(rawurlencode(self::titleToFilename($titleText)), ['%2F' => '/', '%3A' => ':']);
	}

	/**
	 * Whether the page with the given prefixed title text was imported from a
	 * source file, as opposed to generated during the build (like the Version
	 * page). The edit/history/view-source links point at that source file, so
	 * there is nothing for them to point at when it does not exist.
	 */
	public static function exists(string $titleText): bool {
		global $wgWikvenSourceDirectory;
		if ((string)$wgWikvenSourceDirectory === '') {
			return false;
		}
		return is_file(rtrim($wgWikvenSourceDirectory, '/') . '/' . self::titleToFilename($titleText));
	}

	/**
	 * Whether MediaWiki resolves a non-wikitext default content model for the
	 * given title text — i.e. the title (via its extension or namespace) already
	 * determines the content, so no ".wikitext" marker is needed. Driven by the
	 * live content-model registry, so it tracks whatever extensions are loaded.
	 */
	private static function titleHasOwnContentModel(string $titleText): bool {
		$services = MediaWikiServices::getInstance();
		$title = $services->getTitleFactory()->newFromText($titleText);
		if (!$title) {
			return false;
		}
		$model = $services->getSlotRoleRegistry()->getRoleHandler(SlotRecord::MAIN)->getDefaultModel($title);
		return $model !== CONTENT_MODEL_WIKITEXT;
	}
}
