<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\BuildFor;
use MediaWiki\Extension\Wikven\OutputName;
use MediaWiki\Extension\Wikven\Search;
use MediaWiki\Extension\Wikven\SkinList;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;

class Adder implements
	\MediaWiki\Hook\BeforePageDisplayHook,
	\MediaWiki\Hook\SidebarBeforeOutputHook,
	\MediaWiki\Hook\SkinAddFooterLinksHook {
	/**
	 * Skins that move every sidebar section from the toolbox onward into their page-tools menu
	 * (SkinVector22::extractPageToolsFromSidebar splices from the toolbox to the end), so a section
	 * of our own lands there beside it, under a heading naming what it holds. Everywhere else the
	 * skin list goes in the toolbox: Citizen splices the toolbox alone and Minerva reads no other
	 * section, so a section of our own would leave their page-tools menus for the sidebar drawer,
	 * or vanish.
	 *
	 * The page-tools menu is where the section is rendered, not where a reader is meant to find it:
	 * ext.Wikven.vectorSkins moves it into the appearance menu, beside the other choices about how
	 * a page looks. This stays as the fallback a reader without JavaScript is left with.
	 */
	private const OWN_SECTION_SKINS = ['vector-2022'];

	/** The sidebar section for the skin list, when it does not go in the toolbox. */
	private const SECTION = 'wikven-skins';

	/**
	 * Offer each enabled skin's copy of this page, in the toolbox Hider has just emptied or in a
	 * section of our own.
	 *
	 * Minerva is not served here. It reads no sidebar section but `navigation` and the toolbox, and
	 * its toolbox is the page-actions menu, which is not where a site-wide setting belongs;
	 * fillMinervaMenu.php writes the same entries into its main menu instead.
	 *
	 * @inheritDoc
	 */
	public function onSidebarBeforeOutput($skin, &$sidebar): void {
		// A list of skins to read the site in is wikven's, not the skin's, and a preview is of one
		// skin. See BuildFor.
		if (BuildFor::skinPreview()) {
			return;
		}
		$current = $skin->getSkinName();
		if ($current === 'minerva' || !isset($sidebar['TOOLBOX'])) {
			return;
		}

		// Appended, so it follows the toolbox: core drops SEARCH and LANGUAGES from the section
		// list, leaving the toolbox last and this section right after it.
		$section = in_array($current, self::OWN_SECTION_SKINS, true) ? self::SECTION : 'TOOLBOX';

		foreach (SkinList::entries($skin) as $entry) {
			$item = ['id' => $entry['id'], 'text' => $entry['text']];
			if ($entry['href'] !== null) {
				$item['href'] = $entry['href'];
			}
			if ($entry['active']) {
				$item['active'] = true;
			}
			$sidebar[$section][$entry['id']] = $item;
		}
	}

	/** @inheritDoc */
	public function onBeforePageDisplay($out, $skin): void {
		// Ahead of the preview guard, because neither of these is an opinion about how the page
		// should look: both stop the export asking for something it does not have, which a preview
		// has no more of than a published site does.
		if (MW_ENTRY_POINT === 'cli' && $skin->getSkinName() === 'citizen') {
			// Citizen registers a service worker at "$wgScriptPath/load.php" whenever the client-side
			// script path is the wiki root (""), which is what the build installs with, and the request
			// 404s on every page. There is no script path in a static export -- no index.php, load.php
			// or api.php -- so say so, and the registration returns early on its own guard. Citizen is
			// the only thing that reads the value for a decision; what else reads it builds api.php and
			// rest.php URLs, which are dead here whichever way it is set.
			$out->addJsConfigVars('wgScriptPath', null);

			// The skin's search shortcuts outlive the command palette the bake leaves out: skin.js
			// binds them whether or not the trigger they open is still there, so "/" and Ctrl+K reach
			// for two modules the export does not ship. The module tells the loader as much, so the
			// request is never built, and opens the search form the export does have instead.
			$out->addModules('ext.Wikven.citizenSearchShortcuts');
		}

		if (BuildFor::skinPreview()) {
			return;
		}

		$out->addModuleStyles('ext.Wikven.styles');
		$out->addModules('ext.Wikven.pinnableState');

		// No search backend on a static site; hide the box unless SifterSearch serves Pagefind.
		if (!Search::isActive()) {
			$out->addInlineStyle('#p-search { display: none; }');
		}

		// One skin means no skin list, so nothing refills the toolbox and its box stays empty.
		if (count($GLOBALS['wgWikvenSkins'] ?? []) < 2) {
			$out->addModuleStyles('ext.Wikven.emptyToolbox');
		} else {
			// Every skin renders the settings page, so every skin has its skin list to fill in. The
			// module takes the list from the chrome, wherever the skin keeps it, and leaves where
			// the build has already written one -- which is Minerva, and Minerva alone.
			$out->addModules('ext.Wikven.appearance');
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

		// Vector's appearance menu is where its readers change how a page looks, so the skin list
		// moves there from the page-tools menu its own section lands in. As in Citizen, the module
		// moves the section the bake already rendered rather than building a second copy, so a
		// reader without JavaScript keeps the plain list of links where it is.
		if (
			$skin->getSkinName() === 'vector-2022'
			&& count($GLOBALS['wgWikvenSkins'] ?? []) > 1
		) {
			$out->addModules('ext.Wikven.vectorSkins');
		}

		// Minerva writes the night-mode class only when SkinOptions::NIGHT_MODE is on, which nothing
		// but MobileFrontend can turn on, or when the request carries minervanightmode. The bake
		// makes its own requests, so it asks for the day theme: the class is what mw.user.clientPrefs
		// switches from, and without it the settings page would have nothing to offer.
		if ($skin->getSkinName() === 'minerva') {
			$out->getRequest()->setVal('minervanightmode', 'day');
			// The text size a reader picks on the settings page has to hold on the pages they then
			// read, so every page carries the class it is set from and the stylesheet it means
			// something in. MobileFrontend loads that stylesheet when it is serving a mobile view,
			// which a bake never is.
			if (ExtensionRegistry::getInstance()->isLoaded('MobileFrontend')) {
				$out->addHtmlClasses('mf-font-size-clientpref-regular');
				$out->addModuleStyles('mobile.init.styles');
			}
			$this->prepareSettingsPage($out);
		}

		// A static export has no user session or server logs, so Timeless's personal-tools dropdown
		// and its "Page tools" sidebar (page actions, Special:Log) are dead; hide them on cli export.
		// !important: the skin stylesheet loads after this inline rule and would otherwise win.
		if (MW_ENTRY_POINT === 'cli' && $skin->getSkinName() === 'timeless') {
			$out->addInlineStyle('#user-tools, #page-tools { display: none !important; }');
		}
	}

	/**
	 * Ask for what Special:MobileOptions asks for, on the page the export offers in its place.
	 *
	 * The controls there are not the special page's markup: its script renders them from the
	 * client preferences the page declares, which is why they can be had at all without a wiki
	 * behind them. So the page carries the same empty form, and buildScripts.php bundles the
	 * modules because this queued them.
	 *
	 * The one control not offered is "Expand all sections": clientPreferences.js draws a preference
	 * only when the page carries its class, and no page here does, because nothing in a bake
	 * collapses a section to begin with.
	 */
	private function prepareSettingsPage(OutputPage $out): void {
		$page = (string)( $GLOBALS['wgWikvenSettingsPage'] ?? '' );
		$title = $out->getTitle();
		if ($page === '' || !$title || $title->getPrefixedText() !== $page) {
			return;
		}
		if (!ExtensionRegistry::getInstance()->isLoaded('MobileFrontend')) {
			return;
		}

		// The special page sets this itself, and its script draws no text-size control without it.
		$out->addJsConfigVars([
			'wgMFEnableFontChanger' => MediaWikiServices::getInstance()
				->getService('MobileFrontend.FeaturesManager')
				->isFeatureAvailableForCurrentUser('MFEnableFontChanger')
		]);
		$out->addModuleStyles([
			'mobile.special.styles',
			'mobile.special.codex.styles',
			'mobile.special.mobileoptions.styles'
		]);
		// oojs-ui-widgets is asked for at runtime rather than declared, so buildScripts.php has no
		// way to see it coming: without it the module's own mw.loader.using() never resolves and
		// nothing it would have drawn is drawn.
		$out->addModules(['mobile.special.mobileoptions.scripts', 'oojs-ui-widgets']);
	}

	/** @inheritDoc */
	public function onSkinAddFooterLinks(Skin $skin, string $key, array &$footerItems) {
		// A row in the footer that the skin did not put there. See BuildFor.
		if (BuildFor::skinPreview()) {
			return;
		}

		global $wgWikvenFooterUrl;

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

		// What the site redistributes, on every page, because the page saying it is no use if only
		// the reader who goes looking finds it.
		$licenses = $this->licensesTitle();
		if ($licenses !== null) {
			$footerItems['wikven-licenses'] = Html::element(
				'a',
				[
					'href' => self::licensesHref(
						$licenses,
						ExtensionRegistry::getInstance()->isLoaded('Translate')
					)
				],
				$skin->msg('wikven-footer-licenses')->text()
			);
		}
	}

	/**
	 * Where the footer's licenses link points.
	 *
	 * Root-relative like every other link the build writes, so rename.php reparents it for a
	 * subpage along with the rest.
	 *
	 * Through Special:MyLanguage where Translate is loaded, which is the condition
	 * resolveTranslationLinks.php runs on: that pass rewrites the link to the reader's own
	 * language, or to the source page where that language has no translation. Without the prefix a
	 * Korean reader on Licenses/ko.html would be sent to the English page, and since this link is
	 * the site's only guaranteed route to it, there is no second way in.
	 *
	 * Written plainly where Translate is not loaded, because then the pass never runs and the
	 * special page, which no export contains, would be left standing in the href.
	 */
	public static function licensesHref(Title $licenses, bool $translated): string {
		$namespace = (string)$licenses->getNsText();
		if (!$translated) {
			return './' . OutputName::href(OutputName::of($namespace, $licenses->getDBkey()));
		}
		// Built as the title it stands for, rather than spelt out, so this marker and the one
		// GetLocalURL writes for a real Special:MyLanguage link are the same string in either
		// spelling of the file names -- which is what resolveMyLanguage() below matches on.
		$page = Title::makeName($licenses->getNamespace(), $licenses->getDBkey());
		return './' . OutputName::href(OutputName::of('Special', "MyLanguage/$page"));
	}

	/** The page listing what the site redistributes, or null where the site asked for none. */
	private function licensesTitle(): ?Title {
		$name = (string)( $GLOBALS['wgWikvenLicensesPage'] ?? '' );
		return $name === '' ? null : Title::newFromText($name);
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
