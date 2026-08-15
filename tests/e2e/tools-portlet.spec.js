// The toolbox is Special: pages a static host cannot serve, so the bake empties it
// (Hooks\Hider). Core hides the emptied portlet, but a skin that wraps it in a box
// of its own draws that box regardless, and it is the box a reader sees as an empty
// "Tools" panel. The markup is identical either way, so only a laid-out page tells
// the two apart: the workflow's static assertions cannot.
//
// Minerva needs no case here: it drops its overflow menu when the menu has no
// entries, so it never draws the empty box in the first place.

const { test, expect } = require("@playwright/test");

// Per skin: the page it renders, and the element it wraps the toolbox in.
const SKINS = [
	["vector-2022", "Installation.html", ".vector-page-tools-landmark"],
	["citizen", "citizen/Installation.html", "#citizen-page-more-dropdown"],
];

for (const [skin, path, box] of SKINS) {
	test(`${skin} draws no empty tools box`, async ({ page }) => {
		await page.goto(path);
		await expect(page.locator("#firstHeading")).toBeVisible();

		// Still emitted, so the assertions below are about what is shown, not what is built.
		await expect(page.locator("#p-tb")).toBeAttached();
		await expect(page.locator("#p-tb")).toBeHidden();

		// The box is what this rule hides, and toBeHidden() passes for an element that
		// is not there, so a renamed box has to fail here rather than assert nothing.
		await expect(page.locator(box)).not.toHaveCount(0);
		for (const element of await page.locator(box).all()) {
			await expect(element).toBeHidden();
		}
	});
}

// Below the width at which Vector hides the tab row it moves the tabs into that box,
// which is then the only way to reach them, so there it has to stay. Both sides of
// that edge are pinned: the two rules keying off the same breakpoint is what keeps a
// width with neither the tabs nor the box from opening up between them.
test("vector-2022 keeps its tools box where Vector hides the tab row", async ({
	page,
}) => {
	await page.setViewportSize({ width: 639, height: 800 });
	await page.goto("Installation.html");

	await expect(page.locator(".mw-portlet-views")).toBeHidden();
	await expect(page.locator("#vector-page-tools-dropdown")).toBeVisible();
});

test("vector-2022 drops its tools box where the tab row is back", async ({
	page,
}) => {
	await page.setViewportSize({ width: 640, height: 800 });
	await page.goto("Installation.html");

	await expect(page.locator(".mw-portlet-views")).toBeVisible();
	await expect(page.locator("#vector-page-tools-dropdown")).toBeHidden();
});
