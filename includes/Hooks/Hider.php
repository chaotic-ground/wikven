<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\Search;
use MediaWiki\Extension\Wikven\SkinPreview;
use MediaWiki\Extension\Wikven\SourceFile;

/**
 * Everything this class takes away is an affordance a static host cannot answer for, which makes
 * all of it chrome: a skin preview keeps the lot, because a skin author is looking at exactly
 * these menus. See SkinPreview.
 */
class Hider implements
	\MediaWiki\Hook\ParserOutputPostCacheTransformHook,
	\MediaWiki\Hook\SidebarBeforeOutputHook,
	\MediaWiki\Hook\SkinTemplateNavigation__UniversalHook {
	/** @inheritDoc */
	public function onParserOutputPostCacheTransform($parserOutput, &$text, &$options): void {
		if (SkinPreview::isOn()) {
			return;
		}
		$options['enableSectionEditLinks'] = false;
	}

	/** @inheritDoc */
	public function onSidebarBeforeOutput($skin, &$sidebar): void {
		if (SkinPreview::isOn()) {
			return;
		}
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
		if (SkinPreview::isOn()) {
			return;
		}
		// Hide personal tools (login, talk, prefs); empty groups so skins like Minerva keep them.
		foreach (['user-menu', 'user-page', 'user-interface-preferences', 'notifications'] as $key) {
			if (isset($links[$key])) {
				$links[$key] = [];
			}
		}

		// A static export has no watchlist. Minerva puts the star back for anonymous readers,
		// pointing at the login page, so ext.Wikven.styles still hides what it re-adds.
		unset($links['actions']['watch'], $links['actions']['unwatch']);

		// The discussion tab. A static export cannot carry a discussion -- there is nothing behind
		// the tab to read and no way to post to it -- and MediaWiki has no setting that turns talk
		// namespaces off (T35298), so it is dropped from the navigation every skin builds from.
		// A `#ca-talk` rule in ext.Wikven.styles is not enough: that id is core's own on the tab,
		// and Minerva renders the same data through a tab bar of its own that keeps no id to hide.
		// Both menus, because a skin still asking core for the legacy `namespaces` one is rendered
		// from that copy rather than from `associated-pages`.
		foreach (['associated-pages', 'namespaces'] as $menu) {
			foreach (array_keys($links[$menu] ?? []) as $key) {
				// 'talk' in the main namespace, '<subject>_talk' everywhere else.
				if ($key === 'talk' || str_ends_with((string)$key, '_talk')) {
					unset($links[$menu][$key]);
				}
			}
		}

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
