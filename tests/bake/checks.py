"""What a finished bake has to be true of, one function per fact.

Each check reads a baked site and returns the problems it found, as sentences a reader can act on.
None of them knows it is running on a CI runner: a check that wants to be loud says so by returning
a problem, and assert_bake.py decides whether that becomes a line of prose or a workflow command.

A check may also ask for input beyond the site itself -- the source tree it was baked from, the
logs the bake wrote, a second bake to compare against -- by naming it in ``needs``. Given none, the
runner reports the check skipped rather than passed, because a check that quietly passes when its
input is missing is the kind that stops being a check without anyone noticing.
"""

from __future__ import annotations

import glob
import gzip
import json
import os
import re
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field
from typing import Callable
from urllib.parse import unquote, urlparse

SITEMAP_NS = {'s': 'http://www.sitemaps.org/schemas/sitemap/0.9'}

# The addresses a build host answers on. Any of them in the output names the machine that ran the
# bake rather than the site, which is a different site for every reader.
LOCAL_HOSTS = {'localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'}


@dataclass
class Site:
    """A baked site and the expectations it is being read against."""

    dist: str
    # What is this site's rather than wikven's: where it is published, which pages it keeps out of
    # the index, which skins it builds. Read from a file so the checks below hold for any site.
    expect: dict
    # The source tree the site was baked from. Three checks derive what to expect from it rather
    # than restating it, so that adding a page or a translation moves the target with it.
    source: str | None = None
    # What the bake said while it ran.
    logs: list[str] = field(default_factory=list)
    # A second bake of the same source, for the checks that compare the two.
    other: str | None = None
    # Anything a check wants to say about what it found, whether or not it found a problem.
    notes: list[str] = field(default_factory=list)

    @property
    def base(self) -> str:
        """The published base URL, with the single trailing slash the checks all assume."""
        return self.expect['site_url'].rstrip('/') + '/'

    def path(self, *parts: str) -> str:
        return os.path.join(self.dist, *parts)

    def html_files(self) -> list[str]:
        return sorted(glob.glob(self.path('**', '*.html'), recursive=True))

    def read(self, path: str) -> str:
        with open(path, encoding='utf-8', errors='replace') as handle:
            return handle.read()

    def note(self, line: str) -> None:
        self.notes.append(line)


@dataclass
class Check:
    name: str
    summary: str
    run: Callable[[Site], list[str]]
    needs: tuple[str, ...]


CHECKS: list[Check] = []


def check(name: str, summary: str, needs: tuple[str, ...] = ()) -> Callable:
    """Register a check. Order of registration is the order of the report."""

    def register(function: Callable[[Site], list[str]]) -> Callable:
        CHECKS.append(Check(name, summary, function, needs))
        return function

    return register


@check('index-page', 'the main page landed at the root of the export')
def index_page(site: Site) -> list[str]:
    # The main page must land at the dist root (a failed import already aborted the build).
    path = site.path('index.html')
    if not os.path.isfile(path) or os.path.getsize(path) == 0:
        return [f'{path} is missing or empty']
    return []


@check('bake-warnings', 'the bake warned about nothing in the site configuration', needs=('logs',))
def bake_warnings(site: Site) -> list[str]:
    # A bake with something to say about the site's own configuration says it and carries on, so a
    # warning is only ever seen by whoever reads the log. The site's .wikven.yaml is the
    # configuration wikven recommends; a warning about it is a mistake either in that file or in
    # the code that reports on it, and both are worth stopping for.
    warnings = [
        line
        for log in site.logs
        for line in site.read(log).splitlines()
        if 'Wikven: WARNING' in line
    ]
    if warnings:
        return ['the bake warned about the site configuration:', *warnings]
    return []


