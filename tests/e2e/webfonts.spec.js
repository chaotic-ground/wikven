// The bundled-webfont path, which only a browser can confirm: the static assertions in the
// workflow prove webfonts.css and the woff2 exist, not that a page ever resolves to them.

const { test, expect } = require("@playwright/test");

const KHMER_PAGE = "Licenses/km.html";
const FONT = "KhmerOSbattambang";

test("the Khmer page fetches its bundled font from the export", async ({
	page,
}) => {
	const fonts = [];
	page.on("response", (response) => {
		if (/\.woff2(\?|$)/.test(response.url())) {
			fonts.push({ url: response.url(), status: response.status() });
		}
	});

	// A browser downloads a webfont only when rendered text actually resolves to it, so the
	// request below is the assertion that the :lang(km) rule applies -- and it does not depend
	// on which skin rule wins for any particular element.
	await page.goto(KHMER_PAGE, { waitUntil: "networkidle" });

	const khmer = fonts.filter((f) => f.url.includes(FONT));
	expect(khmer.map((f) => f.url).join("; ")).toContain(FONT);
	for (const { url, status } of khmer) {
		expect(status, url).toBe(200);
	}
});

test("the Khmer page is served the font from a subdirectory depth", async ({
	page,
}) => {
	// The page sits one directory down, so a font URL resolved against the page rather than
	// against the stylesheet would 404 here and nowhere else.
	const missing = [];
	page.on("response", (response) => {
		if (
			/\.(woff2|css)(\?|$)/.test(response.url()) &&
			response.status() >= 400
		) {
			missing.push(`${response.status()} ${response.url()}`);
		}
	});

	await page.goto(KHMER_PAGE, { waitUntil: "networkidle" });

	expect(missing, missing.join("; ")).toEqual([]);
});
