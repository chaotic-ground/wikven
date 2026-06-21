<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Html\Html;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Skin\Skin;

class Adder implements \MediaWiki\Hook\BeforePageDisplayHook, \MediaWiki\Hook\SkinAddFooterLinksHook {
	/** @inheritDoc */
	public function onBeforePageDisplay($out, $skin): void {
		$out->addModuleStyles('ext.Wikven.styles');
		$out->addModules('ext.Wikven.pinnableState');

		// The header search box has no working backend on a static site, so hide
		// it, unless SifterSearch is installed to serve search from a static
		// Pagefind bundle.
		if (!ExtensionRegistry::getInstance()->isLoaded('SifterSearch')) {
			$out->addInlineStyle('#p-search { display: none; }');
		}
	}

	/** @inheritDoc */
	public function onSkinAddFooterLinks(Skin $skin, string $key, array &$footerItems) {
		global $wgWikvenFooterUrl;

		if ($key !== 'places' || !$wgWikvenFooterUrl) {
			return;
		}
		$footerItems['github'] = Html::element(
			'a',
			['href' => $wgWikvenFooterUrl],
			// TODO: it could not be Github.
			'View project on Github'
		);
	}
}
