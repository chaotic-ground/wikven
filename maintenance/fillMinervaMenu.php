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
	/** The list Minerva renders its own discovery entries into. */
	private const LIST_ID = 'p-navigation';

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

		$items = $this->navigationMarkup();
		if ($items === '') {
			$this->output("No sidebar navigation to add\n");
			return;
		}

		$changed = 0;
		foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'html') {
				continue;
			}
			$html = (string)file_get_contents($file->getPathname());
			$filled = HtmlListAppender::append($html, self::LIST_ID, $items);
			if ($filled !== $html) {
				file_put_contents($file, $filled, LOCK_EX);
				$changed++;
			}
		}
		$this->output("Filled Minerva's main menu on $changed page(s)\n");
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
