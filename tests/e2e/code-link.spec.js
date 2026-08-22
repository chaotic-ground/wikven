// A link whose label is code -- the reference pages link half their settings that way, as
// [[#WikvenRepositories|<code>WikvenRepositories</code>]] -- has to look like a link. Vector and
// Citizen both ship core's rule for the element (`pre, code, .mw-code { color: var(
// --color-emphasized ) }`), and an element selector outranks the colour an anchor passes down, so
// the label kept body-text colour; neither skin underlines a content link, so nothing else was
// left to say it was one. ext.Wikven.styles hands the colour back with `a code { color: inherit }`,
// which only a rendered page can show: the markup is identical either way.

const { test, expect } = require("@playwright/test");

// A reference page with both kinds of code on it: names that link and names that do not.
const PAGE = "Configuration.html";

// Every code label on the page, paired with the colour of the anchor it sits in, and the colours
// the code standing on its own is painted in. Read from the rendered page rather than from the
// stylesheet, so what is asserted is what a reader sees once every skin's rules have applied.
const codeColours = (page) =>
	page.evaluate(() => {
		const content = document.querySelector(".mw-parser-output");
		const linked = [];
		const plain = new Set();
		for (const code of content.querySelectorAll("code")) {
			const anchor = code.closest("a");
			if (anchor) {
				linked.push({
					text: code.textContent,
					code: getComputedStyle(code).color,
					anchor: getComputedStyle(anchor).color,
				});
			} else {
				plain.add(getComputedStyle(code).color);
			}
		}
		return { linked, plain: [...plain] };
	});

// Where each skin's copy of the page is, discovered from the switcher rather than restated from
// docs/.wikven.yml. Every skin's copy carries the whole list, so the one on the main skin's page
// names them all.
let paths;

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage();
	await page.goto("index.html");
	const skins = await page.evaluate(() =>
		Array.from(document.querySelectorAll('[id^="t-wikven-skin-"]')).map(
			(element) => ({
				skin: element.id.replace("t-wikven-skin-", ""),
				current: element.classList.contains("active"),
			}),
		),
	);
	// The skin a page is rendered in writes into the export root; the others into a directory
	// named after themselves.
	paths = skins.map(({ skin, current }) => ({
		skin,
		path: current ? PAGE : `${skin}/${PAGE}`,
	}));
	await page.close();
});

test("a linked code label is painted in its link's colour, in every skin", async ({
	page,
}) => {
	expect(paths.length, "the page listed no skins").toBeGreaterThan(0);

	for (const { skin, path } of paths) {
		await page.goto(path);
		const { linked } = await codeColours(page);
		expect(
			linked.length,
			`${skin} has no linked code on ${path}`,
		).toBeGreaterThan(0);
		for (const label of linked) {
			expect(
				label.code,
				`${skin}: <code>${label.text}</code> is not painted in its link's colour`,
			).toBe(label.anchor);
		}
	}
});

// The assertion above passes trivially on a skin that paints links and code alike, and on such a
// skin the reader has no cue at all. Keep the two apart.
test("and that colour is not the colour of the code beside it", async ({
	page,
}) => {
	for (const { skin, path } of paths) {
		await page.goto(path);
		const { linked, plain } = await codeColours(page);
		expect(
			plain.length,
			`${skin} has no unlinked code on ${path}`,
		).toBeGreaterThan(0);
		for (const label of linked) {
			expect(
				plain,
				`${skin}: <code>${label.text}</code> reads as ordinary code`,
			).not.toContain(label.anchor);
		}
	}
});