@check('sitemap', 'the sitemap names absolute addresses, all under the published base')
def sitemap(site: Site) -> list[str]:
    # The site configuration says where this site is published, so the bake owes it a sitemap. The
    # bug this guards against fataled the whole build and shipped anyway, because the code path
    # only runs for a site that sets WikvenSiteUrl and no test site did.
    path = site.path('sitemap.xml')
    if not os.path.isfile(path) or os.path.getsize(path) == 0:
        return [f'{path} is missing or empty']
    locs = [element.text or '' for element in ET.parse(path).getroot().findall('s:url/s:loc', SITEMAP_NS)]
    if not locs:
        return ['sitemap.xml names no pages']
    # The protocol requires absolute URLs, and getting a relative one is the failure that looks
    # fine in a diff: core's own generateSitemap.php emits "index.html" under wikven.
    bad = [url for url in locs if urlparse(url).scheme not in ('http', 'https')]
    if bad:
        return [f'sitemap.xml has non-absolute URLs: {bad[:5]}']
    if any(not url.startswith(site.base) for url in locs):
        return ['sitemap.xml names URLs outside the published base']
    # A skin copy is noindex, so naming one would ask a crawler to index what the page denies.
    copies = set(site.expect['skin_copies'])
    inside = [url for url in locs if url[len(site.base):].split('/')[0] in copies]
    if inside:
        return [f'sitemap.xml names skin-preview copies: {inside[:5]}']
    site.note(f'sitemap.xml: {len(locs)} absolute URLs')
    return []


@check('og-url', 'every og:url is a whole address')
def og_url(site: Site) -> list[str]:
    # og:url has to be a whole URL: it is read off the page by something that never saw the page's
    # address. Title::getFullURL() answers the GetFullURL hook, so this is the tag that proves that
    # hook fired -- before it existed WikiSEO published "http:index.html" here, green across every
    # other check.
    bad = []
    for path in site.html_files():
        found = re.search(r'<meta property="og:url" content="([^"]*)"', site.read(path))
        if found and urlparse(found.group(1)).scheme not in ('http', 'https'):
            bad.append((path, found.group(1)))
    if bad:
        return [f'og:url is not an absolute URL on {len(bad)} page(s): {bad[:3]}']
    site.note('og:url is absolute on every page that carries one')
    return []


@check('noindex', 'the pages search engines are told to skip are the ones this site names')
def noindex(site: Site) -> list[str]:
    # A page that stops being indexed is invisible by construction: nothing 404s, nothing reads
    # wrong, and the only place it shows is a crawler's view of the site weeks later. __NOINDEX__
    # is a behaviour switch, so a page that writes the word while telling a reader to use it takes
    # it -- Deploying noindexed itself, in both languages, and left the sitemap, with every gate
    # here green (#655). It is written through <nowiki> now.
    #
    # Read off the rendered pages rather than the sources, because the source is where the word
    # legitimately appears, and named rather than counted, because a count passes one page swapping
    # for another and the swap is what nobody would read.
    #
    # The sitemap is the other half of the same fact and the build derives it separately, so the
    # two have to agree with the list and with each other. That half also catches a page leaving
    # the sitemap for a reason that has nothing to do with noindex.
    expected = set(site.expect['noindex_pages'])
    copies = set(site.expect['skin_copies'])

    pages, noindexed, indexable_copies = set(), set(), {}
    for path in site.html_files():
        page = os.path.relpath(path, site.dist)
        tag = re.search(r'<meta name="robots" content="([^"]*)"', site.read(path))
        is_noindex = bool(tag and 'noindex' in tag.group(1))
        copy = page.split(os.sep)[0]
        if copy in copies:
            # A skin copy is noindex wholesale, so nobody arrives from a search result reading the
            # site in a skin they never picked.
            if not is_noindex:
                indexable_copies.setdefault(copy, []).append(page)
            continue
        pages.add(page)
        if is_noindex:
            noindexed.add(page)

    listed = {
        unquote(loc.text[len(site.base):])
        for loc in ET.parse(site.path('sitemap.xml')).getroot().findall('s:url/s:loc', SITEMAP_NS)
        if (loc.text or '').startswith(site.base)
    }

    problems = []
    for copy, indexable in sorted(indexable_copies.items()):
        problems.append(f'{copy}/ has {len(indexable)} indexable page(s): {sorted(indexable)[:5]}')
    if noindexed != expected:
        problems.append(f'the site noindexes {sorted(noindexed)}, expected {sorted(expected)}')
    missing = pages - listed - expected
    if missing:
        problems.append(f'{len(missing)} indexable page(s) are not in sitemap.xml: {sorted(missing)[:5]}')
    strays = listed & expected
    if strays:
        problems.append(f'sitemap.xml names noindexed page(s): {sorted(strays)}')
    if problems:
        return ['a page changed whether search engines index it', *problems]
    site.note(f'{len(pages)} pages, {len(noindexed)} noindexed, {len(listed)} in sitemap.xml')
    return []


