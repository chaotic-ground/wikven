// Minerva builds its main menu from its own Menu\Definitions rather than the sidebar, so nothing
// the export does to the sidebar reaches it and the entries it invents point at Special: pages the
// bake never writes. Community portal is dropped at bake time and the rest are hidden by CSS, both
// of which leave the anchor in the HTML in some form, so this asserts what a reader can reach.

const { test, expect } = require("@playwright/test");

test("minerva's main menu offers no link the export cannot serve", async ({
	page,
}) => {
	await page.goto("minerva/Installation.html");

	// The drawer is off-canvas rather than display:none, so its links are visible to Playwright
	// while the ones this export hides are not. An empty menu would assert nothing.
	const links = page.locator("#mw-mf-page-left a:visible");
	await expect(links).not.toHaveCount(0);

	const dead = [];
	for (const link of await links.all()) {
		const href = await link.getAttribute("href");
		if (!href || /^(https?:|#|mailto:)/.test(href)) {
			continue;
		}
		const url = new URL(href, page.url()).toString();
		const status = (await page.request.head(url)).status();
		if (status >= 400) {
			dead.push(`${status} ${href}`);
		}
	}

	expect(dead, dead.join("; ")).toEqual([]);
});
