<?php

namespace MediaWiki\Extension\Wikven\Bake;

/**
 * What a finished bake has to be true of, one method per fact.
 *
 * Each check reads a baked site and returns the problems it found, as sentences a reader can act
 * on. None of them knows it is running on a CI runner: a check that wants to be loud says so by
 * returning a problem, and assert-bake.php decides whether that becomes a line of prose or a
 * workflow command.
 *
 * The order all() lists them in is the order of the report.
 */
class Checks {
	/** The sitemap protocol's namespace, which every element in the file is in. */
	private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

	/**
	 * The addresses a build host answers on. Any of them in the output names the machine that ran
	 * the bake rather than the site, which is a different site for every reader.
	 */
	private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];

	/**
	 * The one repository wikven turns on for a site (default.yml: UseInstantCommons), and so the
	 * one host a page can hotlink a picture from without having been told to.
	 */
	private const HOTLINK_HOST = 'upload.wikimedia.org';

	/** @return Check[] */
	public static function all(): array {
		return [
			new Check('index-page', 'the main page landed at the root of the export', self::indexPage(...)),
			new Check(
				'bake-warnings',
				'the bake warned about nothing in the site configuration',
				self::bakeWarnings(...),
				['logs']
			),
			new Check(
				'sitemap',
				'the sitemap names absolute addresses, all under the published base',
				self::sitemap(...)
			),
			new Check('og-url', 'every og:url is a whole address', self::ogUrl(...)),
			new Check(
				'noindex',
				'the pages search engines are told to skip are the ones this site names',
				self::noindex(...)
			),
			new Check('og-image', 'the card a shared link shows names a file this build wrote', self::ogImage(...)),
			new Check(
				'hotlink-host',
				'no picture in the export is still fetched from the repository it came from',
				self::hotlinkHost(...)
			),
			new Check('build-host', 'nothing a reader is handed names the machine that built it', self::buildHost(...)),
			new Check(
				'sitemap-reproducible',
				'two bakes of one source agree on the sitemap',
				self::sitemapReproducible(...),
				['other']
			),
			new Check('css-load-php', 'the dumped stylesheets stand on their own', self::cssLoadPhp(...)),
			new Check(
				'special-search-link',
				'no page links to a special page the export does not have',
				self::specialSearchLink(...)
			),
			new Check(
				'pagefind-bundles',
				'each skin copy carries a search bundle of its own',
				self::pagefindBundles(...)
			),
			new Check(
				'pagefind-languages',
				'each language the site is written in has a search index',
				self::pagefindLanguages(...)
			),
			new Check(
				'pagefind-source-language',
				'no source page is indexed twice under its own language',
				self::pagefindSourceLanguage(...)
			),
			new Check('sortable-table', 'the module a sortable table needs is in the bundle', self::sortableTable(...)),
			new Check(
				'skin-modules',
				'the bundle registers the skins this site builds and no others',
				self::skinModules(...)
			),
			new Check(
				'lua-modules',
				'a Lua module ran at build time and its answer is in the page',
				self::luaModules(...)
			),
			new Check(
				'printfooter-links',
				'every "Retrieved from" link resolves from the page holding it',
				self::printfooterLinks(...)
			),
			new Check(
				'head-links',
				'every address a page names for itself is one the export has',
				self::headLinks(...)
			),
			new Check('canonical-coverage', 'every page says which address is its own', self::canonicalCoverage(...)),
			new Check(
				'webfonts',
				'the bundled webfonts are there and reachable from the stylesheet',
				self::webfonts(...)
			),
			new Check(
				'language-bars',
				'every language bar lists every language the source has',
				self::languageBars(...),
				['source']
			),
			new Check(
				'prevnext-rows',
				'every page the sidebar names gets a navigation row',
				self::prevnextRows(...),
				['source']
			),
			new Check(
				'prevnext-label',
				"a translated navigation row shows the target page's own title",
				self::prevnextLabel(...),
				['source']
			)
		];
	}

	/**
	 * The first few of a list, written into a sentence. A problem names what it found rather than
	 * counting it, and names a few rather than all of it, so the line stays readable.
	 *
	 * @param string[] $items
	 */
	private static function few(array $items, int $limit = 3): string {
		$shown = array_slice($items, 0, $limit);
		$more = count($items) - count($shown);
		return implode(', ', $shown) . ( $more > 0 ? ", and $more more" : '' );
	}

	/**
	 * Every <loc> in a sitemap, in the order the file lists them.
	 *
	 * @return string[]
	 */
	private static function sitemapUrls(string $path): array {
		// libxml reports a malformed file by writing to the error log unless it is told not to;
		// a sitemap this cannot read is the sitemap check's to report, not a warning's.
		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_file($path);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($xml === false) {
			return [];
		}
		$xml->registerXPathNamespace('s', self::SITEMAP_NS);
		$urls = [];
		foreach ($xml->xpath('//s:url/s:loc') ?: [] as $element) {
			$urls[] = (string)$element;
		}
		return $urls;
	}

	private static function isAbsolute(string $url): bool {
		return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
	}

	private static function namesLocalHost(string $url): bool {
		$host = parse_url($url, PHP_URL_HOST);
		return $host !== null && $host !== false && in_array(strtolower($host), self::LOCAL_HOSTS, true);
	}

	/** @return string[] */
	private static function indexPage(Site $site): array {
		// The main page must land at the dist root (a failed import already aborted the build).
		$path = $site->path('index.html');
		if (!is_file($path) || filesize($path) === 0) {
			return ["$path is missing or empty"];
		}
		return [];
	}

	/** @return string[] */
	private static function bakeWarnings(Site $site): array {
		// A bake with something to say about the site's own configuration says it and carries on, so a
		// warning is only ever seen by whoever reads the log. The site's .wikven.yaml is the
		// configuration wikven recommends, so a warning about it is a mistake worth stopping for.
		$warnings = [];
		foreach ($site->logs as $log) {
			foreach (explode("\n", $site->read($log)) as $line) {
				if (str_contains($line, 'Wikven: WARNING')) {
					$warnings[] = rtrim($line, "\r");
				}
			}
		}
		if ($warnings) {
			return ['the bake warned about the site configuration:', ...$warnings];
		}
		return [];
	}

	/** @return string[] */
	private static function sitemap(Site $site): array {
		// The site configuration says where this site is published, so the bake owes it a sitemap.
		// The bug this guards against fataled the whole build and shipped anyway, because the code
		// path only runs for a site that sets WikvenSiteUrl and no test site did.
		$path = $site->path('sitemap.xml');
		if (!is_file($path) || filesize($path) === 0) {
			return ["$path is missing or empty"];
		}
		$locs = self::sitemapUrls($path);
		if (!$locs) {
			return ['sitemap.xml names no pages'];
		}
		// The protocol requires absolute URLs, and getting a relative one is the failure that looks
		// fine in a diff: core's own generateSitemap.php emits "index.html" under wikven.
		$bad = [];
		foreach ($locs as $url) {
			if (!self::isAbsolute($url)) {
				$bad[] = $url;
			}
		}
		if ($bad) {
			return ['sitemap.xml has non-absolute URLs: ' . self::few($bad, 5)];
		}
		$base = $site->base();
		foreach ($locs as $url) {
			if (!str_starts_with($url, $base)) {
				return ['sitemap.xml names URLs outside the published base'];
			}
		}
		// A skin copy is noindex, so naming one would ask a crawler to index what the page denies.
		$copies = (array)$site->expect['skin_copies'];
		$inside = [];
		foreach ($locs as $url) {
			if (in_array(explode('/', substr($url, strlen($base)))[0], $copies, true)) {
				$inside[] = $url;
			}
		}
		if ($inside) {
			return ['sitemap.xml names skin-preview copies: ' . self::few($inside, 5)];
		}
		$site->note('sitemap.xml: ' . count($locs) . ' absolute URLs');
		return [];
	}

	/** @return string[] */
	private static function ogUrl(Site $site): array {
		// og:url has to be a whole URL: it is read off the page by something that never saw the
		// page's address. Title::getFullURL() answers the GetFullURL hook, so this is the tag that
		// proves that hook fired -- before it existed WikiSEO published "http:index.html" here,
		// green across every other check.
		$bad = [];
		foreach ($site->htmlFiles() as $path) {
			$found = [];
			if (
				preg_match('~<meta property="og:url" content="([^"]*)"~', $site->read($path), $found)
				&& !self::isAbsolute($found[1])
			) {
				$bad[] = "$path -> {$found[1]}";
			}
		}
		if ($bad) {
			return ['og:url is not an absolute URL on ' . count($bad) . ' page(s): ' . self::few($bad)];
		}
		$site->note('og:url is absolute on every page that carries one');
		return [];
	}

	/** @return string[] */
	private static function noindex(Site $site): array {
		// A page that stops being indexed is invisible by construction: nothing 404s, and the only place
		// it shows is a crawler's view of the site weeks later. __NOINDEX__ is a behaviour switch, so a
		// page telling a reader to use it took it -- Deploying noindexed itself, in both languages, with
		// every gate here green (#655).
		$expected = (array)$site->expect['noindex_pages'];
		$copies = (array)$site->expect['skin_copies'];

		$pages = [];
		$noindexed = [];
		$indexableCopies = [];
		foreach ($site->htmlFiles() as $path) {
			$page = $site->relative($path);
			$tag = [];
			$isNoindex =
				preg_match('~<meta name="robots" content="([^"]*)"~', $site->read($path), $tag) === 1
				&& str_contains($tag[1], 'noindex');
			$copy = explode('/', $page)[0];
			if (in_array($copy, $copies, true)) {
				// A skin copy is noindex wholesale, so nobody arrives from a search result reading
				// the site in a skin they never picked.
				if (!$isNoindex) {
					$indexableCopies[$copy][] = $page;
				}
				continue;
			}
			$pages[$page] = true;
			if ($isNoindex) {
				$noindexed[$page] = true;
			}
		}

		$base = $site->base();
		$listed = [];
		foreach (self::sitemapUrls($site->path('sitemap.xml')) as $url) {
			if (str_starts_with($url, $base)) {
				$listed[rawurldecode(substr($url, strlen($base)))] = true;
			}
		}

		$problems = [];
		ksort($indexableCopies);
		foreach ($indexableCopies as $copy => $indexable) {
			sort($indexable, SORT_STRING);
			$problems[] = "$copy/ has " . count($indexable) . ' indexable page(s): ' . self::few($indexable, 5);
		}
		$noindexedNames = array_keys($noindexed);
		sort($noindexedNames, SORT_STRING);
		$expectedNames = $expected;
		sort($expectedNames, SORT_STRING);
		if ($noindexedNames !== $expectedNames) {
			$problems[] =
				'the site noindexes ['
				. implode(' ', $noindexedNames)
				. '], expected ['
				. implode(' ', $expectedNames)
				. ']';
		}
		$missing = array_keys(array_diff_key($pages, $listed, array_fill_keys($expected, true)));
		sort($missing, SORT_STRING);
		if ($missing) {
			$problems[] = count($missing) . ' indexable page(s) are not in sitemap.xml: ' . self::few($missing, 5);
		}
		$strays = array_values(array_intersect($expected, array_keys($listed)));
		sort($strays, SORT_STRING);
		if ($strays) {
			$problems[] = 'sitemap.xml names noindexed page(s): ' . implode(', ', $strays);
		}
		if ($problems) {
			return ['a page changed whether search engines index it', ...$problems];
		}
		$site->note(
			count($pages) . ' pages, ' . count($noindexed) . ' noindexed, ' . count($listed) . ' in sitemap.xml'
		);
		return [];
	}

	/** @return string[] */
	private static function ogImage(Site $site): array {
		// The card a shared link shows has to be an absolute URL -- read by something that never saw the
		// page -- AND has to name a file this build actually wrote. Either half alone passes while the
		// card is broken: WikiSEO builds the URL under the upload path, and storeImages moves the file.
		$missing = [];
		$relative = [];
		$seen = 0;
		$base = $site->base();
		foreach ($site->htmlFiles() as $path) {
			$head = explode('</head>', $site->read($path), 2)[0];
			$found = [];
			if (!preg_match('~<meta property="og:image" content="([^"]*)"~', $head, $found)) {
				continue;
			}
			$seen++;
			$url = $found[1];
			if (!self::isAbsolute($url)) {
				$relative[] = "$path -> $url";
			} elseif (str_starts_with($url, $base) && !is_file($site->path(substr($url, strlen($base))))) {
				$missing[] = "$path -> $url";
			}
		}
		if ($relative) {
			return ['og:image is not an absolute URL on ' . count($relative) . ' page(s): ' . self::few($relative)];
		}
		if ($missing) {
			return [
				'og:image names a file the build did not write, on '
					. count($missing)
					. ' page(s): '
					. self::few($missing)
			];
		}
		if (!$seen) {
			return ['no page carries an og:image; the site names a social image, so one is expected'];
		}
		$site->note("og:image is absolute and points at a written file on $seen page(s)");
		return [];
	}

	/** @return string[] */
	private static function hotlinkHost(Site $site): array {
		// A picture this site borrows from a repository is downloaded and republished, and what says the
		// export stopped depending on it is that nothing still names the host. A reference the rewrite
		// does not match is the failure nobody hears about: a schema.org block spells the same URL
		// "https:\/\/upload.wikimedia.org\/...", which went through untouched for a while.
		$guilty = [];
		foreach ($site->filesEndingIn('') as $path) {
			$text = $site->read($path);
			if (str_contains(substr($text, 0, 8192), "\0")) {
				continue;
			}
			if (str_contains($text, self::HOTLINK_HOST)) {
				$guilty[] = $path;
			}
		}
		if ($guilty) {
			return [
				'the export still names ' . self::HOTLINK_HOST . '; a picture reference was not rewritten',
				...$guilty
			];
		}
		return [];
	}

	/**
	 * Every address a page's markup names, including the ones inside a schema.org block.
	 *
	 * @return string[]
	 */
	private static function htmlUrls(string $text): array {
		$urls = [];
		$found = [];
		preg_match_all('~(?:href|src|content)="([^"]*)"~', $text, $found);
		$urls = $found[1];
		preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $text, $found);
		foreach ($found[1] as $block) {
			$parsed = json_decode($block, true);
			if ($parsed === null) {
				continue;
			}
			$stack = [$parsed];
			while ($stack) {
				$node = array_pop($stack);
				if (is_array($node)) {
					foreach ($node as $value) {
						$stack[] = $value;
					}
				} elseif (is_string($node)) {
					$urls[] = $node;
				}
			}
		}
		return $urls;
	}

	/** @return string[] */
	private static function buildHost(Site $site): array {
		// Nothing a reader is handed may name the machine that built it. The build installs against
		// http://localhost:4000, so any address reaching the output through $wgServer is the
		// container's; two places had one, green across every other check. Scoped to the head, because
		// docs/Deploying.wikitext tells a reader to open the preview at a local address.
		$bad = [];
		foreach ($site->filesEndingIn('') as $path) {
			$isHtml = str_ends_with($path, '.html');
			if (!$isHtml && !preg_match('~\.(?:js|css|json|xml)$~', $path)) {
				continue;
			}
			$text = $site->read($path);
			if ($isHtml) {
				$text = explode('</head>', $text, 2)[0];
			}
			$named = false;
			foreach (self::LOCAL_HOSTS as $host) {
				if (str_contains($text, $host)) {
					$named = true;
					break;
				}
			}
			if (!$named) {
				continue;
			}
			$candidates = [];
			if ($isHtml) {
				$candidates = self::htmlUrls($text);
			} else {
				$found = [];
				preg_match_all('~https?://[^\s"\'<>,)]+~', $text, $found);
				$candidates = $found[0];
			}
			foreach ($candidates as $url) {
				if (self::namesLocalHost($url)) {
					$bad[] = "$path -> $url";
				}
			}
		}
		if ($bad) {
			return ['the build host reached the output in ' . count($bad) . ' place(s): ' . self::few($bad)];
		}
		$site->note('no exported URL names the build host');
		return [];
	}

	/** @return string[] */
	private static function sitemapReproducible(Site $site): array {
		// Two bakes of one source must agree on the sitemap too; the walk behind it is a directory
		// iterator, whose order is the filesystem's rather than anything reproducible (#411).
		$mine = $site->path('sitemap.xml');
		$theirs = rtrim((string)$site->other, '/') . '/sitemap.xml';
		if (!is_file($theirs)) {
			return ["$theirs does not exist, so there is no second sitemap to compare against"];
		}
		if ($site->read($mine) !== $site->read($theirs)) {
			return ["$mine and $theirs differ; the sitemap is not reproducible (#411)"];
		}
		return [];
	}

	/** @return string[] */
	private static function cssLoadPhp(Site $site): array {
		// Dumped CSS must not reference load.php (a live-only endpoint); a leftover is an
		// AssetLocalizer regression.
		$guilty = [];
		foreach ($site->filesEndingIn('.css') as $path) {
			if (str_contains($site->read($path), 'load.php')) {
				$guilty[] = $path;
			}
		}
		if ($guilty) {
			return ['dumped CSS still references load.php (AssetLocalizer regression):', ...$guilty];
		}
		return [];
	}

	/** @return string[] */
	private static function specialSearchLink(Site $site): array {
		// The export has no Special:Search, so a link to it answers 404 (#393). Vector writes one for
		// the collapsed search box, on every page it renders, and the results page is where that
		// belongs; the hidden "title" input naming the special page is left to SifterSearch, which
		// repoints it, so this matches the link alone.
		$guilty = [];
		foreach ($site->htmlFiles() as $path) {
			if (str_contains($site->read($path), 'Special:Search.html')) {
				$guilty[] = $path;
			}
		}
		if ($guilty) {
			return ['exported HTML links to Special:Search, which the export does not have:', ...$guilty];
		}
		return [];
	}

	/** @return string[] */
	private static function pagefindBundles(Site $site): array {
		// A skin copy reads the search index out of its own directory, which is what keeps a search
		// from the skin a reader chose inside that skin's copy (#399). The bundle is put there by the
		// skin pass; without it the copy's search answers nothing at all, and only the browser tests
		// would notice.
		$problems = [];
		foreach (['', ...( (array)$site->expect['skin_copies'] )] as $copy) {
			$root = $copy === '' ? $site->dist : $site->path($copy);
			$bundle = "$root/pagefind/pagefind.js";
			if (!is_file($bundle) || filesize($bundle) === 0) {
				$problems[] = "$root has no Pagefind bundle of its own";
			}
		}
		return $problems;
	}

	/** @return string[] */
	private static function pagefindLanguages(Site $site): array {
		// Each page is indexed in the language it is written in, so Pagefind builds one index per
		// language and a reader searching from a translated page is answered out of that language's
		// index (#400). A single index meant every translation was stemmed by English rules.
		$metas = $site->glob($site->path('pagefind', '*.pf_meta'));
		if (!$metas) {
			return [$site->path('pagefind') . ' holds no search index at all'];
		}
		$site->note('search indexes built: ' . implode(', ', array_map('basename', $metas)));

		$fragments = $site->glob($site->path('pagefind', 'fragment', '*'));
		if (!$fragments) {
			return [$site->path('pagefind', 'fragment') . ' holds no indexed pages'];
		}
		$counts = [];
		foreach ($fragments as $fragment) {
			$language = explode('_', basename($fragment))[0];
			$counts[$language] = ( $counts[$language] ?? 0 ) + 1;
		}
		ksort($counts);
		$written = [];
		foreach ($counts as $language => $count) {
			$written[] = "$language $count";
		}
		$site->note('indexed pages per language: ' . implode(', ', $written));

		$problems = [];
		foreach ((array)$site->expect['search_languages'] as $language) {
			if (!$site->glob($site->path('pagefind', "pagefind.{$language}_*.pf_meta"))) {
				$problems[] =
					$site->path('pagefind') . " has no $language index; pages are not indexed in their own language";
			}
		}
		return $problems;
	}

	/** @return string[] */
	private static function pagefindSourceLanguage(Site $site): array {
		// Marking a page for translation gives it a translation page in the source language too, so
		// English was counted twice (#454). What says only the source page is indexed now is the
		// absence of any "/en.html" from the English index; a count would not, the pages indexed not
		// being the source files.
		$language = (string)$site->expect['source_language'];
		$pattern = '~"url":"[^"]*/' . preg_quote($language, '~') . '\.html"~';
		foreach ($site->glob($site->path('pagefind', 'fragment', "{$language}_*")) as $fragment) {
			$raw = $site->read($fragment);
			// Pagefind gzips a fragment; asked by its magic bytes rather than by trying, so a
			// fragment that is not gzipped is read as it is rather than through a warning.
			if (str_starts_with($raw, "\x1f\x8b")) {
				$plain = gzdecode($raw);
				if ($plain !== false) {
					$raw = $plain;
				}
			}
			if (preg_match($pattern, $raw)) {
				return [
					"the $language index holds a /$language.html page, which restates its source page",
					'a source-language translation page is being indexed beside its source'
				];
			}
		}
		return [];
	}

	/** @return string[] */
	private static function sortableTable(Site $site): array {
		// A sortable table needs jquery.tablesorter, and core does not queue it while rendering:
		// mediawiki.page.ready looks for table.sortable in the browser and fetches it from load.php,
		// which an export does not have (#483). The build makes that decision instead.
		$page = $site->path((string)$site->expect['sortable_page']);
		if (!is_file($page) || !preg_match('~class="[^"]*\bsortable\b~', $site->read($page))) {
			return ["$page no longer has a sortable table for this to be about"];
		}
		$bundle = $site->path('assets', 'modules-static.js');
		if (!is_file($bundle) || !str_contains($site->read($bundle), 'jquery.tablesorter')) {
			return [
				'a page has a sortable table and jquery.tablesorter is not in the bundle',
				'the table will publish with headers that do nothing'
			];
		}
		return [];
	}

	/** @return string[] */
	private static function skinModules(Site $site): array {
		// A skin the site never asked for has no business in the script every reader downloads: the
		// installer used to enable every skin on disk, and MonoBook and Timeless rode into each
		// bundle (#637). Read out of the startup manifest, which is what the bundle is built from.
		$expected = (array)$site->expect['skin_modules'];
		sort($expected, SORT_STRING);
		$manifests = [
			$site->path('assets', 'startup-static.js'),
			...$site->glob($site->path('*', 'assets', 'startup-static.js'))
		];
		$problems = [];
		foreach ($manifests as $manifest) {
			if (!is_file($manifest)) {
				continue;
			}
			$found = [];
			preg_match_all('~skins\.[a-z0-9-]*~', $site->read($manifest), $found);
			$names = array_values(array_unique($found[0]));
			sort($names, SORT_STRING);
			if ($names !== $expected) {
				$problems[] =
					"$manifest registers skin modules for ["
					. implode(' ', $names)
					. ']; this site builds ['
					. implode(' ', $expected)
					. ']';
			}
		}
		return $problems;
	}

	/** @return string[] */
	private static function luaModules(Site $site): array {
		// A Lua module runs at build time and its answer is baked into the page that invoked it
		// (#465). Module:Example answers with the title of the page it ran on, so this says both that
		// Scribunto rendered at all and that the module saw which page it was on, per language.
		$problems = [];
		$pages = (array)$site->expect['lua_pages'];
		ksort($pages);
		foreach ($pages as $page => $title) {
			$path = $site->path((string)$page);
			if (!is_file($path) || !str_contains($site->read($path), "<code>$title</code>")) {
				$problems[] = "$path does not carry Module:Example's answer ($title)";
			}
		}
		// And the module itself is not a page of the site: Module: is not a content namespace, so
		// nothing should export the Lua source. Without Scribunto it does, percent-encoded.
		$exported = $site->glob($site->path('Module*'));
		if ($exported) {
			$problems[] = 'the Lua module was exported as a page: ' . implode(', ', $exported);
		}
		return $problems;
	}

	/** @return string[] */
	private static function printfooterLinks(Site $site): array {
		// Every printfooter "Retrieved from" link must resolve from the page that carries it (#394).
		// Core builds it from the page's own URL and expands away the "./" wikven writes, so a page
		// exported into a subdirectory needs the "../" per level rename.php adds; the link is
		// print-only on screen, which is exactly why nothing else would catch it.
		$problems = [];
		foreach ($site->htmlFiles() as $page) {
			// Line by line and last match wins, which is what the sed this replaces did.
			$href = '';
			foreach (explode("\n", $site->read($page)) as $line) {
				$found = [];
				if (preg_match('~.*class="printfooter"[^<]*<a[^>]*href="([^"]*)"~', $line, $found)) {
					$href = $found[1];
				}
			}
			if ($href === '') {
				continue;
			}
			if (str_contains($href, '://') || str_starts_with($href, '/') || str_starts_with($href, '#')) {
				continue;
			}
			if (!is_file(dirname($page) . '/' . $href)) {
				$problems[] = "$page: printfooter link '$href' does not resolve";
			}
		}
		return $problems;
	}

	/** @return string[] */
	private static function headLinks(Site $site): array {
		// Every address a page names for itself has to be one the export actually has (#394 again, one
		// layer out). A canonical url and an hreflang alternate are whole urls, so nothing in the export
		// resolves them -- and one alternate pointing at a 404 loses the whole set it belongs to.
		$hrefs = [];
		foreach ($site->htmlFiles() as $page) {
			$tags = [];
			preg_match_all('~<link rel="(?:canonical|alternate)"[^>]*>~', $site->read($page), $tags);
			foreach ($tags[0] as $tag) {
				$found = [];
				$hrefs[preg_match('~.*href="([^"]*)"~', $tag, $found) ? $found[1] : $tag] = true;
			}
		}
		if (!$hrefs) {
			return ['no exported page carries a canonical or alternate link'];
		}
		$base = $site->base();
		$problems = [];
		$names = array_keys($hrefs);
		sort($names, SORT_STRING);
		foreach ($names as $href) {
			if (!str_starts_with($href, $base)) {
				$problems[] = "head link '$href' is not an address under $base";
			} elseif (!is_file($site->path(substr($href, strlen($base))))) {
				$problems[] = "head link '$href' names a page the export does not have";
			}
		}
		return $problems;
	}

	/** @return string[] */
	private static function canonicalCoverage(Site $site): array {
		// And every page carries one, which is what settles the extension-less address a host serves
		// beside the .html one, and the skin copies, in a single tag.
		$pages = $site->htmlFiles();
		$without = [];
		foreach ($pages as $page) {
			if (!str_contains($site->read($page), 'rel="canonical"')) {
				$without[] = $page;
			}
		}
		if ($without) {
			$carrying = count($pages) - count($without);
			return [
				"$carrying of " . count($pages) . ' exported pages say which address is theirs',
				'without one: ' . self::few($without, 5)
			];
		}
		return [];
	}

	/** @return string[] */
	private static function webfonts(Site $site): array {
		// Bundled webfonts. A translation in a script ULS has a font for is the fixture: without
		// one, every assertion below passes vacuously on an empty stylesheet.
		$stylesheet = $site->path('assets', 'webfonts.css');
		if (!is_file($stylesheet) || filesize($stylesheet) === 0) {
			return ["$stylesheet is missing or empty"];
		}
		$css = $site->read($stylesheet);
		$language = (string)$site->expect['webfont_language'];
		$problems = [];
		if (!str_contains($css, ":lang($language)")) {
			$problems[] = "$stylesheet has no :lang($language) rule, so that script gets no font";
		}
		// The font files themselves are copied to a fixed directory at the output root, not into the
		// asset directory, so the stylesheet steps up out of it to reach them.
		$directory = (string)$site->expect['webfont_directory'];
		if (!$site->glob($site->path('fonts', 'uls', $directory, '*.woff2'))) {
			$problems[] = $site->path('fonts', 'uls', $directory) . ' holds no .woff2 file';
		}
		// Every url() must resolve from the stylesheet's own directory, which is what lets the fonts
		// load from a page at any depth. Resolved against that directory and not against the output
		// root, which is the whole point when the two are not the same.
		$found = [];
		preg_match_all("~url\('([^']*)'\)~", $css, $found);
		$references = array_values(array_unique($found[1]));
		sort($references, SORT_STRING);
		foreach ($references as $reference) {
			if (!is_file(dirname($stylesheet) . '/' . $reference)) {
				$problems[] = "webfonts.css references missing $reference";
			}
		}
		return $problems;
	}

	/** @return string[] */
	private static function languageBars(Site $site): array {
		// Every <languages/> bar lists every language the source has (#333 baked them short). A page
		// with N languages is emitted N+1 times and each copy carries N entries, so the target is
		// derived from the source tree rather than pinned to a number.
		$source = rtrim((string)$site->source, '/');
		$expected = 0;
		foreach ($site->glob("$source/*.wikitext") as $path) {
			if (!preg_match('~<languages */>~', $site->read($path))) {
				continue;
			}
			$translations = $source . '/' . basename($path, '.wikitext');
			$languages = 1 + count($site->glob("$translations/*.wikitext"));
			$expected += $languages * ( $languages + 1 );
		}
		// Once per skin the site renders: the default plus the copies the site configuration adds.
		$expected *= 1 + count((array)$site->expect['skin_copies']);
		$found = 0;
		foreach ($site->htmlFiles() as $page) {
			$found += substr_count($site->read($page), 'mw-pt-progress--');
		}
		if ($found !== $expected) {
			return ["$found language-bar entries across the export, expected $expected"];
		}
		$site->note("$found language-bar entries, as the source calls for");
		return [];
	}

	/**
	 * The page titles MediaWiki:Sidebar names, in the order it names them.
	 *
	 * @return string[]
	 */
	private static function sidebarPages(Site $site): array {
		$source = rtrim((string)$site->source, '/') . '/MediaWiki:Sidebar.wikitext';
		$pages = [];
		foreach (explode("\n", $site->read($source)) as $line) {
			$found = [];
			if (preg_match('~^\*\* *Special:MyLanguage/([^|]*)~', $line, $found)) {
				$pages[] = $found[1];
			}
		}
		return $pages;
	}

	/** @return string[] */
	private static function prevnextRows(Site $site): array {
		// Every page the sidebar names gets a navigation row, and the two ends of the sequence get one
		// link rather than two. The order lives in MediaWiki:Sidebar and Module:Sequence reads it
		// (#455); before that it was restated in each call, and both ends went wrong.
		$missing = [];
		foreach (self::sidebarPages($site) as $page) {
			$path = $site->path(str_replace(' ', '_', $page) . '.html');
			// The link div, not the wrapper: prevnext always emits the wrapper, so matching that
			// passes an empty row -- which is exactly what a broken module produces, and what the
			// first draft of this check waved through.
			if (!is_file($path) || filesize($path) === 0) {
				$missing[] = $page;
			} elseif (!str_contains($site->read($path), 'class="wikven-prevnext-')) {
				$missing[] = $page;
			}
		}
		if ($missing) {
			return ['sidebar pages with no navigation row: ' . implode(' ', $missing)];
		}
		return [];
	}

	/** @return string[] */
	private static function prevnextLabel(Site $site): array {
		// A prevnext link is labelled with the target page's own title, so on a translated page it must
		// carry the translated one. The label is not in the calling page's source: the call sits outside
		// the translate tags, and the template reads the target's own title unit.
		$page = (string)$site->expect['prevnext_page'];
		$language = (string)$site->expect['prevnext_language'];
		$order = self::sidebarPages($site);
		$at = array_search($page, $order, true);
		// Checked before the file is read: reading a path built from an empty name would fail with
		// an error of its own, which says nothing about the sidebar being what went wrong.
		if ($at === false || ( $at + 1 ) >= count($order)) {
			return ["MediaWiki:Sidebar names no page after $page, so this check has no pair to make"];
		}
		$following = $order[$at + 1];

		$translation = rtrim((string)$site->source, '/') . "/$following/$language.wikitext";
		$want = '';
		$seenTitleUnit = false;
		if (is_file($translation)) {
			foreach (explode("\n", $site->read($translation)) as $line) {
				$line = rtrim($line, "\r");
				if (preg_match('~^<!--T:title[ -]~', $line)) {
					$seenTitleUnit = true;
					continue;
				}
				if ($seenTitleUnit && trim($line) !== '') {
					$want = $line;
					break;
				}
			}
		}
		if ($want === '') {
			return ["$translation has no translated title to expect"];
		}

		// Newlines are flattened before the block is read: the template writes its markup over
		// several lines, and what is being matched is one span of it. An empty match fails the case
		// below rather than passing it, so a prevnext that stopped rendering is caught here too.
		$rendered = $site->path($page, "$language.html");
		$flat = is_file($rendered) ? str_replace("\n", ' ', $site->read($rendered)) : '';
		$found = [];
		$shown = preg_match('~wikven-prevnext".{0,700}~', $flat, $found) ? $found[0] : '';
		if (!str_contains($shown, $want)) {
			return [
				"$page/$language's prevnext does not show $following's $language title ($want)",
				$shown
			];
		}
		return [];
	}
}