@check('og-image', 'the card a shared link shows names a file this build wrote')
def og_image(site: Site) -> list[str]:
    # The card a shared link shows has to be an absolute URL -- read by something that never saw
    # the page -- AND has to name a file this build actually wrote. Both halves are asserted,
    # because either alone passes while the card is broken: WikiSEO builds the URL under
    # MediaWiki's upload path, which the export does not serve, and storeImages moves it to the
    # published file. Get one half of that wrong and the tag is either a relative URL no crawler
    # can resolve or an absolute one pointing at nothing.
    missing, relative, seen = [], [], 0
    for path in site.html_files():
        head = site.read(path).split('</head>', 1)[0]
        found = re.search(r'<meta property="og:image" content="([^"]*)"', head)
        if not found:
            continue
        seen += 1
        url = found.group(1)
        if urlparse(url).scheme not in ('http', 'https'):
            relative.append((path, url))
        elif url.startswith(site.base) and not os.path.isfile(site.path(url[len(site.base):])):
            missing.append((path, url))
    if relative:
        return [f'og:image is not an absolute URL on {len(relative)} page(s): {relative[:3]}']
    if missing:
        return [f'og:image names a file the build did not write, on {len(missing)} page(s): {missing[:3]}']
    if not seen:
        return ['no page carries an og:image; the site names a social image, so one is expected']
    site.note(f'og:image is absolute and points at a written file on {seen} page(s)')
    return []


def _html_urls(text: str):
    yield from re.findall(r'(?:href|src|content)="([^"]*)"', text)
    for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>', text, re.S):
        try:
            parsed = json.loads(block)
        except ValueError:
            continue
        stack = [parsed]
        while stack:
            node = stack.pop()
            if isinstance(node, dict):
                stack.extend(node.values())
            elif isinstance(node, list):
                stack.extend(node)
            elif isinstance(node, str):
                yield node


@check('build-host', 'nothing a reader is handed names the machine that built it')
def build_host(site: Site) -> list[str]:
    # Nothing a reader is handed may name the machine that built it. The build installs against
    # http://localhost:4000 and every step is a maintenance script, so any address that reaches the
    # output through $wgServer is the container's, not the site's. Two places had one, green across
    # every other check: WikiSEO named it as the site's author and publisher in the JSON-LD of
    # every page, and mw.config shipped it as wgServer in the module bundle, disagreeing with the
    # wgServerName beside it.
    #
    # Scoped to the head, because "http://localhost:8080" is also a correct sentence:
    # docs/Deploying.wikitext tells a reader to open the preview there, and wikitext autolinks it,
    # so the body legitimately holds an anchor to a local address. The head is machine-read
    # metadata about the site and can hold no such sentence. The generated bundles are searched
    # whole; they hold no prose either.
    bad = []
    for path in glob.glob(site.path('**', '*'), recursive=True):
        if not path.endswith(('.html', '.js', '.css', '.json', '.xml')):
            continue
        text = site.read(path)
        if path.endswith('.html'):
            text = text.split('</head>', 1)[0]
        if not any(host in text for host in LOCAL_HOSTS):
            continue
        if path.endswith('.html'):
            found = [url for url in _html_urls(text) if urlparse(url).hostname in LOCAL_HOSTS]
        else:
            found = [
                match
                for match in re.findall(r'https?://[^\s"\'<>,)]+', text)
                if urlparse(match).hostname in LOCAL_HOSTS
            ]
        bad.extend((path, url) for url in found)
    if bad:
        return [f'the build host reached the output in {len(bad)} place(s): {bad[:3]}']
    site.note('no exported URL names the build host')
    return []


@check('sitemap-reproducible', 'two bakes of one source agree on the sitemap', needs=('other',))
def sitemap_reproducible(site: Site) -> list[str]:
    # Two bakes of one source must agree on the sitemap too; the walk behind it is a directory
    # iterator, whose order is the filesystem's rather than anything reproducible (#411).
    mine, theirs = site.path('sitemap.xml'), os.path.join(site.other or '', 'sitemap.xml')
    if not os.path.isfile(theirs):
        return [f'{theirs} does not exist, so there is no second sitemap to compare against']
    with open(mine, 'rb') as first, open(theirs, 'rb') as second:
        if first.read() != second.read():
            return [f'{mine} and {theirs} differ; the sitemap is not reproducible (#411)']
    return []


