<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\Version;
use MediaWiki\Parser\Hook\GetMagicVariableIDsHook;
use MediaWiki\Parser\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * Serves {{WIKVENVERSION}}: the version of the Wikven that is building this page.
 *
 * A site saying which Wikven built it had to write the number by hand and remember to change it,
 * and the documentation had the same problem in a worse place -- it tells readers which tag to pin
 * an action to, so a stale number there is a workflow that does not resolve. A variable is read at
 * build time and is right by construction; release-please already keeps extension.json's version
 * in step with the tag it cuts.
 *
 * MediaWiki's own {{CURRENTVERSION}} answers for MediaWiki. This answers for what wrote the site,
 * which is the other half of the question and the half a wikven site is likelier to be asked.
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
