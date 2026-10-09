<?php

namespace MediaWiki\Extension\Wikven\Source;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

/**
 * Maps a source file to the wiki page it imports as, and back.
 *
 * A page outside the main namespace lives in a directory named for its namespace:
 * "Template/note.wikitext" is "Template:note". The older spelling, "Template:note.wikitext", is
 * still read, but a filesystem without ":" in a name (NTFS) cannot hold it.
 */
class SourceFile {
	private const MARKER = 'wikitext';

	/** Whether a relative source path is a page to import, not an asset. */
	public static function isPageFile(string $relativePath): bool {
		return (
			str_ends_with($relativePath, '.' . self::MARKER)
			|| self::titleHasOwnContentModel(self::filenameToTitle($relativePath))
		);
	}

	/**
	 * Source path -> page title: drop the ".wikitext" marker, else keep verbatim, and read a leading
	 * directory named for a namespace as that namespace.
	 */
	public static function filenameToTitle(string $relativePath): string {
		$suffix = '.' . self::MARKER;
		if (str_ends_with($relativePath, $suffix)) {
			$relativePath = substr($relativePath, 0, -strlen($suffix));
		}
		$namespace = self::namespaceDirectory($relativePath);
		if ($namespace === null) {
			return $relativePath;
		}
		return $namespace . ':' . substr($relativePath, strlen($namespace) + 1);
	}

	/**
	 * Page title -> source path: append ".wikitext" unless the title has its own content model.
	 *
	 * Outside the main namespace, the colon spelling only where the source holds it and not the other.
	 */
	public static function titleToFilename(string $title): string {
		$filename = self::titleHasOwnContentModel($title) ? $title : $title . '.' . self::MARKER;
		$parsed = MediaWikiServices::getInstance()->getTitleFactory()->newFromText($title);
		if (!$parsed || $parsed->getNamespace() === NS_MAIN) {
			return $filename;
		}
		$namespace = MediaWikiServices::getInstance()->getNamespaceInfo()->getCanonicalName($parsed->getNamespace());
		if ($namespace === false) {
			return $filename;
		}
		$inDirectory = str_replace('_', ' ', $namespace) . '/' . substr($filename, strpos($filename, ':') + 1);
		$dir = self::sourceDirectory();
		if ($dir !== null && !is_file("$dir/$inDirectory") && is_file("$dir/$filename")) {
			return $filename;
		}
		return $inDirectory;
	}

	/**
	 * The namespace a path's leading directory names, or null when it names none.
	 *
	 * Only the canonical name, spelled as a title spells it: "Template/", "User talk/".
	 */
	public static function namespaceDirectory(string $relativePath): ?string {
		$slash = strpos($relativePath, '/');
		if ($slash === false || $slash === 0 || $slash === ( strlen($relativePath) - 1 )) {
			return null;
		}
		$directory = substr($relativePath, 0, $slash);
		$namespaceInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
		$index = $namespaceInfo->getCanonicalIndex(strtolower(str_replace(' ', '_', $directory)));
		if ($index === null || $index === NS_MAIN || $index < 0) {
			return null;
		}
		// getCanonicalIndex() ignores case; a directory has to spell the name as it is written.
		$canonical = str_replace('_', ' ', (string)$namespaceInfo->getCanonicalName($index));
		return $directory === $canonical ? $directory : null;
	}

	/** Whether a path spells its namespace the old way, with ":" in the file name. */
	public static function hasColonPrefix(string $relativePath): bool {
		$colon = strpos($relativePath, ':');
		if ($colon === false || str_contains(substr($relativePath, 0, $colon), '/')) {
			return false;
		}
		$title = MediaWikiServices::getInstance()->getTitleFactory()->newFromText(self::filenameToTitle($relativePath));
		return $title !== null && $title->getNamespace() !== NS_MAIN;
	}

	/** Page's source file name, percent-encoded for the $1 in URL templates ('/' and ':' kept). */
	public static function titleToParam(string $titleText): string {
		return strtr(rawurlencode(self::titleToFilename($titleText)), ['%2F' => '/', '%3A' => ':']);
	}

	/** Whether the page was imported from a source file, not generated during the build. */
	public static function exists(string $titleText): bool {
		$dir = self::sourceDirectory();
		return $dir !== null && is_file($dir . '/' . self::titleToFilename($titleText));
	}

	/** The source directory without its trailing slash, or null where there is none. */
	private static function sourceDirectory(): ?string {
		global $wgWikvenSourceDirectory;
		$dir = rtrim((string)$wgWikvenSourceDirectory, '/');
		return $dir === '' ? null : $dir;
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