@check('css-load-php', 'the dumped stylesheets stand on their own')
def css_load_php(site: Site) -> list[str]:
    # Dumped CSS must not reference load.php (a live-only endpoint); a leftover is an
    # AssetLocalizer regression.
    guilty = [
        path
        for path in glob.glob(site.path('**', '*.css'), recursive=True)
        if re.search(r'load\.php', site.read(path))
    ]
    if guilty:
        return ['dumped CSS still references load.php (AssetLocalizer regression):', *guilty]
    return []


@check('special-search-link', 'no page links to a special page the export does not have')
def special_search_link(site: Site) -> list[str]:
    # The export has no Special:Search, so a link to it answers 404 (#393). Vector writes one for
    # the collapsed search box, on every page it renders, and the results page is where that
    # belongs; the hidden "title" input naming the special page is left to SifterSearch, which
    # repoints it, so this matches the link alone.
    guilty = [path for path in site.html_files() if 'Special:Search.html' in site.read(path)]
    if guilty:
        return ['exported HTML links to Special:Search, which the export does not have:', *guilty]
    return []


@check('pagefind-bundles', 'each skin copy carries a search bundle of its own')
def pagefind_bundles(site: Site) -> list[str]:
    # A skin copy reads the search index out of its own directory, which is what keeps a search
    # from the skin a reader chose inside that skin's copy (#399). The bundle is put there by the
    # skin pass; without it the copy's search answers nothing at all, and only the browser tests
    # would notice.
    problems = []
    for copy in ['', *site.expect['skin_copies']]:
        root = site.path(copy) if copy else site.dist
        bundle = os.path.join(root, 'pagefind', 'pagefind.js')
        if not os.path.isfile(bundle) or os.path.getsize(bundle) == 0:
            problems.append(f'{root} has no Pagefind bundle of its own')
    return problems


@check('pagefind-languages', 'each language the site is written in has a search index')
def pagefind_languages(site: Site) -> list[str]:
    # Each page is indexed in the language it is written in, so Pagefind builds one index per
    # language and a reader searching from a translated page is answered out of that language's
    # index (#400). A single index means every translation was stamped with the wiki's content
    # language and stemmed by English rules, which is what this site looked like until SifterSearch
    # indexed per page rather than per wiki.
    metas = sorted(glob.glob(site.path('pagefind', '*.pf_meta')))
    if not metas:
        return [f'{site.path("pagefind")} holds no search index at all']
    site.note('search indexes built: ' + ', '.join(os.path.basename(meta) for meta in metas))

    fragments = sorted(glob.glob(site.path('pagefind', 'fragment', '*')))
    if not fragments:
        return [f'{site.path("pagefind", "fragment")} holds no indexed pages']
    counts: dict[str, int] = {}
    for fragment in fragments:
        language = os.path.basename(fragment).split('_')[0]
        counts[language] = counts.get(language, 0) + 1
    site.note('indexed pages per language: ' + ', '.join(f'{k} {v}' for k, v in sorted(counts.items())))

    problems = []
    for language in site.expect['search_languages']:
        if not glob.glob(site.path('pagefind', f'pagefind.{language}_*.pf_meta')):
            problems.append(
                f'{site.path("pagefind")} has no {language} index; '
                'pages are not indexed in their own language'
            )
    return problems


@check('pagefind-source-language', 'no source page is indexed twice under its own language')
def pagefind_source_language(site: Site) -> list[str]:
    # Marking a page for translation gives it a translation page in the source language too, so
    # "Installation" and "Installation/en" hold the same English text under two titles and English
    # was counted twice for every translated page (#454). Only the source page is indexed now, and
    # what says so is the absence of any "/en.html" from the English index -- a count would not,
    # since the pages indexed are not the source files: the build generates Licenses and Settings,
    # which have no files, and the search results page has a file but is not indexed.
    # Those two cancelled out the day this was written, so a count read off the source tree would
    # have passed by luck and broken on the next page added.
    #
    # This reads Pagefind's fragments, which are gzipped JSON carrying the page's url.
    language = site.expect['source_language']
    pattern = re.compile(rf'"url":"[^"]*/{re.escape(language)}\.html"')
    for fragment in sorted(glob.glob(site.path('pagefind', 'fragment', f'{language}_*'))):
        with open(fragment, 'rb') as handle:
            raw = handle.read()
        try:
            raw = gzip.decompress(raw)
        except OSError:
            pass
        if pattern.search(raw.decode('utf-8', 'replace')):
            return [
                f'the {language} index holds a /{language}.html page, which restates its source page',
                'a source-language translation page is being indexed beside its source',
            ]
    return []


