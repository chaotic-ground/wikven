<?php

namespace MediaWiki\Extension\Wikven\Hooks;

class Hider implements
	\MediaWiki\Hook\ParserOutputPostCacheTransformHook,
	\MediaWiki\Hook\SidebarBeforeOutputHook,
	\MediaWiki\Hook\SkinTemplateNavigation__UniversalHook
{
	/** @inheritDoc */
	public function onParserOutputPostCacheTransform( $parserOutput, &$text,
		&$options
	): void {
		$options['enableSectionEditLinks'] = false;
	}

	/** @inheritDoc */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		// Empty rather than unset: some skins (e.g. Minerva) read these keys and
		// require them to stay arrays.
		foreach ( [ 'TOOLBOX', 'SEARCH' ] as $key ) {
			if ( isset( $sidebar[$key] ) ) {
				$sidebar[$key] = [];
			}
		}
	}

	/** @inheritDoc */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		// Hide the personal tools (login, talk, preferences, etc.); this replaces the
		// removed PersonalUrls hook. Empty the groups rather than unset them: some
		// skins (e.g. Minerva) read these keys and require them to stay arrays.
		foreach ( [ 'user-menu', 'user-page', 'user-interface-preferences', 'notifications' ] as $key ) {
			if ( isset( $links[$key] ) ) {
				$links[$key] = [];
			}
		}
	}
}
