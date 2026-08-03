// The docs ship a PersistTabber gadget that keeps every tabber on the page (and
// the next page) on the tab the reader picked. ResourceLoader parses a gadget
// before serving it and swaps a module it cannot parse for a console error, so
// the gadget can stop running without any other gate noticing; these tests
// drive it in a browser, where that failure is visible.

const { test, expect } = require("@playwright/test");

const SELECTED = '.tabber__tab[aria-selected="true"]';

// The Getting Started page shows the install and the preview steps in one
// Binary/Docker tabber each.
const tabbersOf = async (page) => {
	const tabbers = page.locator(".tabber");
	await expect(tabbers.first()).toBeVisible();
	expect(await tabbers.count()).toBeGreaterThan(1);
	return [tabbers.nth(0), tabbers.nth(1)];
};

// The two tabbers are only a few lines of markup apart, so on any ordinary
// viewport both are on screen at once and the case that actually broke -- a
// tabber TabberNeue has not wired for clicks, because it wires one only while
// it is on screen -- never arises. Push them a couple of screens apart.
const separate = async (second) => {
	await second.evaluate((el) => {
		const spacer = el.ownerDocument.createElement("div");
		spacer.style.height = "200vh";
		el.parentNode.insertBefore(spacer, el);
	});
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
	await separate(second);

	await first.scrollIntoViewIfNeeded();
	await expect(second).not.toBeInViewport();

	await first.getByRole("tab", { name: "Docker" }).click();
	await expect(first.locator(SELECTED)).toHaveText("Docker");
	// Off screen, so nothing to click: it has to catch up on reveal.
	await expect(second.locator(SELECTED)).toHaveText("Binary");

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

// TabberNeue answers a hashchange itself, by opening the fragment's panel in
// that panel's own tabber, so the gadget leaves that tabber alone and lines up
// only the others. Racing it there ends with the gadget's activation reaching
// TabberNeue's hash router as a click, which answers by replacing the fragment
// with the panel's id -- visible only for a fragment that is not already that
// id, hence an anchor inside the panel rather than the panel itself. The panels
// on this page carry no such anchor, so add one.
//
// Reaching that race also needs TabberNeue's hashchange listener to be
// registered before the gadget's, which on this export it is not: the gadget
// registers when its module runs in <head>, TabberNeue's hash router when
// scan() runs on wikipage.content, which mediawiki.page.ready fires at DOM
// ready. So this is a guard on the fragment, not a reproduction of the race.
test("jumping to an anchor inside a panel switches every tabber", async ({
	page,
}) => {
	await page.goto("Getting_Started.html");
	const [first, second] = await tabbersOf(page);
	await expect(first.locator(SELECTED)).toHaveText("Binary");

	await first.evaluate((el) => {
		const anchor = el.ownerDocument.createElement("span");
		anchor.id = "deep-anchor";
		el.querySelector('.tabber__panel[id$="Docker"]').prepend(anchor);
	});

	await page.evaluate(() => {
		window.location.hash = "#deep-anchor";
	});

	await expect(first.locator(SELECTED)).toHaveText("Docker");
	await second.scrollIntoViewIfNeeded();
	await expect(second.locator(SELECTED)).toHaveText("Docker");

	// Not rewritten to #tabber-Docker.
	expect(new URL(page.url()).hash).toBe("#deep-anchor");
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
