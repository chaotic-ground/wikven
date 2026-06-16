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
	 * Whether a file under the source directory is a page to import (as opposed
	 * to an asset like an image or the .wikven.yaml config).
	 *
	 * @param string $relativePath Path relative to the source directory.
	 * @return bool
	 */
	public static function isPageFile($relativePath) {
		return str_ends_with($relativePath, '.' . self::MARKER) || self::titleHasOwnContentModel($relativePath);
	}

	/**
	 * Map a source path (relative to the source directory) to its page title:
	 * drop the ".wikitext" marker if present, otherwise keep the path verbatim
	 * because the title carries its own content model. A nested file becomes a
	 * subpage, so "Template:Foo/styles.css" imports as "Template:Foo/styles.css".
	 *
	 * @param string $relativePath
	 * @return string
	 */
	public static function filenameToTitle($relativePath) {
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
	 *
	 * @param string $title
	 * @return string
	 */
	public static function titleToFilename($title) {
		if (self::titleHasOwnContentModel($title)) {
			return $title;
		}
		return $title . '.' . self::MARKER;
	}

	/**
	 * Whether MediaWiki resolves a non-wikitext default content model for the
	 * given title text — i.e. the title (via its extension or namespace) already
	 * determines the content, so no ".wikitext" marker is needed. Driven by the
	 * live content-model registry, so it tracks whatever extensions are loaded.
	 *
	 * @param string $titleText
	 * @return bool
	 */
	private static function titleHasOwnContentModel($titleText) {
		$services = MediaWikiServices::getInstance();
		$title = $services->getTitleFactory()->newFromText($titleText);
		if (!$title) {
			return false;
		}
		$model = $services->getSlotRoleRegistry()->getRoleHandler(SlotRecord::MAIN)->getDefaultModel($title);
		return $model !== CONTENT_MODEL_WIKITEXT;
	}
}
