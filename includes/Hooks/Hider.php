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
		unset( $sidebar['TOOLBOX'] );
		unset( $sidebar['SEARCH'] );
	}

	/** @inheritDoc */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		// Hide the personal tools (login, talk, preferences, etc.); this replaces the
		// removed PersonalUrls hook.
		unset( $links['user-menu'] );
		unset( $links['user-page'] );
		unset( $links['user-interface-preferences'] );
		unset( $links['notifications'] );
	}
}