@check('sortable-table', 'the module a sortable table needs is in the bundle')
def sortable_table(site: Site) -> list[str]:
    # A sortable table needs jquery.tablesorter, and core does not queue it while rendering:
    # mediawiki.page.ready looks for table.sortable in the browser and fetches the module from
    # load.php, which an export does not have (#483). The build makes that decision instead, so
    # what this checks is that it made it -- the module has to be in the bundle, and the page that
    # asked for it has to still be there to ask.
    page = site.path(site.expect['sortable_page'])
    if not os.path.isfile(page) or not re.search(r'class="[^"]*\bsortable\b', site.read(page)):
        return [f'{page} no longer has a sortable table for this to be about']
    bundle = site.path('assets', 'modules-static.js')
    if not os.path.isfile(bundle) or 'jquery.tablesorter' not in site.read(bundle):
        return [
            'a page has a sortable table and jquery.tablesorter is not in the bundle',
            'the table will publish with headers that do nothing',
        ]
    return []


@check('skin-modules', 'the bundle registers the skins this site builds and no others')
def skin_modules(site: Site) -> list[str]:
    # A skin the site never asked for has no business in the script every reader downloads. The
    # installer used to enable every skin it found on disk, and MonoBook and Timeless rode into
    # each bundle and onto the licenses page as skins this site publishes (#637). Read out of the
    # startup manifest, which is the registry the bundle is built from, and so is where a skin
    # nobody asked for shows up first. Each copy checked, not just the root: a reader of one skin
    # downloads that copy's bundle and no other.
    # Exact, rather than a list of names not to find: a check that only knows the two that went
    # wrong would pass the next one.
    expected = sorted(site.expect['skin_modules'])
    problems = []
    manifests = [site.path('assets', 'startup-static.js')]
    manifests += sorted(glob.glob(site.path('*', 'assets', 'startup-static.js')))
    for manifest in manifests:
        if not os.path.isfile(manifest):
            continue
        found = sorted(set(re.findall(r'skins\.[a-z0-9-]*', site.read(manifest))))
        if found != expected:
            problems.append(
                f'{manifest} registers skin modules for [{" ".join(found)}]; '
                f'this site builds [{" ".join(expected)}]'
            )
    return problems


@check('lua-modules', 'a Lua module ran at build time and its answer is in the page')
def lua_modules(site: Site) -> list[str]:
    # A Lua module runs at build time and its answer is baked into the page that invoked it (#465).
    # Module:Example answers with the title of the page it ran on, so this says both that Scribunto
    # rendered at all -- without it the invocation is left in the page as
    # "{{#invoke:Example|thisPage}}", which is what used to happen quietly -- and that the module
    # saw which page it was on, per language.
    # Named per page rather than derived, because the file name has underscores where the title the
    # module answers with has spaces.
    problems = []
    for page, title in sorted(site.expect['lua_pages'].items()):
        path = site.path(page)
        if not os.path.isfile(path) or f'<code>{title}</code>' not in site.read(path):
            problems.append(f'{path} does not carry Module:Example\'s answer ({title})')
    # And the module itself is not a page of the site: Module: is not a content namespace, so
    # nothing should export the Lua source. Without Scribunto it does, percent-encoded.
    exported = sorted(glob.glob(site.path('Module*')))
    if exported:
        problems.append('the Lua module was exported as a page: ' + ', '.join(exported))
    return problems


