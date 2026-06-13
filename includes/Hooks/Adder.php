<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use Html;
use Skin;

class Adder implements \MediaWiki\Hook\BeforePageDisplayHook, \MediaWiki\Hook\SkinAddFooterLinksHook {
	/** @inheritDoc */
	public function onBeforePageDisplay($out, $skin): void {
		$out->addModuleStyles('ext.Wikven.styles');
		$out->addModules('ext.Wikven.pinnableState');
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
