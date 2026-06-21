<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;

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

		// Citizen's search is its own REST-backed command palette, which has no
		// backend on the static export; hide its trigger there. SifterSearch does
		// not wire Citizen, so there is no Pagefind-backed replacement. A live wiki
		// (web entry) keeps Citizen's search working, so only hide it on the build.
		if (MW_ENTRY_POINT === 'cli' && $skin->getSkinName() === 'citizen') {
			// !important: the element also carries .citizen-dropdown, whose
			// display:flex would otherwise win by cascade order.
			$out->addInlineStyle('.citizen-search { display: none !important; }');
		}
	}

	/** @inheritDoc */
	public function onSkinAddFooterLinks(Skin $skin, string $key, array &$footerItems) {
		global $wgWikvenFooterUrl, $wgWikvenSkins, $wgWikvenVersionPage;

		if ($key !== 'places') {
			return;
		}
		if ($wgWikvenFooterUrl) {
			$host = $this->repoHostName($wgWikvenFooterUrl);
			$footerItems['source'] = Html::element(
				'a',
				['href' => $wgWikvenFooterUrl],
				$host !== null
					? $skin->msg('wikven-footer-source', $host)->text()
					: $skin->msg('wikven-footer-source-plain')->text()
			);
		}
		$versionPage = $wgWikvenVersionPage ?? '';
		if ($versionPage !== '') {
			$versionTitle = Title::newFromText($versionPage);
			if ($versionTitle && $versionTitle->exists()) {
				$footerItems['version'] = Html::element(
					'a',
					['href' => $versionTitle->getLocalURL()],
					$skin->msg('version')->text()
				);
			}
		}
		if (count($wgWikvenSkins ?? []) > 1) {
			$footerItems['skin-switcher'] = $this->skinSwitcher($skin, $wgWikvenSkins, $skin->getSkinName());
		}
	}

	/**
	 * A display name for the host of the project URL, so the footer link is not
	 * hardcoded to GitHub. Known forges are prettified; any other host is shown
	 * as-is, and a URL with no host yields null (the caller drops the host name).
	 */
	private function repoHostName(string $url): ?string {
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return null;
		}
		$host = preg_replace('/^www\./', '', $host);
		$known = [
			'github.com' => 'GitHub',
			'gitlab.com' => 'GitLab',
			'codeberg.org' => 'Codeberg',
			'bitbucket.org' => 'Bitbucket',
			'gitea.com' => 'Gitea',
			'sr.ht' => 'sourcehut'
		];
		return $known[$host] ?? $host;
	}

	/**
	 * A footer <select> linking to the other enabled skins' copies of the current
	 * page. The ext.Wikven.skinSwitcher module wires the navigation; without it
	 * the control is an inert list of the available skins.
	 */
	private function skinSwitcher(Skin $skin, array $skins, string $current): string {
		$displayNames = MediaWikiServices::getInstance()->getSkinFactory()->getInstalledSkins();
		$options = '';
		foreach ($skins as $name) {
			$attribs = ['value' => $name];
			if ($name === $current) {
				$attribs['selected'] = '';
			}
			$label = $displayNames[$name] ?? ucwords(str_replace('-', ' ', $name));
			$options .= Html::element('option', $attribs, $label);
		}
		$label = $skin->msg('wikven-skin-switcher-label')->escaped() . $skin->msg('colon-separator')->text();
		return Html::rawElement(
			'label',
			['class' => 'wikven-skin-switcher'],
			$label . Html::rawElement('select', [], $options)
		);
	}
}