@check('printfooter-links', 'every "Retrieved from" link resolves from the page holding it')
def printfooter_links(site: Site) -> list[str]:
    # Every printfooter "Retrieved from" link must resolve from the page that carries it (#394).
    # Core builds it from the page's own URL and expands away the "./" wikven writes, so a page
    # exported into a subdirectory needs the "../" per level rename.php adds; the link is
    # print-only on screen, which is exactly why nothing else would catch it.
    problems = []
    for page in site.html_files():
        # Line by line and last match wins, which is what the sed this replaces did.
        hrefs = []
        for line in site.read(page).splitlines():
            found = re.search(r'.*class="printfooter"[^<]*<a[^>]*href="([^"]*)"', line)
            if found:
                hrefs.append(found.group(1))
        href = '\n'.join(hrefs)
        if not href:
            continue
        if '://' in href or href.startswith('/') or href.startswith('#'):
            continue
        if not os.path.isfile(os.path.join(os.path.dirname(page), href)):
            problems.append(f'{page}: printfooter link {href!r} does not resolve')
    return problems


@check('head-links', 'every address a page names for itself is one the export has')
def head_links(site: Site) -> list[str]:
    # Every address a page names for itself has to be one the export actually has (#394 again, one
    # layer out). A canonical url and an hreflang alternate are whole urls, so nothing in the
    # export resolves them and nothing else would notice one naming a page that was never written
    # -- and a single alternate pointing at a 404 is enough for a search engine to throw away the
    # whole set it belongs to.
    hrefs = set()
    for page in site.html_files():
        for tag in re.findall(r'<link rel="(?:canonical|alternate)"[^>]*>', site.read(page)):
            found = re.search(r'.*href="([^"]*)"', tag)
            hrefs.add(found.group(1) if found else tag)
    if not hrefs:
        return ['no exported page carries a canonical or alternate link']
    problems = []
    for href in sorted(hrefs):
        if not href.startswith(site.base):
            problems.append(f'head link {href!r} is not an address under {site.base}')
        elif not os.path.isfile(site.path(href[len(site.base):])):
            problems.append(f'head link {href!r} names a page the export does not have')
    return problems


@check('canonical-coverage', 'every page says which address is its own')
def canonical_coverage(site: Site) -> list[str]:
    # And every page carries one, which is what settles the extension-less address a host serves
    # beside the .html one, and the skin copies, in a single tag.
    pages = site.html_files()
    canonical = [page for page in pages if 'rel="canonical"' in site.read(page)]
    if len(pages) != len(canonical):
        without = sorted(set(pages) - set(canonical))
        return [
            f'{len(canonical)} of {len(pages)} exported pages say which address is theirs',
            'without one: ' + ', '.join(without[:5]),
        ]
    return []


@check('webfonts', 'the bundled webfonts are there and reachable from the stylesheet')
def webfonts(site: Site) -> list[str]:
    # Bundled webfonts. A translation in a script ULS has a font for is the fixture: without one,
    # every assertion below passes vacuously on an empty stylesheet.
    stylesheet = site.path('assets', 'webfonts.css')
    if not os.path.isfile(stylesheet) or os.path.getsize(stylesheet) == 0:
        return [f'{stylesheet} is missing or empty']
    css = site.read(stylesheet)
    language = site.expect['webfont_language']
    problems = []
    if f':lang({language})' not in css:
        problems.append(f'{stylesheet} has no :lang({language}) rule, so that script gets no font')
    # The font files themselves are copied to a fixed directory at the output root, not into the
    # asset directory, so the stylesheet steps up out of it to reach them.
    directory = site.expect['webfont_directory']
    if not glob.glob(site.path('fonts', 'uls', directory, '*.woff2')):
        problems.append(f'{site.path("fonts", "uls", directory)} holds no .woff2 file')
    # Every url() must resolve from the stylesheet's own directory, which is what lets the fonts
    # load from a page at any depth. Resolved against that directory and not against the output
    # root, which is the whole point when the two are not the same.
    for reference in sorted(set(re.findall(r"url\('([^']*)'\)", css))):
        if not os.path.isfile(os.path.join(os.path.dirname(stylesheet), reference)):
            problems.append(f'webfonts.css references missing {reference}')
    return problems


