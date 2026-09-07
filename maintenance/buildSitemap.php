<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\Build\SkinOutput;
use MediaWiki\Extension\Wikven\Output\OutputName;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Write sitemap.xml, naming every page this build exported.
 *
 * The protocol wants absolute URLs, so this writes nothing until the site has said where it is
 * published. The pages come from the output directory rather than the database: core's
 * generateSitemap.php names rows that were never exported.
 *
 * There is no <lastmod> -- the wall clock would make two bakes differ (#411).
 */
class BuildSitemap extends Maintenance {
	/** The conventional name: what a crawler looks for, and what a webmaster tool is pointed at. */
	private const FILE = 'sitemap.xml';

	public function __construct() {
		parent::__construct();
		$this->addDescription('Write sitemap.xml naming the pages this build exported.');
	}

	public function execute() {
		// Read through the config service rather than $GLOBALS: at build time MediaWiki is fully up,
		// so the settings file's excuse for reaching into globals does not apply here.
		$config = $this->getConfig();

		$siteUrl = SiteUrl::fromWritten((string)$config->get('WikvenSiteUrl'));
		if (!$siteUrl->isKnown()) {
			return;
		}
		// One sitemap for the site, written by the pass that renders what the site serves. Every other
		// pass renders the same pages under dist/<skin>/ for preview, and those carry noindex.
		$mainSkin = (string)$config->get('WikvenMainSkin');
		if ((string)$config->get('DefaultSkin') !== $mainSkin) {
			return;
		}
		$htmlDir = rtrim((string)$config->get('WikvenHtmlDirectory'), '/');
		if ($htmlDir === '' || !is_dir($htmlDir)) {
			return;
		}

		$urls = [];
		$refused = 0;
		$pages = SkinOutput::pages($htmlDir, (array)$config->get('WikvenSkins'), $mainSkin, $mainSkin);
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

		// Built before it is weighed, because one of the two caps is a fact about the bytes rather
		// than about the pages, and the bytes are not known until the document is.
		$document = $this->document($urls);
		$past = SitemapLimits::exceeded(self::FILE, count($urls), strlen($document));
		if ($past !== null) {
			$this->error($past);
		}

		$file = "$htmlDir/" . self::FILE;
		if (file_put_contents($file, $document) === false) {
			$this->fatalError("Wikven: could not write $file");
		}
		$this->output('Wikven: wrote ' . self::FILE . ' naming ' . count($urls) . " page(s)\n");
	}

	/**
	 * Whether a rendered page is one a crawler may index, read from the page itself.
	 *
	 * The page is asked rather than the configuration, __NOINDEX__ settling it a page at a time.
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
	 * Escaped rather than written in: a page name keeps every character the cache's escaping gave
	 * back, "&" among them.
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
