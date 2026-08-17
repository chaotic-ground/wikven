// A reader who searches from a skin copy stays in that copy (#399). Pagefind reports each result's
// URL relative to the crawl root and the client resolves it against the directory the bundle sits
// in, so this is really an assertion about which bundle a copy loads: the one published inside it,
// rather than the root's. Read in a browser because that resolution happens in Pagefind's own
// client, and nothing in the exported HTML shows what it will come out as.

const { test, expect } = require("@playwright/test");

// The non-main skin copies, each a directory of its own under the export root. The main skin has
// no copy: it is the export root, and searching from it was always right.
const COPIES = ["citizen", "minerva"];

// A page every bake of the docs site has, and a term that matches it.
const RESULT = "Standalone_binary.html";
const TERM = "binary";

for (const skin of COPIES) {
	test(`a search from the ${skin} copy leads back into it`, async ({
		page,
		request,
	}) => {
		const response = await request.get(`${skin}/index.html`).catch(() => null);
		test.skip(!response?.ok(), `this export does not render ${skin}`);

		await page.goto(`${skin}/Search.html?search=${TERM}`);

		const result = page.locator(".pagefind-ui__result-link").first();
		await expect(result).toBeVisible({ timeout: 15000 });

		// The copy's own page rather than the root's, which is the whole of it. Asserted as a
		// suffix, since the URL also carries the path prefix the site is served under.
		const inCopy = new RegExp(`/${skin}/[^/]+\\.html$`);
		const hrefs = await page
			.locator(".pagefind-ui__result-link")
			.evaluateAll((links) => links.map((link) => link.href));
		expect(hrefs.length).toBeGreaterThan(0);
		for (const href of hrefs) {
			expect(href).toMatch(inCopy);
		}

		// And it is a page that is actually there: a URL in the right shape pointing at nothing
		// would read the same from the results list.
		const target = page
			.locator(".pagefind-ui__result-link", { hasText: "Standalone binary" })
			.first();
		await expect(target).toHaveAttribute("href", new RegExp(`/${skin}/${RESULT}$`));
		await target.click();
		await expect(page).toHaveURL(new RegExp(`/${skin}/${RESULT}$`));
		await expect(page.locator("#firstHeading")).toBeVisible();
	});
}