@check('language-bars', 'every language bar lists every language the source has', needs=('source',))
def language_bars(site: Site) -> list[str]:
    # Every <languages/> bar lists every language the source has (#333 baked them short). A page
    # with N languages is emitted N+1 times and each copy carries N entries, so the target is
    # derived from the source tree rather than pinned to a number.
    assert site.source is not None
    expected = 0
    for path in sorted(glob.glob(os.path.join(site.source, '*.wikitext'))):
        if not re.search(r'<languages */>', site.read(path)):
            continue
        translations = os.path.join(site.source, os.path.basename(path)[: -len('.wikitext')])
        languages = 1 + len(glob.glob(os.path.join(translations, '*.wikitext')))
        expected += languages * (languages + 1)
    # Once per skin the site renders: the default plus the copies the site configuration adds.
    expected *= 1 + len(site.expect['skin_copies'])
    found = sum(site.read(page).count('mw-pt-progress--') for page in site.html_files())
    if found != expected:
        return [f'{found} language-bar entries across the export, expected {expected}']
    site.note(f'{found} language-bar entries, as the source calls for')
    return []


def _sidebar_pages(site: Site) -> list[str]:
    """The page titles MediaWiki:Sidebar names, in the order it names them."""
    assert site.source is not None
    source = os.path.join(site.source, 'MediaWiki:Sidebar.wikitext')
    pages = []
    for line in site.read(source).splitlines():
        found = re.match(r'\*\* *Special:MyLanguage/([^|]*)', line)
        if found:
            pages.append(found.group(1))
    return pages


@check('prevnext-rows', 'every page the sidebar names gets a navigation row', needs=('source',))
def prevnext_rows(site: Site) -> list[str]:
    # Every page the sidebar names gets a navigation row, and the two ends of the sequence get one
    # link rather than two. The order lives in MediaWiki:Sidebar and Module:Sequence reads it
    # (#455); before that it was restated in each call, and what went wrong twice was exactly this
    # -- a page in the sidebar with no row, and the last page with none either.
    missing = []
    for page in _sidebar_pages(site):
        path = site.path(page.replace(' ', '_') + '.html')
        # The link div, not the wrapper: prevnext always emits the wrapper, so matching that passes
        # an empty row -- which is exactly what a broken module produces, and what the first draft
        # of this check waved through.
        if not os.path.isfile(path) or os.path.getsize(path) == 0:
            missing.append(page)
        elif 'class="wikven-prevnext-' not in site.read(path):
            missing.append(page)
    if missing:
        return ['sidebar pages with no navigation row: ' + ' '.join(missing)]
    return []


@check('prevnext-label', 'a translated navigation row shows the target page\'s own title', needs=('source',))
def prevnext_label(site: Site) -> list[str]:
    # A prevnext link is labelled with the target page's own title, so on a translated page it must
    # carry the translated one. The label is not in the calling page's source: the call sits
    # outside the translate tags and passes a page name, and the template reads the title from the
    # target's own title unit, which is the whole point -- a label restated in the caller would be
    # a second copy of a string that is already translated once.
    # One page is the fixture, and the expectation is read from the source rather than written into
    # the expectations file: any Korean at all would pass a string pinned there. Which page follows
    # it is read from the sidebar for the same reason the row itself is -- naming it would make
    # this a check of a particular order rather than of the label.
    assert site.source is not None
    page = site.expect['prevnext_page']
    language = site.expect['prevnext_language']
    order = _sidebar_pages(site)
    # Checked before the file is read: reading a path built from an empty name would fail with an
    # error of its own, which says nothing about the sidebar being what went wrong.
    if page not in order or order.index(page) + 1 >= len(order):
        return [f'MediaWiki:Sidebar names no page after {page}, so this check has no pair to make']
    following = order[order.index(page) + 1]

    translation = os.path.join(site.source, following, f'{language}.wikitext')
    want, seen_title_unit = '', False
    if os.path.isfile(translation):
        for line in site.read(translation).splitlines():
            if re.match(r'<!--T:title[ -]', line):
                seen_title_unit = True
                continue
            if seen_title_unit and line.split():
                want = line
                break
    if not want:
        return [f'{translation} has no translated title to expect']

    # Newlines are flattened before the block is read: the template writes its markup over several
    # lines, and what is being matched is one span of it. An empty match fails the case below
    # rather than passing it, so a prevnext that stopped rendering is caught here too.
    rendered = site.path(page, f'{language}.html')
    flat = site.read(rendered).replace('\n', ' ') if os.path.isfile(rendered) else ''
    found = re.search(r'wikven-prevnext".{0,700}', flat)
    shown = found.group(0) if found else ''
    if want not in shown:
        return [
            f'{page}/{language}\'s prevnext does not show {following}\'s {language} title ({want})',
            shown,
        ]
    return []
