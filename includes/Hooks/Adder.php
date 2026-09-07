<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Config\Config;
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
	 * Skins that move every sidebar section from the toolbox onward into their page-tools menu, so
	 * a section of our own lands there beside it. Everywhere else the skin list goes in the
	 * toolbox, where Citizen and Minerva would not find one. ext.Wikven.vectorSkins then moves it
	 * into the appearance menu; this is the fallback without JavaScript.
	 */
	private const OWN_SECTION_SKINS = ['vector-2022'];

	/** The sidebar section for the skin list, when it does not go in the toolbox. */
	private const SECTION = 'wikven-skins';

	private Config $config;

	public function __construct(Config $config) {
		$this->config = $config;
	}

	/**
	 * Offer each enabled skin's copy of this page, in the toolbox Hider has just emptied or in a
	 * section of our own.
	 *
	 * Minerva is not served here: it reads no sidebar section but `navigation` and the toolbox, and
	 * its toolbox is the page-actions menu. fillMinervaMenu.php writes the same entries into its
	 * main menu instead.
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
			// Citizen registers a service worker at "$wgScriptPath/load.php" whenever the client-side script
			// path is the wiki root (""), which is what the build installs with, and the request 404s on
			// every page. There is no script path in a static export, so say so, and the registration
			// returns early on its own guard.
			$out->addJsConfigVars('wgScriptPath', null);

			// The skin's search shortcuts outlive the command palette the bake leaves out: skin.js
			// binds them whether or not the trigger they open is still there, so "/" and Ctrl+K reach
			// for two modules the export does not ship. The module tells the loader as much, so the
			// request is never built, and opens the search form the export does have instead.
			$out->addModules('ext.Wikven.citizenSearchShortcuts');
		}

		// Minerva's own, and ahead of the guard for the same reason: most of what it hides is a
		// Special: page an export has no server for.
		//
		// A sheet of wikven's own rather than a "+skins.minerva.styles" entry: a second declaration of a
		// skin's key replaces the first rather than merging, so which survived came down to load order.
		if ($skin->getSkinName() === 'minerva') {
			$out->addModuleStyles('ext.Wikven.minervaStyles');
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
		if (count($this->skins()) < 2) {
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
			&& count($this->skins()) > 1
			// Citizen's own setting, so asked for before it is read: the skin naming itself here is
			// the skin being loaded in a bake, but it is only a mock saying so in a test.
			&& $this->config->has('CitizenEnablePreferences')
			&& $this->config->get('CitizenEnablePreferences')
		) {
			$out->addModules('ext.Wikven.citizenSkins');
		}

		// Vector's appearance menu is where its readers change how a page looks, so the skin list
		// moves there from the page-tools menu its own section lands in. As in Citizen, the module
		// moves the section the bake already rendered rather than building a second copy, so a
		// reader without JavaScript keeps the plain list of links where it is.
		if (
			$skin->getSkinName() === 'vector-2022'
			&& count($this->skins()) > 1
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
	 * The controls there are not the special page's markup: its script renders them from the client
	 * preferences the page declares, so the page here carries the same empty form. "Expand all
	 * sections" is not offered, being drawn only for a page carrying its class.
	 */
	private function prepareSettingsPage(OutputPage $out): void {
		$page = (string)$this->config->get('WikvenSettingsPage');
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

		if ($key !== 'places') {
			return;
		}
		$footerUrl = (string)$this->config->get('WikvenFooterUrl');
		if ($footerUrl) {
			$host = $this->repoHostName($footerUrl);
			$footerItems['source'] = Html::element(
				'a',
				['href' => $footerUrl],
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
	 * Root-relative like every other link the build writes, and through Special:MyLanguage where
	 * Translate is loaded, which is the condition resolveTranslationLinks.php runs on: without the
	 * prefix a Korean reader on Licenses/ko.html would be sent to the English page.
	 */
	public static function licensesHref(Title $licenses, bool $translated): string {
		$namespace = (string)$licenses->getNsText();
		if (!$translated) {
			return './' . OutputName::href(OutputName::of($namespace, $licenses->getDBkey()));
		}
		// Built as the title it stands for, rather than spelt out, so this marker and the one
		// GetLocalURL writes for a real Special:MyLanguage link are the same string in either spelling
		// of the file names, which is what resolveMyLanguage() matches on.
		$page = Title::makeName($licenses->getNamespace(), $licenses->getDBkey());
		return './' . OutputName::href(OutputName::of('Special', "MyLanguage/$page"));
	}

	/**
	 * Every skin the site is rendered in, and none where the wiki is not a build -- which is the
	 * declared default, so this is a plain read.
	 *
	 * @return array The skin names, in the order the site listed them.
	 */
	private function skins(): array {
		return (array)$this->config->get('WikvenSkins');
	}

	/** The page listing what the site redistributes, or null where the site asked for none. */
	private function licensesTitle(): ?Title {
		$name = (string)$this->config->get('WikvenLicensesPage');
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
