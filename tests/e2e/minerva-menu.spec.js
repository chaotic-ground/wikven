// Minerva builds its main menu from its own Menu\Definitions rather than the sidebar, so nothing
// the export does to the sidebar reaches it: the entries it invents point at Special: pages the
// bake never writes, and the site's own navigation never arrives. Both halves are fixed outside
// the skin, and both leave the anchors in the HTML in some form, so these read a laid-out page.

const { test, expect } = require("@playwright/test");

// The drawer is visibility:hidden until opened, and its toggle is a label with a transparent
// checkbox over it, so the checkbox is what a click lands on.
const openDrawer = async (page) => {
	await page.locator("#main-menu-input").click();
	const links = page.locator("#mw-mf-page-left a:visible");
	// Awaited here rather than in the caller: the drawer slides in, and a bare read of the links
	// runs before it arrives and reports an empty menu as an empty menu.
	await expect(links).not.toHaveCount(0);
	return links;
};

test("minerva's main menu offers no link the export cannot serve", async ({
	page,
}) => {
	await page.goto("minerva/Installation.html");
	const links = await openDrawer(page);

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

// Compared by label rather than by href: the two skins render the same pages out of different
// directories, so the hrefs differ by depth while the navigation is the same navigation. The main
// page is left out, because Minerva already offers it as its own Home entry under another name.
test("minerva's main menu carries the same navigation as the main skin", async ({
	page,
}) => {
	await page.goto("Installation.html");
	// By text content rather than by innerText: the main skin keeps its menu in a dropdown, and
	// innerText is empty for anything not on screen. Labelled entries only, since the sidebar also
	// carries icon-only links, which have nothing to look for on the other side.
	const expected = (
		await page
			.locator('[id^="n-"] a:not([href$="index.html"])')
			.allTextContents()
	)
		.map((label) => label.trim())
		.filter(Boolean);
	expect(
		expected.length,
		"the main skin has a sidebar to compare against",
	).toBeGreaterThan(1);

	await page.goto("minerva/Installation.html");
	const shown = (await (await openDrawer(page)).allTextContents()).map(
		(label) => label.trim(),
	);

	for (const label of expected) {
		expect(shown, `${label} is missing from Minerva's menu`).toContain(label);
	}
});
