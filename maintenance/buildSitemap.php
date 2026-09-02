<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Write sitemap.xml, naming every page this build exported.
 *
 * A sitemap is how a crawler is told a page exists without a link to it, which for a freshly
 * published site is most of them. The protocol wants absolute URLs -- "must begin with the
 * protocol" -- so this writes nothing at all until the site has said where it will be published;
 * see SiteUrl. A sitemap naming pages a crawler cannot resolve is worse than no sitemap.
 *
 * The pages come from the output directory rather than from the database, because those are two
 * different sets. Core's own maintenance/generateSitemap.php reads the database, and run against a
 * wikven build it names MediaWiki:Mainpage, Help:Setup and the rest -- pages that exist as rows and
 * were never exported, so every one of them is a 404 offered to a crawler as a page. What the site
 * serves is what is on disk.
 *
 * There is no <lastmod>. Everything a build could put there is either wrong or harmful: the wiki's
 * own timestamps are frozen by freezePageTouched, and the wall clock would make two bakes of one
 * source differ, which is the promise #411 checks. Both <lastmod> and <changefreq> are optional and
 * a crawler treats them as hints; an honest omission beats a field that lies every bake.
 */
class BuildSitemap extends Maintenance {
	/** The conventional name: what a crawler looks for, and what a webmaster tool is pointed at. */
	private const FILE = 'sitemap.xml';

	/** What the protocol allows in one sitemap before it has to be split across several. */
	private const URL_LIMIT = 50_000;

	public function __construct() {
		parent::__construct();
		$this->addDescription('Write sitemap.xml naming the pages this build exported.');
	}

	public function execute() {
		$siteUrl = SiteUrl::fromWritten((string)( $GLOBALS['wgWikvenSiteUrl'] ?? '' ));
		if (!$siteUrl->isKnown()) {
			return;
		}
		// One sitemap for the site, written by the pass that renders what the site serves. Every
		// other pass renders the same pages again under dist/<skin>/ for preview, and those carry
		// noindex; a second sitemap naming them would ask a crawler to index what the pages
		// themselves tell it to skip.
		$mainSkin = (string)( $GLOBALS['wgWikvenMainSkin'] ?? $GLOBALS['wgDefaultSkin'] );
		if ((string)$GLOBALS['wgDefaultSkin'] !== $mainSkin) {
			return;
		}
		$htmlDir = rtrim((string)$GLOBALS['wgWikvenHtmlDirectory'], '/');
		if ($htmlDir === '' || !is_dir($htmlDir)) {
			return;
		}

		$urls = [];
		$refused = 0;
		$pages = SkinOutput::pages($htmlDir, $GLOBALS['wgWikvenSkins'] ?? [], $mainSkin, $mainSkin);
		foreach ($pages as $path) {
			if (!self::invitesIndexing($path)) {
				$refused++;
				continue;
			}
			// The file's own name, turned into the link the site serves it at -- the same crossing
			// rename.php made in the other direction -- and then made absolute against the base.
			$urls[] = $siteUrl->forFile(OutputName::href(substr($path, strlen($htmlDir) + 1)));
		}
		if ($urls === []) {
			if ($refused > 0) {
				$this->output(
					'Wikven: no sitemap; all ' . $refused . " exported page(s) ask not to be indexed\n"
				);
			}
			return;
		}

		// Sorted because the walk is not: RecursiveDirectoryIterator hands back whatever order the
		// filesystem holds, and two bakes of one source have to be byte-identical (#411). The search
		// index needed the same treatment for the same reason (#460).
		sort($urls, SORT_STRING);

		if (count($urls) > self::URL_LIMIT) {
			$this->error(
				'Wikven: this site has '
				. count($urls)
				. ' pages, over the '
				. self::URL_LIMIT
				. ' a single sitemap may name. '
				. self::FILE
				. ' is written anyway and a crawler will reject it; splitting it is not built yet.'
			);
		}

		$file = "$htmlDir/" . self::FILE;
		if (file_put_contents($file, $this->document($urls)) === false) {
			$this->fatalError("Wikven: could not write $file");
		}
		$this->output('Wikven: wrote ' . self::FILE . ' naming ' . count($urls) . " page(s)\n");
	}

	/**
	 * Whether a rendered page is one a crawler may index, read from the page itself.
	 *
	 * A sitemap is an invitation to index, so naming a page that answers "noindex" asks a crawler
	 * to do what the page refuses -- the same contradiction the skin-preview copies are kept out
	 * of, and the reason a site that sets DefaultRobotPolicy to noindex gets no sitemap rather
	 * than one listing everything it just told crawlers to skip.
	 *
	 * The page is asked rather than the configuration, because the configuration is only one of
	 * the things that decides: __NOINDEX__ settles it a page at a time, and what MediaWiki wrote
	 * into the file is the answer both of them end at.
	 */
	private static function invitesIndexing(string $path): bool {
		$html = (string)file_get_contents($path);
		if (preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/i', $html, $found) !== 1) {
			return true;
		}
		return !str_contains(strtolower($found[1]), 'noindex');
	}

	/**
	 * The sitemap document for a set of absolute URLs.
	 *
	 * Escaped rather than written in: a page name keeps every character the file cache's escaping
	 * gave back, and "&" is one of them, so a site with a page called "Bread & butter" would
	 * otherwise write XML nothing can parse.
	 *
	 * @param string[] $urls
	 */
	private function document(array $urls): string {
		$lines = [
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
		];
		foreach ($urls as $url) {
			$lines[] = "\t<url><loc>" . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>';
		}
		$lines[] = '</urlset>';
		return implode("\n", $lines) . "\n";
	}
}

$maintClass = BuildSitemap::class;
require_once RUN_MAINTENANCE_IF_MAIN;
