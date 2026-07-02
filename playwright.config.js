const { defineConfig, devices } = require("@playwright/test");

// CI bakes docs/ to dist/, serves it under the site's own path prefix, and passes
// the resulting base URL in WIKVEN_BASE_URL. The specs use paths relative to it.
module.exports = defineConfig({
	testDir: "./tests/e2e",
	reporter: "line",
	use: {
		baseURL: process.env.WIKVEN_BASE_URL,
		...devices["Desktop Chrome"],
	},
});
