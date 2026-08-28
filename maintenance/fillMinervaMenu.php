<?php

namespace MediaWiki\Extension\Wikven;

use FilesystemIterator;
use Maintenance;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Put the site's navigation in Minerva's main menu.
 *
 * Minerva builds that menu from its own Menu\Definitions and reads MediaWiki:Sidebar only to
 * override the href of its two hardcoded entries, so a site's own navigation never reaches it:
 * its Definitions class is final, its builder is constructed inside the skin, and it registers no
 * menu hook. Nothing in PHP can add an entry, so the entries are written into the rendered pages
 * instead, in the same pass that rewrites their scripts and styles.
 *
 * Runs before rename.php, so hrefs written as "./Page.html" are reparented for subpages with
 * every other link on the page.
 */
class FillMinervaMenu extends Maintenance {
	/** The list Minerva renders its own discovery entries into; ours follow it. */
	private const AFTER_LIST = 'p-navigation';

	/** Minerva's own class for a menu group, which is what draws the gap between two of them. */
	private const LIST_CLASS = 'toggle-list__list';

	/** The placeholder build.php leaves on the settings page for the skin list. */
	private const SKIN_LIST = 'wikven-appearance-skins';

	/** The placeholder build.php leaves for the form MobileFrontend's controls are laid out in. */
	private const SETTINGS_FORM = 'wikven-settings-form';

	public function __construct() {
		parent::__construct();
		$this->addDescription("Add the site's navigation to Minerva's main menu.");
	}

	public function execute() {
		global $wgWikvenHtmlDirectory, $wgDefaultSkin;

		$dir = rtrim((string)$wgWikvenHtmlDirectory, '/');
		if ($wgDefaultSkin !== 'minerva' || $dir === '' || !is_dir($dir)) {
			return;
		}
		// The site's own navigation written into the skin's menu is the largest edit wikven makes
		// to any skin's chrome, so it is the first thing a skin preview does without: what Minerva
		// builds from its own definitions is what a skin author came to look at. See SkinPreview.
		if (SkinPreview::isOn()) {
			$this->output("Skin preview: leaving Minerva's main menu as the skin builds it\n");
			return;
		}

		// The navigation and the settings link are the same on every page; the switcher is not,
		// since each page links to its own copy in the other skins.
		$navigation = $this->list('p-wikven-navigation', $this->navigationMarkup());
		$settings = $this->settingsMarkup();
		$language = $this->getServiceContainer()->getContentLanguage();

		$changed = 0;
		foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'html') {
				continue;
			}
			// A group of its own each, as Minerva's own groups are: one list, one band of the menu.
			// The file is still under its cache name here; a link has to name its destination as
			// rename.php will leave it, since that pass runs after this one.
			$page = CacheName::toOutputPath($file->getFilename(), $language);
			$html = (string)file_get_contents($file->getPathname());
			$filled = HtmlListInserter::after(
				$html,
				self::AFTER_LIST,
				$navigation . $this->list('p-wikven-settings', $settings)
			);
			// The switcher lives on the settings page, the only page with anywhere to put it.
			$filled = HtmlListInserter::inside($filled, self::SKIN_LIST, $this->skinMarkup($page));
			// mobile.special.mobileoptions.styles lays the controls out through form.mw-mf-settings,
			// and wikitext carries no form, so the page gets one here.
			$filled = HtmlListInserter::inside(
				$filled,
				self::SETTINGS_FORM,
				Html::rawElement('form', ['id' => 'mobile-options', 'class' => 'mw-mf-settings'], '')
			);
			if ($filled !== $html) {
				file_put_contents($file, $filled, LOCK_EX);
				$changed++;
			}
		}
		$this->output("Filled Minerva's main menu on $changed page(s)\n");
	}

	/**
	 * The entry Minerva would have pointed at Special:MobileOptions, pointed at the page build.php
	 * generates in its place. Empty when the site has turned that page off.
	 */
	private function settingsMarkup(): string {
		$name = (string)( $GLOBALS['wgWikvenSettingsPage'] ?? '' );
		$title = $name === '' ? null : Title::newFromText($name);
		if (!$title) {
			return '';
		}
		// The cog Minerva draws beside its own settings entry, which the skin already ships: the
		// sidebar links have no icon of their own, but this one is the skin's own entry, restored.
		$icon = Html::element('span', ['class' => 'minerva-icon minerva-icon--settings'], '');
		return Html::rawElement(
			'li',
			['class' => 'toggle-list-item', 'id' => 't-wikven-settings'],
			Html::rawElement(
				'a',
				['class' => 'toggle-list-item__anchor', 'href' => $title->getLocalURL()],
				$icon
					. Html::element(
						'span',
						['class' => 'toggle-list-item__label'],
						wfMessage('wikven-settings')->text()
					)
			)
		);
	}

	/** One menu group, or "" when it would hold nothing. */
	private function list(string $id, string $items): string {
		return (
			$items === ''
				? ''
				: Html::rawElement('ul', ['id' => $id, 'class' => self::LIST_CLASS], $items)
		);
	}

	/** The sidebar's links as Minerva's own list items, or "" when there are none to add. */
	private function navigationMarkup(): string {
		$sidebar = RequestContext::getMain()->getSkin()->buildSidebar();
		// Minerva's own Home entry is the main page already.
		$home = Title::newMainPage()->getLocalURL();
		$markup = '';
		foreach ($this->sections($sidebar) as $item) {
			$href = $item['href'] ?? null;
			$text = $item['text'] ?? null;
			if (!is_string($href) || !is_string($text) || $text === '' || $href === $home) {
				continue;
			}
			$markup .= Html::rawElement(
				'li',
				['class' => 'toggle-list-item wikven-nav-item'],
				Html::rawElement(
					'a',
					['class' => 'toggle-list-item__anchor', 'href' => $href],
					Html::element('span', ['class' => 'toggle-list-item__label'], $text)
				)
			);
		}
		return $markup;
	}

	/**
	 * The skin switcher, which every other skin gets through the sidebar. Minerva would take it in
	 * the toolbox, but that is its page-actions menu, and which skin to read the site in is not an
	 * action on a page.
	 */
	private function skinMarkup(string $page): string {
		global $wgDefaultSkin;

		$items = '';
		$skin = RequestContext::getMain()->getSkin();
		foreach (SkinList::forPage($skin, $page, $wgDefaultSkin) as $entry) {
			$classes = ['wikven-skin-item'];
			if ($entry['active']) {
				$classes[] = 'active';
			}
			$items .= Html::rawElement(
				'li',
				['class' => $classes, 'id' => $entry['id']],
				// The skin being read names itself without linking to the page one is already on.
				$entry['href'] === null
					? Html::element('span', [], $entry['text'])
					: Html::element('a', ['href' => $entry['href']], $entry['text'])
			);
		}
		return $items === '' ? '' : Html::rawElement('ul', ['class' => 'wikven-skin-list'], $items);
	}

	/**
	 * Every link section of the sidebar, in order. A site names its own sections -- the docs site
	 * puts one entry in `navigation` and the rest in a section of its own -- so all of them are
	 * taken, minus the three core handles that are not navigation.
	 *
	 * @return iterable<array>
	 */
	private function sections(array $sidebar): iterable {
		foreach ($sidebar as $name => $items) {
			if (!is_array($items) || in_array($name, ['SEARCH', 'TOOLBOX', 'LANGUAGES'], true)) {
				continue;
			}
			yield from $items;
		}
	}
}

$maintClass = FillMinervaMenu::class;
require_once RUN_MAINTENANCE_IF_MAIN;
