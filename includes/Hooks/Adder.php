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
	 * Skins that move every sidebar section from the toolbox onward into their page-tools menu
	 * (SkinVector22::extractPageToolsFromSidebar splices from the toolbox to the end), so a section
	 * of our own lands there beside it, under a heading naming what it holds. Everywhere else the
	 * skin list goes in the toolbox: Citizen splices the toolbox alone and Minerva reads no other
	 * section, so a section of our own would leave their page-tools menus for the sidebar drawer,
	 * or vanish.
	 */
	private const OWN_SECTION_SKINS = ['vector-2022'];

	/** The sidebar section for the skin list, when it does not go in the toolbox. */
	private const SECTION = 'wikven-skins';

	/**
	 * Offer each enabled skin's copy of this page, in the toolbox Hider has just emptied or in a
	 * section of our own.
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
		// Appended, so it follows the toolbox: core drops SEARCH and LANGUAGES from the section
		// list, leaving the toolbox last and this section right after it.
		$section = in_array($current, self::OWN_SECTION_SKINS, true) ? self::SECTION : 'TOOLBOX';

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
			$sidebar[$section]["wikven-skin-$target"] = $entry;
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

		// Citizen registers a service worker at "$wgScriptPath/load.php" whenever the client-side
		// script path is the wiki root (""), which is what the build installs with, and the request
		// 404s on every page. There is no script path in a static export -- no index.php, load.php
		// or api.php -- so say so, and the registration returns early on its own guard. Citizen is
		// the only thing that reads the value for a decision; what else reads it builds api.php and
		// rest.php URLs, which are dead here whichever way it is set.
		if (MW_ENTRY_POINT === 'cli' && $skin->getSkinName() === 'citizen') {
			$out->addJsConfigVars('wgScriptPath', null);
		}

		// Citizen's preferences panel is where its readers change how a page looks, so the skin list
		// moves there from the toolbox. The module reads the entries the toolbox already holds and
		// takes them with it, so this needs no second copy of them and leaves the plain links for a
		// reader without JavaScript.
		if (
			$skin->getSkinName() === 'citizen'
			&& count($GLOBALS['wgWikvenSkins'] ?? []) > 1
			&& ( $GLOBALS['wgCitizenEnablePreferences'] ?? false )
		) {
			$out->addModules('ext.Wikven.citizenSkins');
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
