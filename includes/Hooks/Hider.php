<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\Search;
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
		// Drop server tools a static export can't serve; keep SEARCH only if SifterSearch wires a box.
		$keys = ['TOOLBOX'];
		if (!Search::isActive()) {
			$keys[] = 'SEARCH';
		}
		// Empty rather than unset: Minerva passes $sidebar['TOOLBOX'] to an array parameter; null is fatal.
		foreach ($keys as $key) {
			if (isset($sidebar[$key])) {
				$sidebar[$key] = [];
			}
		}
	}

	/** @inheritDoc */
	public function onSkinTemplateNavigation__Universal($sktemplate, &$links): void {
		// Hide personal tools (login, talk, prefs); empty groups so skins like Minerva keep them.
		foreach (['user-menu', 'user-page', 'user-interface-preferences', 'notifications'] as $key) {
			if (isset($links[$key])) {
				$links[$key] = [];
			}
		}

		// A static export has no watchlist. Minerva puts the star back for anonymous readers,
		// pointing at the login page, so ext.Wikven.styles still hides what it re-adds.
		unset($links['actions']['watch'], $links['actions']['unwatch']);

		// Edit/history tabs need configured external URLs and a source file; else they 404 or self-link.
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
