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

		// With more than one enabled skin, the footer carries a skin switcher.
		if (count($GLOBALS['wgWikvenSkins'] ?? []) > 1) {
			$out->addModules('ext.Wikven.skinSwitcher');
			$out->addJsConfigVars('wgWikvenMainSkin', $GLOBALS['wgWikvenMainSkin'] ?? '');
		}
	}

	/** @inheritDoc */
	public function onSkinAddFooterLinks(Skin $skin, string $key, array &$footerItems) {
		global $wgWikvenFooterUrl, $wgWikvenSkins;

		if ($key !== 'places') {
			return;
		}
		if ($wgWikvenFooterUrl) {
			$footerItems['github'] = Html::element(
				'a',
				['href' => $wgWikvenFooterUrl],
				// TODO: it could not be Github.
				'View project on Github'
			);
		}
		if (count($wgWikvenSkins ?? []) > 1) {
			$footerItems['skin-switcher'] = $this->skinSwitcher($wgWikvenSkins, $skin->getSkinName());
		}
	}

	/**
	 * A footer <select> linking to the other enabled skins' copies of the current
	 * page. The ext.Wikven.skinSwitcher module wires the navigation; without it
	 * the control is an inert list of the available skins.
	 */
	private function skinSwitcher(array $skins, string $current): string {
		$options = '';
		foreach ($skins as $name) {
			$attribs = ['value' => $name];
			if ($name === $current) {
				$attribs['selected'] = '';
			}
			$options .= Html::element('option', $attribs, ucwords(str_replace('-', ' ', $name)));
		}
		return Html::rawElement(
			'label',
			['class' => 'wikven-skin-switcher'],
			'Skin: ' . Html::rawElement('select', [], $options)
		);
	}
}
