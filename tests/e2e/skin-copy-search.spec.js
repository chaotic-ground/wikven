// Search from a skin the reader chose. A site built with more than one skin is written once per
// skin -- the first at the export root, the rest into a directory of their own -- and the chooser
// in the chrome moves a reader between those copies. Everything else in a copy is the copy's own:
// its pages, its stylesheets, its script bundle. The search index was not, and that is what took a
// reader out of the copy: Pagefind records each page's URL under the crawl root and the client
// resolves it against the bundle's own parent directory, so one bundle at the export root answered
// every copy with the root copy's pages (#399). Each copy now carries its own bundle, and these
// read where a search from inside one actually lands.

const { test, expect } = require("@playwright/test");

// The docs site's non-default skins, each written into a directory named after it.
const COPIES = ["citizen", "minerva"];

// A term the docs site has more than one match for, as the other search specs use.
const RESULTS = "Search.html?search=binary";

for (const copy of COPIES) {
	test.describe(`the ${copy} copy`, () => {
		test.beforeEach(async ({ request }) => {
			const response = await request
				.get(`${copy}/Installation.html`)
				.catch(() => null);
			test.skip(!response?.ok(), `this export does not render ${copy}`);
		});

		test("carries a Pagefind bundle of its own", async ({ request }) => {
			// The copy's pages ask for the bundle here, so a copy without one has no search at all:
			// the results widget never mounts and the typeahead answers nothing. Read directly
			// rather than inferred from the page below, so that failure reads as what it is.
			const response = await request.get(`${copy}/pagefind/pagefind.js`);
			expect(response.status()).toBe(200);
		});

		test("keeps a search result inside the copy", async ({ page, baseURL }) => {
			await page.goto(`${copy}/${RESULTS}`);

			const link = page.locator(".pagefind-ui__result a[href]").first();
			await expect(link).toBeVisible({ timeout: 15000 });

			// Read as the browser resolves it: the point is where following it lands, and the URL
			// carries the path prefix the site is served under.
			const href = await link.evaluate((element) => element.href);
			expect(href.startsWith(new URL(`${copy}/`, baseURL).href)).toBe(true);
			expect(href).toMatch(/\.html(#|$)/);
		});
	});
}

test("a search from the root copy stays at the root", async ({
	page,
	baseURL,
}) => {
	// The other half of the same rule, and the one a too-eager fix would break: the default skin
	// is the copy at the export root, and its results belong to no skin directory.
	await page.goto(RESULTS);

	const link = page.locator(".pagefind-ui__result a[href]").first();
	await expect(link).toBeVisible({ timeout: 15000 });

	const href = await link.evaluate((element) => element.href);
	expect(href.startsWith(new URL("./", baseURL).href)).toBe(true);
	for (const copy of COPIES) {
		expect(href.startsWith(new URL(`${copy}/`, baseURL).href)).toBe(false);
	}
});
