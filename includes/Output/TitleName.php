<?php

namespace MediaWiki\Extension\Wikven\Output;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/** The namespace and title a page is written under, as OutputName::of() takes them. */
class TitleName {
	/**
	 * A special page is named canonically rather than as this wiki names it: a surviving link is a
	 * marker for a later pass, and a marker has to be one string.
	 *
	 * @return array{string,string}
	 */
	public static function of(Title $title): array {
		if ($title->getNamespace() !== NS_SPECIAL) {
			return [(string)$title->getNsText(), $title->getDBkey()];
		}
		$services = MediaWikiServices::getInstance();
		[$name, $subpage] = $services->getSpecialPageFactory()->resolveAlias($title->getDBkey());
		// A name no special page answers to has no canonical form to be written in; leave it as typed.
		if ($name === null) {
			return [self::specialNamespace(), $title->getDBkey()];
		}
		return [self::specialNamespace(), $subpage === null ? $name : "$name/$subpage"];
	}

	/** The special namespace as of() writes it, in every language: rename.php names those files so. */
	public static function specialNamespace(): string {
		return (string)MediaWikiServices::getInstance()->getNamespaceInfo()->getCanonicalName(NS_SPECIAL);
	}
}
