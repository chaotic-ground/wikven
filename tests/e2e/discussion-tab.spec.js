// A static export carries no discussion: there is nothing behind the tab to read and no way to
// post to it. The tab is taken out of the navigation in PHP (Hooks\Hider) rather than hidden in
// CSS, because a `#ca-talk` rule only reaches the id core puts on its own tab: Minerva builds a tab
// bar of its own out of the same data and keeps no id on it, so its Discussion tab stood through
// every skin's stylesheet. Read in a browser, one skin at a time, so the next skin that draws the
// tab its own way is caught by the page rather than by the rule that was supposed to cover it.

const { test, expect } = require("@playwright/test");

// The same article as each skin renders it. The default skin bakes to the root; the others to a
// directory of their own.
const PAGES = {
	"the main skin": "Installation.html",
	minerva: "minerva/Installation.html",
	citizen: "citizen/Installation.html",
};

// Every shape the tab takes: core's own id on it, the rel core marks the link with -- which is what
// Minerva carries into its tab bar in place of the id -- and the button Minerva puts at the foot of
// a page on the sites where it shows talk there instead of at the top.
const DISCUSSION =
	'#ca-talk, [rel="discussion"], #page-secondary-actions .talk';

for (const [skin, path] of Object.entries(PAGES)) {
	test(`${skin} offers no discussion tab`, async ({ page, request }) => {
		const response = await request.get(path).catch(() => null);
		test.skip(!response?.ok(), `this export does not render ${skin}`);

		await page.goto(path);

		// Absent, not merely invisible: the export ships the markup it renders, and a tab hidden by
		// a stylesheet is one skin update away from being visible again.
		await expect(page.locator(DISCUSSION)).toHaveCount(0);
		// And the page rendered, so a skin copy that stopped being written cannot pass the check
		// above as chrome that went. The heading is the one element every skin here draws.
		await expect(page.locator("#firstHeading")).toBeVisible();
	});
}
