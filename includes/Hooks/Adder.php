<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\Search;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;

class Adder implements \MediaWiki\Hook\BeforePageDisplayHook, \MediaWiki\Hook\SkinAddFooterLinksHook {
	/** @inheritDoc */
	public function onBeforePageDisplay($out, $skin): void {
		$out->addModuleStyles('ext.Wikven.styles');
		$out->addModules('ext.Wikven.pinnableState');

		// No search backend on a static site; hide the box unless SifterSearch serves Pagefind.
		if (!Search::isActive()) {
			$out->addInlineStyle('#p-search { display: none; }');
		}

		// With more than one enabled skin, the footer carries a skin switcher.
		if (count($GLOBALS['wgWikvenSkins'] ?? []) > 1) {
			$out->addModules('ext.Wikven.skinSwitcher');
			$out->addJsConfigVars('wgWikvenMainSkin', $GLOBALS['wgWikvenMainSkin'] ?? '');
		}

		// A static export has no user session or server logs, so Timeless's personal-tools dropdown
		// and its "Page tools" sidebar (page actions, Special:Log) are dead; hide them on cli export.
		// !important: the skin stylesheet loads after this inline rule and would otherwise win.
		if (MW_ENTRY_POINT === 'cli' && $skin->getSkinName() === 'timeless') {
			$out->addInlineStyle('#user-tools, #page-tools { display: none !important; }');
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

	/** Display name for the project URL's host; forges prettified, others as-is, no host null. */
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

	/** Footer <select> linking to other skins' copies; ext.Wikven.skinSwitcher wires navigation. */
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
