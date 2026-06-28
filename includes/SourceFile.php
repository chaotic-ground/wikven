<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

/** Maps a source file to the wiki page it imports as, and back. */
class SourceFile {
	private const MARKER = 'wikitext';

	/** Whether a relative source path is a page to import, not an asset. */
	public static function isPageFile(string $relativePath): bool {
		return str_ends_with($relativePath, '.' . self::MARKER) || self::titleHasOwnContentModel($relativePath);
	}

	/** Source path -> page title: drop the ".wikitext" marker, else keep verbatim. */
	public static function filenameToTitle(string $relativePath): string {
		$suffix = '.' . self::MARKER;
		if (str_ends_with($relativePath, $suffix)) {
			return substr($relativePath, 0, -strlen($suffix));
		}
		return $relativePath;
	}

	/** Page title -> source path: append ".wikitext" unless the title has its own content model. */
	public static function titleToFilename(string $title): string {
		if (self::titleHasOwnContentModel($title)) {
			return $title;
		}
		return $title . '.' . self::MARKER;
	}

	/** Page's source file name, percent-encoded for the $1 in URL templates ('/' and ':' kept). */
	public static function titleToParam(string $titleText): string {
		return strtr(rawurlencode(self::titleToFilename($titleText)), ['%2F' => '/', '%3A' => ':']);
	}

	/** Whether the page was imported from a source file, not generated during the build. */
	public static function exists(string $titleText): bool {
		global $wgWikvenSourceDirectory;
		if ((string)$wgWikvenSourceDirectory === '') {
			return false;
		}
		return is_file(rtrim($wgWikvenSourceDirectory, '/') . '/' . self::titleToFilename($titleText));
	}

	/** Whether the title resolves a non-wikitext default content model (so no marker is needed). */
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
