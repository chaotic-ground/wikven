// The docs ship a PersistTabber gadget that keeps every tabber on the page (and
// the next page) on the tab the reader picked. ResourceLoader parses a gadget
// before serving it and swaps a module it cannot parse for a console error, so
// the gadget can stop running without any other gate noticing; these tests
// drive it in a browser, where that failure is visible.

const { test, expect } = require("@playwright/test");

const SELECTED = '.tabber__tab[aria-selected="true"]';

// The Getting Started page shows the install and preview steps in one
// Binary/Docker tabber each.
const tabbersOf = async (page) => {
	const tabbers = page.locator(".tabber");
	await expect(tabbers.first()).toBeVisible();
	expect(await tabbers.count()).toBeGreaterThan(1);
	return [tabbers.nth(0), tabbers.nth(1)];
};

test("the site's gadgets are served as runnable code", async ({ page }) => {
	const errors = [];
	page.on("console", (message) => {
		if (message.type() === "error") {
			errors.push(message.text());
		}
	});

	await page.goto("Getting_Started.html");
	await expect(page.locator(".tabber__tab").first()).toBeVisible();

	const parseErrors = errors.filter((text) => text.includes("Parse error"));
	expect(parseErrors, parseErrors.join("; ")).toEqual([]);
});

test("picking a tab switches every tabber on the page", async ({ page }) => {
	await page.goto("Getting_Started.html");
	const [first, second] = await tabbersOf(page);

	await first.getByRole("tab", { name: "Docker" }).click();
	await expect(first.locator(SELECTED)).toHaveText("Docker");

	// A tabber below the fold is not wired for clicks until it scrolls in, so it
	// catches up on reveal rather than at the moment of the choice.
	await second.scrollIntoViewIfNeeded();
	await expect(second.locator(SELECTED)).toHaveText("Docker");

	// Syncing the rest of the page must not move the fragment off the panel the
	// reader picked.
	expect(new URL(page.url()).hash).toBe("#tabber-Docker");
});

test("arriving on a tab's fragment switches every tabber", async ({ page }) => {
	await page.goto("Getting_Started.html#tabber-Docker");
	const [first, second] = await tabbersOf(page);

	await expect(first.locator(SELECTED)).toHaveText("Docker");
	await second.scrollIntoViewIfNeeded();
	await expect(second.locator(SELECTED)).toHaveText("Docker");

	expect(new URL(page.url()).hash).toBe("#tabber-Docker");
});

test("the choice survives a reload", async ({ page }) => {
	await page.goto("Getting_Started.html");
	const [first] = await tabbersOf(page);
	await first.getByRole("tab", { name: "Docker" }).click();
	await expect(first.locator(SELECTED)).toHaveText("Docker");

	// No fragment this time: the choice has to come back out of storage.
	await page.goto("Getting_Started.html");
	const [reloaded, second] = await tabbersOf(page);
	await expect(reloaded.locator(SELECTED)).toHaveText("Docker");
	await second.scrollIntoViewIfNeeded();
	await expect(second.locator(SELECTED)).toHaveText("Docker");
});
