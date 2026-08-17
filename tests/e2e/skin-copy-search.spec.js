// A reader who searches from a skin copy stays in that copy (#399). Pagefind reports each result's
// URL relative to the crawl root and the client resolves it against the directory the bundle sits
// in, so this is really an assertion about which bundle a copy loads: the one published inside it,
// rather than the root's. Read in a browser because that resolution happens in Pagefind's own
// client, and nothing in the exported HTML shows what it will come out as.

const { test, expect } = require("@playwright/test");

// The non-main skin copies, each a directory of its own under the export root. The main skin has
// no copy: it is the export root, and searching from it was always right.
const COPIES = ["citizen", "minerva"];

// A term the docs site answers with pages that exist in every copy.
const TERM = "binary";

for (const skin of COPIES) {
	test(`a search from the ${skin} copy leads back into it`, async ({
		page,
		request,
	}) => {
		const response = await request.get(`${skin}/index.html`).catch(() => null);
		test.skip(!response?.ok(), `this export does not render ${skin}`);

		await page.goto(`${skin}/Search.html?search=${TERM}`);

		const results = page.locator(".pagefind-ui__result-link");
		await expect(results.first()).toBeVisible({ timeout: 15000 });

		// Inside the copy is the whole of it. Matched as a prefix rather than as one file name,
		// since a translated page is exported into a directory of its own
		// ("citizen/Standalone_binary/en.html") and the index holds those too (#400). The site's
		// own path prefix is in front of all of it, hence the suffix match.
		const inCopy = new RegExp(`/${skin}/.+\\.html$`);
		const hrefs = await results.evaluateAll((links) =>
			links.map((link) => link.href),
		);
		expect(hrefs.length).toBeGreaterThan(0);
		for (const href of hrefs) {
			expect(href).toMatch(inCopy);
		}

		// And it is a page that is really there: a URL in the right shape pointing at nothing
		// reads the same in the results list.
		const target = await results.first().evaluate((link) => link.href);
		await results.first().click();
		await expect(page).toHaveURL(target);
		await expect(page.locator("#firstHeading")).toBeVisible();
	});
}
