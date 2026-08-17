// Search from a page the export put in a subdirectory. A translated page is exported into a real
// directory ("Deploying/ko.html"), and everything the export writes into its HTML is rebased by
// that depth. The search targets are not all written into the HTML: SifterSearch carries the
// results page's URL inside the module bundle, which is one file serving every page at every
// depth, so a page-relative URL there is right only at the export root (#393, SifterSearch #63).
// The reader-facing consequence is silent -- the box still opens, still suggests -- so what
// follows reads the targets themselves, from a page that is not at the root.

const { test, expect } = require("@playwright/test");

// A translated page, exported one directory down. The Korean translations are the only pages the
// docs site keeps at depth, and this one exists because docs/Deploying/ko.wikitext does.
const SUBPAGE = "Deploying/ko.html";

test("a search from a subpage aims at the results page, not inside its own directory", async ({
	page,
	baseURL,
}) => {
	await page.goto(SUBPAGE);

	const results = new URL("Search.html", baseURL).href;
	const form = page.locator('[role="search"] form').first();

	// The skin renders the form aimed at the script path, and ext.sifter.retarget repoints it once
	// the bundle runs. So the action leaving the script path is what says the module has had its
	// say, and only then is there anything to assert about where it left the targets.
	await expect
		.poll(() => form.evaluate((el) => el.action), { timeout: 15000 })
		.toBe(results);

	// Read as the browser resolves them, since these are relative and the point is where they land.
	// Every link inside the search box: Vector's collapsed-box toggle is the one that exists today.
	const hrefs = await page
		.locator('[role="search"] a[href]')
		.evaluateAll((links) => links.map((link) => link.href));
	expect(hrefs.length).toBeGreaterThan(0);
	for (const href of hrefs) {
		expect(href).toBe(results);
	}
});

test("the 'containing' suggestion from a subpage carries the query to the results page", async ({
	page,
	baseURL,
}) => {
	await page.goto(SUBPAGE);

	// Focusing the raw box mounts the Codex typeahead and focuses its input; wait for the mounted
	// input before typing so no keystroke is dropped mid-swap, as the root-page suggestion test does.
	await page.locator("#searchInput").first().click();
	await page
		.locator(".cdx-text-input__input")
		.first()
		.waitFor({ state: "visible", timeout: 15000 });
	await page.keyboard.type("binary", { delay: 40 });

	// The footer row a full-text search goes to. Its URL comes from the same configured value as the
	// form's action, and core appends a wprov= tracking parameter, so the query is matched by prefix.
	const containing = page
		.locator('.cdx-menu-item a[href*="Search.html?search="]')
		.first();
	await expect(containing).toBeVisible({ timeout: 15000 });
	const href = await containing.evaluate((link) => link.href);
	const query = new URL("Search.html?search=binary", baseURL).href;
	expect(href.startsWith(query)).toBe(true);
});
