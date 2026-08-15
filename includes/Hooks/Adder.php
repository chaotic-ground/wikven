<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\Search;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;

class Adder implements
	\MediaWiki\Hook\BeforePageDisplayHook,
	\MediaWiki\Hook\SidebarBeforeOutputHook,
	\MediaWiki\Hook\SkinAddFooterLinksHook {
	/**
	 * Skins whose toolbox is a page-actions menu: Minerva's builder keeps only entries carrying an
	 * icon, and hands every one of those to SingleMenuEntry, whose $url is typed string -- so an
	 * entry with an icon and no href fatals the bake. There the current skin is a self-link.
	 */
	private const PAGE_ACTIONS_TOOLBOX = ['minerva' => 'listBullet'];

	/**
	 * Offer each enabled skin's copy of this page in the toolbox, which Hider has just emptied.
	 *
	 * The href is the same root-relative form every other link is written in ("./x" from the main
	 * skin's output, "../x" from a skin subdirectory), so rename.php reparents these by the page's
	 * own depth along with the rest and a subpage's links stay correct.
	 *
	 * @inheritDoc
	 */
	public function onSidebarBeforeOutput($skin, &$sidebar): void {
		global $wgWikvenSkins, $wgWikvenMainSkin;

		$skins = $wgWikvenSkins ?? [];
		$title = $skin->getTitle();
		if (count($skins) < 2 || !isset($sidebar['TOOLBOX']) || !$title || !$title->canExist()) {
			return;
		}

		$current = $skin->getSkinName();
		$page = Title::makeName($title->getNamespace(), $title->getDBkey()) . '.html';
		$root = $current === $wgWikvenMainSkin ? './' : '../';
		$icon = self::PAGE_ACTIONS_TOOLBOX[$current] ?? null;

		foreach ($skins as $target) {
			$entry = ['id' => "t-wikven-skin-$target", 'text' => $this->skinLabel($skin, $target)];
			if ($icon !== null) {
				$entry['icon'] = $icon;
			}
			if ($target !== $current || $icon !== null) {
				$entry['href'] = $root . ( $target === $wgWikvenMainSkin ? '' : "$target/" ) . $page;
			}
			if ($target === $current) {
				$entry['active'] = true;
			}
			$sidebar['TOOLBOX']["wikven-skin-$target"] = $entry;
		}
	}

	/** A skin's human-readable name; getInstalledSkins() only resolves an explicit displayname. */
	private function skinLabel(Skin $skin, string $name): string {
		$message = $skin->msg("skinname-$name");
		if (!$message->isDisabled() && $message->exists()) {
			return $message->text();
		}
		$installed = MediaWikiServices::getInstance()->getSkinFactory()->getInstalledSkins();
		return $installed[$name] ?? ucwords(str_replace('-', ' ', $name));
	}

	/** @inheritDoc */
	public function onBeforePageDisplay($out, $skin): void {
		$out->addModuleStyles('ext.Wikven.styles');
		$out->addModules('ext.Wikven.pinnableState');

		// No search backend on a static site; hide the box unless SifterSearch serves Pagefind.
		if (!Search::isActive()) {
			$out->addInlineStyle('#p-search { display: none; }');
		}

		// One skin means no skin list, so nothing refills the toolbox and its box stays empty.
		if (count($GLOBALS['wgWikvenSkins'] ?? []) < 2) {
			$out->addModuleStyles('ext.Wikven.emptyToolbox');
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
		global $wgWikvenFooterUrl, $wgWikvenVersionPage;

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
}
