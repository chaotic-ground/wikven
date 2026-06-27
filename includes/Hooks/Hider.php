<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\SourceFile;

class Hider implements
	\MediaWiki\Hook\ParserOutputPostCacheTransformHook,
	\MediaWiki\Hook\SidebarBeforeOutputHook,
	\MediaWiki\Hook\SkinTemplateNavigation__UniversalHook {
	/** @inheritDoc */
	public function onParserOutputPostCacheTransform($parserOutput, &$text, &$options): void {
		$options['enableSectionEditLinks'] = false;
	}

	/** @inheritDoc */
	public function onSidebarBeforeOutput($skin, &$sidebar): void {
		// Empty rather than unset: some skins (e.g. Minerva) read these keys and
		// require them to stay arrays.
		foreach (['TOOLBOX', 'SEARCH'] as $key) {
			if (isset($sidebar[$key])) {
				$sidebar[$key] = [];
			}
		}
	}

	/** @inheritDoc */
	public function onSkinTemplateNavigation__Universal($sktemplate, &$links): void {
		// Hide the personal tools (login, talk, preferences, etc.); this replaces the
		// removed PersonalUrls hook. Empty the groups rather than unset them: some
		// skins (e.g. Minerva) read these keys and require them to stay arrays.
		foreach (['user-menu', 'user-page', 'user-interface-preferences', 'notifications'] as $key) {
			if (isset($links[$key])) {
				$links[$key] = [];
			}
		}

		// The edit and history tabs only make sense when they point at the
		// external URLs configured via $wgWikvenEditUrl / $wgWikvenHistoryUrl, and
		// at a page with a source file behind them. A generated page (e.g.
		// Version) has none, so its links would 404; without the URLs, GetLocalURL
		// falls back to a self-link (./Page.html). Drop the tabs in either case.
		global $wgWikvenEditUrl, $wgWikvenHistoryUrl;
		$title = $sktemplate->getTitle();
		$hasSource = $title && SourceFile::exists($title->getPrefixedText());
		if (!$wgWikvenEditUrl || !$hasSource) {
			unset($links['views']['edit'], $links['views']['ve-edit'], $links['views']['viewsource']);
		}
		if (!$wgWikvenHistoryUrl || !$hasSource) {
			unset($links['views']['history']);
		}
	}
}
