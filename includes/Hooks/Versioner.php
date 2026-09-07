<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\Version;
use MediaWiki\Parser\Hook\GetMagicVariableIDsHook;
use MediaWiki\Parser\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * Serves {{WIKVENVERSION}}: the version of the Wikven that is building this page.
 *
 * A site saying which Wikven built it had to write the number by hand, and the documentation had
 * the same problem in a worse place: it tells readers which tag to pin an action to.
 *
 * MediaWiki's own {{CURRENTVERSION}} answers for MediaWiki; this answers for what wrote the site.
 */
class Versioner implements GetMagicVariableIDsHook, ParserGetVariableValueSwitchHook {
	private const VARIABLE = 'wikvenversion';

	/** @inheritDoc */
	public function onGetMagicVariableIDs(&$variableIDs) {
		$variableIDs[] = self::VARIABLE;
	}

	/** @inheritDoc */
	public function onParserGetVariableValueSwitch($parser, &$variableCache, $magicWordId, &$ret, $frame) {
		// Every registered variable comes through here, wikven's own among thousands; anything else
		// belongs to whoever registered it and is left for them.
		if ($magicWordId !== self::VARIABLE) {
			return;
		}
		$ret = Version::of(ExtensionRegistry::getInstance()->getAllThings(), 'Wikven');
	}
}
