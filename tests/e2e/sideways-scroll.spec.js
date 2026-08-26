// A page that scrolls sideways on a phone is a page whose every line has moved, and one long
// identifier in a paragraph is all it takes to cause it. Minerva and Citizen wrap content that
// cannot fit; Vector 2022 wraps links alone, so the export says so itself in ext.Wikven.styles.
// Asserted in a browser because nothing in the markup shows it: the overflow is a line box that
// laid out wider than the screen.

const { test, expect } = require("@playwright/test");

// Development names three settings in a row, joined by slashes -- the longest unbreakable run the
// docs have, in the language that reads it most narrowly and in the one that wrote it -- and one
// page per skin, since this is a skin's default that the export overrides.
const PAGES = [
	"Development.html",
	"Development/ko.html",
	"minerva/Development.html",
	"citizen/Development.html",
];

test.use({ viewport: { width: 360, height: 740 } });

for (const path of PAGES) {
	test(`${path} does not scroll sideways on a phone`, async ({ page }) => {
		await page.goto(path, { waitUntil: "load" });

		const { over, widest } = await page.evaluate(() => {
			const root = document.scrollingElement;
			let widest = "";
			let right = innerWidth + 1;
			for (const element of document.querySelectorAll("*")) {
				const box = element.getBoundingClientRect();
				if (box.width > 0 && box.height > 0 && box.right > right) {
					right = box.right;
					widest = `<${element.tagName.toLowerCase()}> "${(
						element.textContent || ""
					)
						.trim()
						.slice(0, 40)}" ends at ${Math.round(box.right)}`;
				}
			}
			return { over: root.scrollWidth - root.clientWidth, widest };
		});

		// A pixel of slack: Minerva's own overflow menu lays out one wider than the screen, which
		// is the skin's and not the export's, and is not something a reader can scroll to.
		expect(
			over,
			widest || `${over}px of page past the screen`,
		).toBeLessThanOrEqual(1);
	});
}
