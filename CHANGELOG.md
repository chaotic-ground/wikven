# Changelog

## 1.0.0 (2026-08-31)

The first release.

### What it is

Wikven bakes a directory of wikitext into a static website. MediaWiki renders every page at build
time and wikven writes out plain HTML, so nothing runs behind the published site — no PHP, no
database, no server to keep patched. `index.wikitext` becomes `index.html`, and what you are left
with is a directory to hand to GitHub Pages, or to any web server.

### What 1.0 settles

Three surfaces are what a site actually depends on, and this is the release they stop moving
without warning:

* the configuration file — `.wikven.yaml`, or any of the five other accepted names, layered over
  wikven's own defaults;
* the commands — `build`, `serve`, and `translate mark|scaffold|check|stamp`;
* the output — one directory holding the pages, images, styles and search index.

### What it does

* **Three ways to run it.** A standalone Linux binary for x86_64 and arm64 — one file with
  MediaWiki inside and no Docker anywhere; the image, published to both
  `ghcr.io/chaotic-ground/wikven` and `quay.io/chaotic-ground/wikven`; and a GitHub Action,
  `chaotic-ground/wikven/actions/bake@v1.0.0`.
* **Search with nothing behind it.** SifterSearch and Pagefind are in the box, and each language
  is indexed on its own, so the search box works on a site that is only files.
* **Translation as part of the build.** Translate and UniversalLanguageSelector are bundled:
  `translate scaffold` writes the skeletons, `mark` numbers the units, `stamp` records that
  someone read one, and `check` can stop a build — with an Action that reports what it found on
  the pull request.
* **Skins a reader can switch.** Vector, Vector 2022 and MinervaNeue, each rendered into the
  export, with a switcher in the footer.
* **Builds that repeat.** Last-modified dates come from `SOURCE_DATE_EPOCH`, so the same source at
  the same commit gives the same site.

### Requirements

MediaWiki 1.46 or later — though you do not install it. The image and the binary each carry their
own 1.46, and that is what renders the pages however you run them. Wikven is tested against 1.46
and against MediaWiki's development branch. The binary is Linux only; on macOS and Windows, use
the image.

### Getting started

```shell
mkdir src
echo 'Hello, World!' > src/index.wikitext
wikven build
wikven serve
```

Then open <http://localhost:8080>. The guide is at <https://chaotic-ground.github.io/wikven/>.

### Where this came from

The project began in December 2021 as **This Is Not A Wiki**, at `lens0021/this-is-not-a-wiki`: 78
commits over three weeks, a MediaWiki extension and a fixed shell script that installed a wiki,
imported the wikitext, and kept what the file cache left behind. Then it sat untouched for four
and a half years. It was renamed to wikven on 1 June 2026, and the 611 commits since are what this
release is made of.

Little of that first pass survives. Configuration moved from six `$wg` globals to a
`.wikven.yaml`; the fixed script became the `build`, `serve` and `translate` commands; and the
search, the translation workflow, the standalone binary and the Actions are all things it never
had. GitHub still redirects the old address here, so old links keep working — but there is no
upgrade path to describe, because a site built with the old thing predates every interface this
release settles.

The rest of this entry is that work, as the commits recorded it.

### Features

* **action:** default to a pinned image version and cache it between runs ([#188](https://github.com/chaotic-ground/wikven/issues/188)) ([f7fcb40](https://github.com/chaotic-ground/wikven/commit/f7fcb40f6852815496d079b6351ffec59a6e15d8))
* **actions:** offer a guiding pull request comment on translation findings ([#520](https://github.com/chaotic-ground/wikven/issues/520)) ([1ab0175](https://github.com/chaotic-ground/wikven/commit/1ab0175158371e648c1614d0945d43ac0868f7ae))
* add a "View source" tab linking to the repo source ([#119](https://github.com/chaotic-ground/wikven/issues/119)) ([24e4d16](https://github.com/chaotic-ground/wikven/commit/24e4d1695b130f4e9fe79262e67a74631618d09d))
* add a {{WIKVENVERSION}} variable ([#604](https://github.com/chaotic-ground/wikven/issues/604)) ([a702b9f](https://github.com/chaotic-ground/wikven/commit/a702b9f48c4c8fe65c30521d4bdfffa971c25179))
* add a footer skin switcher ([#114](https://github.com/chaotic-ground/wikven/issues/114)) ([1867ea6](https://github.com/chaotic-ground/wikven/commit/1867ea64a4c6fbd4c0e197b587958ce469d8d459))
* add a mark command to number translation units ([#216](https://github.com/chaotic-ground/wikven/issues/216)) ([0fcde73](https://github.com/chaotic-ground/wikven/commit/0fcde73766472b885f4aeb4587bceb7f667e861a))
* add a serve subcommand for local preview ([#155](https://github.com/chaotic-ground/wikven/issues/155)) ([8d97799](https://github.com/chaotic-ground/wikven/commit/8d97799efac3bb7d06c8ceb8c1f55f9f6cdcca14))
* add translate scaffold to generate translation skeletons ([#223](https://github.com/chaotic-ground/wikven/issues/223)) ([48ce2ab](https://github.com/chaotic-ground/wikven/commit/48ce2aba8182b856b86f32878a98f67e09e61d91))
* bake ULS webfonts into the static export (opt-in) ([#286](https://github.com/chaotic-ground/wikven/issues/286)) ([074a103](https://github.com/chaotic-ground/wikven/commit/074a103b346799691c2bd023665d09a157f9cb25))
* bake with the standalone binary in CI, and fix the three things that found ([#481](https://github.com/chaotic-ground/wikven/issues/481)) ([8cf3bc3](https://github.com/chaotic-ground/wikven/commit/8cf3bc3f0906ed66a87d41f935a4990f844d9025))
* base the image on mediawiki:1.46-fpm-alpine ([#332](https://github.com/chaotic-ground/wikven/issues/332)) ([f3fdcee](https://github.com/chaotic-ground/wikven/commit/f3fdceeef75eaeb4f7dfc2333736e7d5b3ae2b41))
* **build:** list the site's extensions and skins, with their licenses ([#523](https://github.com/chaotic-ground/wikven/issues/523)) ([42d75d2](https://github.com/chaotic-ground/wikven/commit/42d75d23e7812a25d4e24c6c682cd0402dfe0eef))
* **build:** name wikven in the User-Agent of every request it makes ([#542](https://github.com/chaotic-ground/wikven/issues/542)) ([7d6d1ce](https://github.com/chaotic-ground/wikven/commit/7d6d1ce9dddab81a9c67e84725571133e6c8052c))
* bundle the native search closure so SifterSearch search works offline ([#98](https://github.com/chaotic-ground/wikven/issues/98)) ([cf6b910](https://github.com/chaotic-ground/wikven/commit/cf6b91091bc118f3b9bdf2459bc7dd64f024ab68)), closes [#97](https://github.com/chaotic-ground/wikven/issues/97)
* derive page files from content model, dropping the .css.wikitext suffix ([#62](https://github.com/chaotic-ground/wikven/issues/62)) ([ef645c4](https://github.com/chaotic-ground/wikven/commit/ef645c47fd6821a75304977ca462b53ce62f2233))
* derive the footer project link label from the repo host ([#118](https://github.com/chaotic-ground/wikven/issues/118)) ([e14c059](https://github.com/chaotic-ground/wikven/commit/e14c0599f3ff65316796249b2595b7fcafb43dfd))
* **docs:** give the landing page symbols, an output it can show, and some warmth ([#524](https://github.com/chaotic-ground/wikven/issues/524)) ([3b07995](https://github.com/chaotic-ground/wikven/commit/3b0799514fac649983fc347b35d7bfd4c9f4cb54))
* **docs:** give the note template a teal icon ([#341](https://github.com/chaotic-ground/wikven/issues/341)) ([05a7ea2](https://github.com/chaotic-ground/wikven/commit/05a7ea2478011c9ec29d5b018a64fca9cf0605fa))
* **docs:** keep the rendered page behind the tree instead of trading places ([#530](https://github.com/chaotic-ground/wikven/issues/530)) ([83fc9ee](https://github.com/chaotic-ground/wikven/commit/83fc9ee8c2be3721e4b81a5114637f7bfb0bf46f))
* **docs:** put a copy button on the code blocks ([#377](https://github.com/chaotic-ground/wikven/issues/377)) ([3beee91](https://github.com/chaotic-ground/wikven/commit/3beee912c4a2bf89e9cc12f8af7678e75e1bc2e5))
* **docs:** stop the sidebar linking to the page you are reading ([#499](https://github.com/chaotic-ground/wikven/issues/499)) ([1bf9e2b](https://github.com/chaotic-ground/wikven/commit/1bf9e2b99cd03727d888a2fb7c7f6f2241439924))
* **docs:** turn the diagram's output pane over on scroll where the browser can ([#526](https://github.com/chaotic-ground/wikven/issues/526)) ([6c7bcc8](https://github.com/chaotic-ground/wikven/commit/6c7bcc8b308e012b29d5cb8783df6fa678145fcc))
* fold the version page into an About page ([#426](https://github.com/chaotic-ground/wikven/issues/426)) ([af61267](https://github.com/chaotic-ground/wikven/commit/af61267c7e127cb86c80098e59d029cfe76e4a94))
* gate translation checks on broken source pages, not on stale translations ([#385](https://github.com/chaotic-ground/wikven/issues/385)) ([aacfbfc](https://github.com/chaotic-ground/wikven/commit/aacfbfc2b3dc9078f87816366000b2438c2a2c29))
* generate a Special:Version-style page linked from the footer ([#117](https://github.com/chaotic-ground/wikven/issues/117)) ([d2dfa8a](https://github.com/chaotic-ground/wikven/commit/d2dfa8a679a3f2412b0da3326fd853442d415460))
* give Minerva a main menu the export can serve ([#366](https://github.com/chaotic-ground/wikven/issues/366)) ([fc59117](https://github.com/chaotic-ground/wikven/commit/fc59117b2d5485a61e354de4655f8383288cca79))
* give the standalone binary the translate commands ([#507](https://github.com/chaotic-ground/wikven/issues/507)) ([25ba28e](https://github.com/chaotic-ground/wikven/commit/25ba28e63b10dd2782bbad43967d87f4bdf4b570))
* **i18n:** translate the footer, skin switcher and version page strings ([#123](https://github.com/chaotic-ground/wikven/issues/123)) ([9849b36](https://github.com/chaotic-ground/wikven/commit/9849b36b3a3bb3755e961299fd6ef7420210dc21))
* index each language separately, and assert it stays that way ([#447](https://github.com/chaotic-ground/wikven/issues/447)) ([b4b01cc](https://github.com/chaotic-ground/wikven/commit/b4b01cc3ef8dedeb8feb02ca6112bdb5d9ac73bd))
* keep non-main skin pages out of search indexes ([#113](https://github.com/chaotic-ground/wikven/issues/113)) ([0d8d94e](https://github.com/chaotic-ground/wikven/commit/0d8d94e7f551be9dc90625e088794c586c7399ac))
* keep Vector's appearance menu, which works statically ([#387](https://github.com/chaotic-ground/wikven/issues/387)) ([ce30fea](https://github.com/chaotic-ground/wikven/commit/ce30fea7437f5d4a981e7084f1406141b24d63c9))
* let a site choose the skin it is read in ([#589](https://github.com/chaotic-ground/wikven/issues/589)) ([5f96e9e](https://github.com/chaotic-ground/wikven/commit/5f96e9ef8cb005e5d5c7df0ac5b16378ba1e027c))
* let a skin author turn off the chrome wikven imposes ([#551](https://github.com/chaotic-ground/wikven/issues/551)) ([ea78cab](https://github.com/chaotic-ground/wikven/commit/ea78cabfd3ac333291bc463a3c8232a809b1dc1f))
* let a stamp mean someone read the translation ([#572](https://github.com/chaotic-ground/wikven/issues/572)) ([12d3292](https://github.com/chaotic-ground/wikven/commit/12d329275ddf95c107c0e4bb02a56124919f117a))
* load .wikven.yaml through MediaWiki's settings system and validate it ([#77](https://github.com/chaotic-ground/wikven/issues/77)) ([0952b3b](https://github.com/chaotic-ground/wikven/commit/0952b3b20d4ff7a4954c69eba61562ed34621555))
* localize translated-page chrome and route Special:Translate links to the edit host ([#229](https://github.com/chaotic-ground/wikven/issues/229)) ([3afa7c4](https://github.com/chaotic-ground/wikven/commit/3afa7c40543336ef4e81c4d7fcebf1f012aba810))
* make page titles translatable, and translate them into Korean ([#256](https://github.com/chaotic-ground/wikven/issues/256)) ([acdd975](https://github.com/chaotic-ground/wikven/commit/acdd9750c0a87ba5077aaad6007132cfa8b3f37f))
* make the Translating page translatable ([#248](https://github.com/chaotic-ground/wikven/issues/248)) ([6122151](https://github.com/chaotic-ground/wikven/commit/6122151fa9053e98a0a5cf402f1287a27f94ea2e))
* name a setting that belongs to no one ([#578](https://github.com/chaotic-ground/wikven/issues/578)) ([3df5b0e](https://github.com/chaotic-ground/wikven/commit/3df5b0eecd45312e2088b459077a8f5b113eb429))
* name the setting core cannot accept ([#574](https://github.com/chaotic-ground/wikven/issues/574)) ([56f2d80](https://github.com/chaotic-ground/wikven/commit/56f2d80b911dcdda58090a866057976f3857c685))
* offer the Citizen skin (search hidden) ([#115](https://github.com/chaotic-ground/wikven/issues/115)) ([b83717b](https://github.com/chaotic-ground/wikven/commit/b83717bccb3879b37cdb85387144bcceeb809bf5))
* pin and verify fetched extensions/skins ([#74](https://github.com/chaotic-ground/wikven/issues/74)) ([8ccd5e7](https://github.com/chaotic-ground/wikven/commit/8ccd5e766b219f984b07d45d614b58510266b3e1))
* point non-main skin pages' canonical at the main copy ([#116](https://github.com/chaotic-ground/wikven/issues/116)) ([5bf1d6a](https://github.com/chaotic-ground/wikven/commit/5bf1d6a1dc581ba3a3168ae7220a543b3c935b51))
* publish images on releases and nightlies, not on every commit to main ([#488](https://github.com/chaotic-ground/wikven/issues/488)) ([18fa018](https://github.com/chaotic-ground/wikven/commit/18fa018c22d6b9b9ae8626e9d250519ee294622d))
* publish the image to quay.io as well, with no token to keep ([#485](https://github.com/chaotic-ground/wikven/issues/485)) ([f387e5e](https://github.com/chaotic-ground/wikven/commit/f387e5eabd975ed1990ca202e47adf76dd660138))
* put everything the build generates in one directory ([#584](https://github.com/chaotic-ground/wikven/issues/584)) ([d8b8f43](https://github.com/chaotic-ground/wikven/commit/d8b8f434b629fed51848a1c9cb9d157b2ffce9fe))
* put the generated templates under one name, and keep the old ones ([#559](https://github.com/chaotic-ground/wikven/issues/559)) ([2cd766a](https://github.com/chaotic-ground/wikven/commit/2cd766af6be42cfc1cabb863534102f125215290))
* put the pictures pages carry where the rest of the output goes ([#592](https://github.com/chaotic-ground/wikven/issues/592)) ([78fdf9f](https://github.com/chaotic-ground/wikven/commit/78fdf9f488197ddecbdf9abe75ffa10e269447bc))
* put the skin switcher in the toolbox and drop the footer select ([#351](https://github.com/chaotic-ground/wikven/issues/351)) ([2f2551e](https://github.com/chaotic-ground/wikven/commit/2f2551e50611950e583ca63327af52c06bb5c465))
* render each enabled skin into its own output directory ([#111](https://github.com/chaotic-ground/wikven/issues/111)) ([b0adc94](https://github.com/chaotic-ground/wikven/commit/b0adc947b6fbb12d45d2bc4172316c2bcc4d8dc9))
* require MediaWiki 1.46, dropping 1.45 support ([#236](https://github.com/chaotic-ground/wikven/issues/236)) ([993e62c](https://github.com/chaotic-ground/wikven/commit/993e62ce05556db3aa648e531b524479beac3584))
* resolve Special:MyLanguage/ links in the static export ([#225](https://github.com/chaotic-ground/wikven/issues/225)) ([f4904ea](https://github.com/chaotic-ground/wikven/commit/f4904ea8bb6f20c053d1e691c05d07dde51b6d24))
* say what every built site redistributes, on a page the footer links ([#560](https://github.com/chaotic-ground/wikven/issues/560)) ([3fe4eb6](https://github.com/chaotic-ground/wikven/commit/3fe4eb6d80d55016b12e25d9b28e91a1dfa91baf))
* say what wikven does about Lua, and stop guessing at it ([#473](https://github.com/chaotic-ground/wikven/issues/473)) ([ea10b7d](https://github.com/chaotic-ground/wikven/commit/ea10b7df9f5f7f7039713e9927d8e09fc97bc7e2))
* select the build skin via WIKVEN_BUILD_SKIN ([#110](https://github.com/chaotic-ground/wikven/issues/110)) ([126a021](https://github.com/chaotic-ground/wikven/commit/126a0212075f314dfa5256ca9126fa0842376e5d))
* serve logos from a shared upload instead of inlining them per page ([#56](https://github.com/chaotic-ground/wikven/issues/56)) ([ad4017a](https://github.com/chaotic-ground/wikven/commit/ad4017a6bbebb17723619c45ceb684c189f90d34))
* serve the search results at Search and exclude it from indexing ([#104](https://github.com/chaotic-ground/wikven/issues/104)) ([85d35e2](https://github.com/chaotic-ground/wikven/commit/85d35e21c4107dac7978e8f2dce123d703ca8b2c))
* settle the actions' inputs before 1.0.0 freezes them ([#583](https://github.com/chaotic-ground/wikven/issues/583)) ([2bee0c1](https://github.com/chaotic-ground/wikven/commit/2bee0c197915d7a4a6ec5a94dd60d302826c4d47))
* settle what a page is called in the output ([#605](https://github.com/chaotic-ground/wikven/issues/605)) ([a2860f7](https://github.com/chaotic-ground/wikven/commit/a2860f7aa2a6d0feb98d9d82d211f9ebefd0f7b3))
* ship a composite action and dogfood it in the docs deploy ([#150](https://github.com/chaotic-ground/wikven/issues/150)) ([19392b7](https://github.com/chaotic-ground/wikven/commit/19392b75d379de9e349ef2963c11ec5545c3e6ab))
* ship SifterSearch as a built-in, on-by-default search extension ([#195](https://github.com/chaotic-ground/wikven/issues/195)) ([7b19be8](https://github.com/chaotic-ground/wikven/commit/7b19be8a2e6787d613e7d147bc6f804bf3ed2dde))
* stop the build on three things it used to publish around ([#590](https://github.com/chaotic-ground/wikven/issues/590)) ([9c0a0b3](https://github.com/chaotic-ground/wikven/commit/9c0a0b3547d676706dadae73c78348085aa9f797))
* support Citizen in wikven instead of per-site workarounds ([#369](https://github.com/chaotic-ground/wikven/issues/369)) ([bced0dd](https://github.com/chaotic-ground/wikven/commit/bced0ddb974c012dc23377547ca3da580b42b710))
* translate page content into other languages ([#214](https://github.com/chaotic-ground/wikven/issues/214)) ([28ff2b2](https://github.com/chaotic-ground/wikven/commit/28ff2b287149ec28c3243fefc6812c5107408c4f))
* use the core viewsource message for the View source tab ([#120](https://github.com/chaotic-ground/wikven/issues/120)) ([24230c0](https://github.com/chaotic-ground/wikven/commit/24230c0e9c2bd3bcf7e9f6671cee1711e78a1457))


### Bugfixes

* abort the build when a page fails to import ([#146](https://github.com/chaotic-ground/wikven/issues/146)) ([cf0a007](https://github.com/chaotic-ground/wikven/commit/cf0a00787ed99bdbe4a3085f901311cb50395206))
* **action:** use a locally-built image instead of always pulling ([#194](https://github.com/chaotic-ground/wikven/issues/194)) ([8046aa4](https://github.com/chaotic-ground/wikven/commit/8046aa4ac2a9510f4ee1727983085ce0ea8ddf2e))
* answer symlinks where a source tree is read, not where a path is bounded ([#591](https://github.com/chaotic-ground/wikven/issues/591)) ([d3be13e](https://github.com/chaotic-ground/wikven/commit/d3be13e085ffe2b8c3149a3d50d3608f24f3ecad))
* build the image when a Translate dev dependency has an advisory ([#277](https://github.com/chaotic-ground/wikven/issues/277)) ([00838b9](https://github.com/chaotic-ground/wikven/commit/00838b961013d4f9b201a232d28c82b5816e7948))
* build the search index once, at the end ([#283](https://github.com/chaotic-ground/wikven/issues/283)) ([09a5324](https://github.com/chaotic-ground/wikven/commit/09a5324bbfcefa18576e0ec39c7630b97ccf925e))
* **build:** put wikven in front of the User-Agent core builds for Commons ([#543](https://github.com/chaotic-ground/wikven/issues/543)) ([4c774a3](https://github.com/chaotic-ground/wikven/commit/4c774a3ed99267a97639952be3dfb149128ac8ec))
* bump bundled SifterSearch to v0.6.0 (stop the double search box) ([#200](https://github.com/chaotic-ground/wikven/issues/200)) ([cd38aa7](https://github.com/chaotic-ground/wikven/commit/cd38aa798144cff677f98cb2d30267451be24775))
* bundle the modules core loads by looking at the rendered page ([#484](https://github.com/chaotic-ground/wikven/issues/484)) ([c96c107](https://github.com/chaotic-ground/wikven/commit/c96c1070225f2df185af55db2652605286eab0ce))
* **check-translations:** keep the comment to what the change touches ([#541](https://github.com/chaotic-ground/wikven/issues/541)) ([8288229](https://github.com/chaotic-ground/wikven/commit/82882295f0e083b80b6d6379818a9a77b9b07df4))
* clear the output directory before each build ([#72](https://github.com/chaotic-ground/wikven/issues/72)) ([b4cf416](https://github.com/chaotic-ground/wikven/commit/b4cf41631e2662989e5fa1eb7572a8054d77d6b8))
* clear the title cache once the translation units exist ([#466](https://github.com/chaotic-ground/wikven/issues/466)) ([562ea9e](https://github.com/chaotic-ground/wikven/commit/562ea9e50f8dced2c75e6b2c85052bb0244b27a3))
* create directories with wfMkdirParents ([#289](https://github.com/chaotic-ground/wikven/issues/289)) ([5157174](https://github.com/chaotic-ground/wikven/commit/51571747c63134c9362d270ee96b9e56e6b6338b))
* date each page at the commit that changed it, not at the bake ([#424](https://github.com/chaotic-ground/wikven/issues/424)) ([4cd31f4](https://github.com/chaotic-ground/wikven/commit/4cd31f4900d496e6fc866e791a6f84fa9f5701a2)), closes [#406](https://github.com/chaotic-ground/wikven/issues/406)
* declare the build's directory settings in extension.json ([#262](https://github.com/chaotic-ground/wikven/issues/262)) ([65432b5](https://github.com/chaotic-ground/wikven/commit/65432b593af7ecf1cbdb463b912a10b36d28fae7))
* decode JS-bundle URL escapes with json_decode in AssetLocalizer ([#297](https://github.com/chaotic-ground/wikven/issues/297)) ([ccbf702](https://github.com/chaotic-ground/wikven/commit/ccbf7021b45e4976d7068597dde078a521b91098))
* **docs:** cross the output pane's two states gradually instead of at a line ([#540](https://github.com/chaotic-ground/wikven/issues/540)) ([85fb9c9](https://github.com/chaotic-ground/wikven/commit/85fb9c94b57a7ae3fa6bfdb5464b987b18018368))
* **docs:** cut the hero's light at the screen, and turn the pane over mid-screen ([#531](https://github.com/chaotic-ground/wikven/issues/531)) ([ea482af](https://github.com/chaotic-ground/wikven/commit/ea482af8ef7285e0d2e8ac25fe6413f295889a59))
* **docs:** keep sidebar links in the reader's language ([#382](https://github.com/chaotic-ground/wikven/issues/382)) ([f2b27f4](https://github.com/chaotic-ground/wikven/commit/f2b27f4e6323d86d25f605097d5d22862433fa2d))
* **docs:** keep the hero's light inside the hero ([#525](https://github.com/chaotic-ground/wikven/issues/525)) ([aaeef7a](https://github.com/chaotic-ground/wikven/commit/aaeef7a387d149697d233920c7ef221e8f0cd6fc))
* **docs:** keep the logo in the reader's language ([#391](https://github.com/chaotic-ground/wikven/issues/391)) ([749f507](https://github.com/chaotic-ground/wikven/commit/749f507e22ad006de21a3c8e19901a9914bd1ca0))
* **docs:** let the hero's light run off the frame so it reads as ground ([#529](https://github.com/chaotic-ground/wikven/issues/529)) ([3d40660](https://github.com/chaotic-ground/wikven/commit/3d40660c0c2b8390363d8b348efeee95306ef76f))
* **docs:** let the hero's light run past the text column again ([#539](https://github.com/chaotic-ground/wikven/issues/539)) ([2fbee0e](https://github.com/chaotic-ground/wikven/commit/2fbee0eee801638e321996ed57294402cdc52230))
* **docs:** let the output pane settle back on the page as the diagram leaves ([#544](https://github.com/chaotic-ground/wikven/issues/544)) ([9d0785a](https://github.com/chaotic-ground/wikven/commit/9d0785a5d63ee077506686140049b63cea983b21))
* **docs:** light the hero from its corners, and drop the clip [#531](https://github.com/chaotic-ground/wikven/issues/531) needed ([#536](https://github.com/chaotic-ground/wikven/issues/536)) ([186421c](https://github.com/chaotic-ground/wikven/commit/186421c295e01ea4185703ad9a82e7f2441b532a))
* **docs:** make the brand teal and the note box legible in dark mode ([#489](https://github.com/chaotic-ground/wikven/issues/489)) ([a03a76c](https://github.com/chaotic-ground/wikven/commit/a03a76c45779cd4898c29d380d504feb73f288e9))
* **docs:** make the hero's light read as ground rather than as two spheres ([#528](https://github.com/chaotic-ground/wikven/issues/528)) ([e31f7a5](https://github.com/chaotic-ground/wikven/commit/e31f7a523516b7d1eda382be9916fb13a9e2f826))
* **docs:** paint the landing page's marks on its translations, and its buttons anywhere ([#498](https://github.com/chaotic-ground/wikven/issues/498)) ([1ff3a50](https://github.com/chaotic-ground/wikven/commit/1ff3a50b13de353618e7aac196365581db4cc147))
* **docs:** stop the diagram clipping its own file names on a narrow screen ([#527](https://github.com/chaotic-ground/wikven/issues/527)) ([74c35fb](https://github.com/chaotic-ground/wikven/commit/74c35fb9af5b844ce4254d722568bc47ec63dcbf))
* **docs:** translate the Minerva section of Skins into Korean ([#415](https://github.com/chaotic-ground/wikven/issues/415)) ([2ac133a](https://github.com/chaotic-ground/wikven/commit/2ac133a0027b40efddb85ddfeee159bc2bfd3d08))
* don't treat a documented &lt;translate&gt; example as a translation source ([#239](https://github.com/chaotic-ground/wikven/issues/239)) ([cdcc388](https://github.com/chaotic-ground/wikven/commit/cdcc388313b372920846230eee5d0be858c70d51))
* drain translation jobs by hand again, reverting [#311](https://github.com/chaotic-ground/wikven/issues/311) ([#326](https://github.com/chaotic-ground/wikven/issues/326)) ([4170fd7](https://github.com/chaotic-ground/wikven/commit/4170fd739306b0c38db5c77f1d463fc46d12b162))
* drop dead footer/search affordances from the static export ([#160](https://github.com/chaotic-ground/wikven/issues/160)) ([c08a5d2](https://github.com/chaotic-ground/wikven/commit/c08a5d23353ce8740840232505cdb9b04154e512))
* drop the .git the bundled extension clones leave in the image ([#330](https://github.com/chaotic-ground/wikven/issues/330)) ([d19dbf4](https://github.com/chaotic-ground/wikven/commit/d19dbf425da6527f938b93b831313ff1875bc4f3))
* drop the category footer's dead Special:Categories link ([#173](https://github.com/chaotic-ground/wikven/issues/173)) ([2df6bf8](https://github.com/chaotic-ground/wikven/commit/2df6bf89af8af1cb8f2a017902f213af235ae1b3))
* drop the discussion tab in every skin, Minerva included ([#493](https://github.com/chaotic-ground/wikven/issues/493)) ([cd0fccd](https://github.com/chaotic-ground/wikven/commit/cd0fccd035a0768665fc40595f2cf69510a7278f))
* dump ResourceLoader modules without load.php's HTTP response ([#259](https://github.com/chaotic-ground/wikven/issues/259)) ([3a39aac](https://github.com/chaotic-ground/wikven/commit/3a39aac06c911a63bdc79df6155a6fa1acc83901))
* end a source translation unit where its &lt;translate&gt; block ends ([#379](https://github.com/chaotic-ground/wikven/issues/379)) ([50aebd8](https://github.com/chaotic-ground/wikven/commit/50aebd82dbc8b9e239af60c83c8d205d84f8ba7e))
* export subpage titles as real subdirectories ([#213](https://github.com/chaotic-ground/wikven/issues/213)) ([1db8e0d](https://github.com/chaotic-ground/wikven/commit/1db8e0d4122058a2b3ef28379af01aa69af0a904))
* fail on un-localized images and hand output back to the host user ([#161](https://github.com/chaotic-ground/wikven/issues/161)) ([1bb8c86](https://github.com/chaotic-ground/wikven/commit/1bb8c86b07462f4c3b9777c5f6141d4025ed9aba))
* fail the build when a stylesheet never reaches the disk ([#565](https://github.com/chaotic-ground/wikven/issues/565)) ([6ad1b41](https://github.com/chaotic-ground/wikven/commit/6ad1b416bf37fcc5d0a699394237b25b490943ea))
* fail the build when the webfonts a site asked for cannot be copied ([#566](https://github.com/chaotic-ground/wikven/issues/566)) ([6fbd87a](https://github.com/chaotic-ground/wikven/commit/6fbd87a540de4cf8901b968684da4e37f90b7bde))
* fail when a composer package lands where nothing loads it ([#581](https://github.com/chaotic-ground/wikven/issues/581)) ([263fee5](https://github.com/chaotic-ground/wikven/commit/263fee5111ee15e78be32486f4ecbb12de45231d))
* fetch a third-party extension again when its pin moves ([#546](https://github.com/chaotic-ground/wikven/issues/546)) ([44938c2](https://github.com/chaotic-ground/wikven/commit/44938c2e6a0f0ff48454bb3b6025440918443bd2))
* fetch the bundled extensions as tarballs, not clones ([#331](https://github.com/chaotic-ground/wikven/issues/331)) ([9f60efa](https://github.com/chaotic-ground/wikven/commit/9f60efac00e98400feda25afacafb12694cbe859))
* freeze page_touched so wiki-page modules hash the same every bake ([#271](https://github.com/chaotic-ground/wikven/issues/271)) ([853c06f](https://github.com/chaotic-ground/wikven/commit/853c06ff21b510333362714fe12d5ff49dba4225))
* give a fetch that reaches the network more than one go ([#469](https://github.com/chaotic-ground/wikven/issues/469)) ([ba9fb1d](https://github.com/chaotic-ground/wikven/commit/ba9fb1da68368e6545faf08f6ab6c99610878807))
* give Citizen's "View source" tab an icon ([#440](https://github.com/chaotic-ground/wikven/issues/440)) ([f0b4e02](https://github.com/chaotic-ground/wikven/commit/f0b4e0292d2affd82354e3beda809d1ba84bcb19))
* give Citizen's search shortcuts the search the export has ([#397](https://github.com/chaotic-ground/wikven/issues/397)) ([27eeebf](https://github.com/chaotic-ground/wikven/commit/27eeebf049b6c047ba9a0c055b9e91c05aa55413))
* give every source image its own File: page ([#603](https://github.com/chaotic-ground/wikven/issues/603)) ([623b866](https://github.com/chaotic-ground/wikven/commit/623b8661a34c74b0cdf5b3b43c7bad4c7dcbf6fa))
* give the per-skin build its own environment and working directory ([#298](https://github.com/chaotic-ground/wikven/issues/298)) ([cfb0243](https://github.com/chaotic-ground/wikven/commit/cfb0243222fb6a06249be41aaa5560dcebf72e54))
* give the skin list its own section instead of renaming the toolbox ([#363](https://github.com/chaotic-ground/wikven/issues/363)) ([aa13b7b](https://github.com/chaotic-ground/wikven/commit/aa13b7b587b570c3367c180df87b2c4b3a4c150e))
* grant the release binary job the provenance permissions it needs ([#177](https://github.com/chaotic-ground/wikven/issues/177)) ([be9f283](https://github.com/chaotic-ground/wikven/commit/be9f2837e00240379c08a0a36dcf9e31992f1075))
* hide dead skin chrome left by the static export in Timeless and Citizen ([#196](https://github.com/chaotic-ground/wikven/issues/196)) ([1b4c9e1](https://github.com/chaotic-ground/wikven/commit/1b4c9e140eba751efe74de1b32bef5a1e0f22792))
* hide edit/history/source links on generated pages ([#130](https://github.com/chaotic-ground/wikven/issues/130)) ([fb6df1e](https://github.com/chaotic-ground/wikven/commit/fb6df1ee4154dd988dc4ab549190785f50d6fa4f))
* hide the category footer on the static export ([#121](https://github.com/chaotic-ground/wikven/issues/121)) ([939c435](https://github.com/chaotic-ground/wikven/commit/939c43576a758d92607e637afd6afb7b583721b5))
* hide the empty tools box each skin draws around the toolbox ([#346](https://github.com/chaotic-ground/wikven/issues/346)) ([5afb909](https://github.com/chaotic-ground/wikven/commit/5afb9099eb6602bd5caaa9bc4b48b41ce79d3527))
* index a translated page once, not once per title it has ([#458](https://github.com/chaotic-ground/wikven/issues/458)) ([e934e00](https://github.com/chaotic-ground/wikven/commit/e934e00df31ce8a478385287eef49d5d9c7bb509))
* keep $wgCacheEpoch out of module versions ([#270](https://github.com/chaotic-ground/wikven/issues/270)) ([7795bf9](https://github.com/chaotic-ground/wikven/commit/7795bf9ec538ba01ec8b91ca96d8fbe0d9944fab))
* keep a search inside the skin copy the reader is in ([#434](https://github.com/chaotic-ground/wikven/issues/434)) ([32e7f6d](https://github.com/chaotic-ground/wikven/commit/32e7f6ddacedd8ead437a4c043d91b0f3ffb588c))
* keep a skin pass out of the other skins' pages ([#430](https://github.com/chaotic-ground/wikven/issues/430)) ([e84cd11](https://github.com/chaotic-ground/wikven/commit/e84cd115baae67e8d0feb6dd40446232d9519c55))
* keep the build's chrome off a licenses page the site wrote ([#568](https://github.com/chaotic-ground/wikven/issues/568)) ([e45596e](https://github.com/chaotic-ground/wikven/commit/e45596e2dc2e04f538cb0692638e8aeb62ef74dc))
* keep the paths the build derives out of a site's hands ([#573](https://github.com/chaotic-ground/wikven/issues/573)) ([e21b16b](https://github.com/chaotic-ground/wikven/commit/e21b16b191f6717ad1775cf608954e4e9450fb99))
* keep the site's own styles linked from a style directory ([#563](https://github.com/chaotic-ground/wikven/issues/563)) ([5dfea21](https://github.com/chaotic-ground/wikven/commit/5dfea210f0a66716ec91fd4d69cec1f19c0277b6))
* leave a page of its own where a translation would go ([#597](https://github.com/chaotic-ground/wikven/issues/597)) ([09934b7](https://github.com/chaotic-ground/wikven/commit/09934b7860ebfcd83089571e37b37b73669f4792))
* link the search toggle to the results page, not to Special:Search ([#421](https://github.com/chaotic-ground/wikven/issues/421)) ([3b3b2d5](https://github.com/chaotic-ground/wikven/commit/3b3b2d5294e8b4a038823ee69c482fc5bc9f5d28))
* list directories with FilesystemIterator, not glob ([#294](https://github.com/chaotic-ground/wikven/issues/294)) ([f8188fa](https://github.com/chaotic-ground/wikven/commit/f8188fad8a2fd6459070969e1c5845dd7be7a20d))
* make edit/history links correct for all valid titles ([950c0b2](https://github.com/chaotic-ground/wikven/commit/950c0b29aaf37caf3ef88dbcfb18fc97e8a2d735)), closes [#67](https://github.com/chaotic-ground/wikven/issues/67)
* make storeImages downloads status-aware and stream to disk ([#307](https://github.com/chaotic-ground/wikven/issues/307)) ([b75afc3](https://github.com/chaotic-ground/wikven/commit/b75afc32c383537b13edae723e2d0bf502702b17))
* make the exported HTML the same in every bake ([#282](https://github.com/chaotic-ground/wikven/issues/282)) ([caf6661](https://github.com/chaotic-ground/wikven/commit/caf6661a8f5b5d13dba763e44ecf9f28f6107286))
* make the first listed skin the default skin ([#109](https://github.com/chaotic-ground/wikven/issues/109)) ([19e3fae](https://github.com/chaotic-ground/wikven/commit/19e3faee500b7be715218747c35648c83506b510))
* make the main page configurable and fail if it was not imported ([#71](https://github.com/chaotic-ground/wikven/issues/71)) ([4399b8f](https://github.com/chaotic-ground/wikven/commit/4399b8f82ec79988f42b3b94ea4c72e0db737b64))
* make verbatimRanges() comment-aware ([#312](https://github.com/chaotic-ground/wikven/issues/312)) ([59117f7](https://github.com/chaotic-ground/wikven/commit/59117f748a55dff9fe6a999f495ca4b391200e9c))
* match &lt;translate&gt; with its attributes, as Translate does ([#292](https://github.com/chaotic-ground/wikven/issues/292)) ([243bbb0](https://github.com/chaotic-ground/wikven/commit/243bbb0c0271f26fd5624240901f0875aee10d3e))
* move Vector's skin list into the appearance menu ([#423](https://github.com/chaotic-ground/wikven/issues/423)) ([68917e0](https://github.com/chaotic-ground/wikven/commit/68917e0ded2553e65e88f871a63c6c0140cb102c)), closes [#405](https://github.com/chaotic-ground/wikven/issues/405)
* name the project namespace Wikven instead of clashing with MediaWiki ([#105](https://github.com/chaotic-ground/wikven/issues/105)) ([4695978](https://github.com/chaotic-ground/wikven/commit/469597876fbea08504ceb9a32861118ecbb01ec7))
* never read a translation file as a base page ([#390](https://github.com/chaotic-ground/wikven/issues/390)) ([6adb270](https://github.com/chaotic-ground/wikven/commit/6adb270daaedd2ff6324e8865413c4ed35d55fc0))
* obtain GadgetRepo from the service, not the removed singleton() ([288c733](https://github.com/chaotic-ground/wikven/commit/288c73333eb95c4eb4326487bd542979949eaef9))
* point prevnext navigation at the reader's-language pages ([#245](https://github.com/chaotic-ground/wikven/issues/245)) ([a3c8411](https://github.com/chaotic-ground/wikven/commit/a3c8411b1a8ab2d48081e3589ad623145998fe17))
* probe openssl's own compiled-in CA bundle location first ([#309](https://github.com/chaotic-ground/wikven/issues/309)) ([c82d5b1](https://github.com/chaotic-ground/wikven/commit/c82d5b1dccb35a2f754c84fcf4ca3cd3b67cd4ad))
* propagate the child's exit code from wikven build and serve ([#302](https://github.com/chaotic-ground/wikven/issues/302)) ([5c75797](https://github.com/chaotic-ground/wikven/commit/5c75797a29a5e24d65495a491d386e276c36c3bd))
* read a source page the way Translate segments it ([#388](https://github.com/chaotic-ground/wikven/issues/388)) ([ff95548](https://github.com/chaotic-ground/wikven/commit/ff95548b9948a946501ba714bfac1d70477b6959))
* read a subpage as a translation only where it says it is one ([#580](https://github.com/chaotic-ground/wikven/issues/580)) ([e967fb5](https://github.com/chaotic-ground/wikven/commit/e967fb50acc9254458f3328f3f65ff895ca47b03))
* read units the way Translate does ([#358](https://github.com/chaotic-ground/wikven/issues/358)) ([4642fc8](https://github.com/chaotic-ground/wikven/commit/4642fc8f891bcae7698866caeb85f4b568903a03))
* read Vector feature classes through classList ([#301](https://github.com/chaotic-ground/wikven/issues/301)) ([9dfebd1](https://github.com/chaotic-ground/wikven/commit/9dfebd14aa7af5dac93890225763378ab9460a57))
* rebase the printfooter link on a page exported into a subdirectory ([#422](https://github.com/chaotic-ground/wikven/issues/422)) ([5e5b905](https://github.com/chaotic-ground/wikven/commit/5e5b905096522558d959b4624ee1a8be8f2bfb78))
* refuse an extension or skin name that points outside the image ([#570](https://github.com/chaotic-ground/wikven/issues/570)) ([35c0678](https://github.com/chaotic-ground/wikven/commit/35c067847f329241ca0711caab4fa84dd4071738))
* refuse an image or asset path that climbs out of its directory ([#588](https://github.com/chaotic-ground/wikven/issues/588)) ([8fe34a1](https://github.com/chaotic-ground/wikven/commit/8fe34a13c9bf774bb7dc9b5a884217392c532eeb))
* refuse images the source tree only points at ([#596](https://github.com/chaotic-ground/wikven/issues/596)) ([38669f5](https://github.com/chaotic-ground/wikven/commit/38669f5a0ec3e751f7fa2283a9cbdea5ccd05ed8))
* render every translated page after all pages are marked ([#243](https://github.com/chaotic-ground/wikven/issues/243)) ([0d987d3](https://github.com/chaotic-ground/wikven/commit/0d987d3bb617873d2cbd8bd15f71ae06e59d5fad))
* reparent the local URLs a page carries in its JavaScript config ([#445](https://github.com/chaotic-ground/wikven/issues/445)) ([beba746](https://github.com/chaotic-ground/wikven/commit/beba746090cc7699a23f56c01e0f629b8f0e3f7d))
* replace fetchExtensions' curl subprocess with core's HTTP client ([#308](https://github.com/chaotic-ground/wikven/issues/308)) ([9be62ec](https://github.com/chaotic-ground/wikven/commit/9be62ecc7c9fc0405212d74e2050e595ef3e6241))
* replace hand-rolled balanced-div matcher with RemexHtml ([#314](https://github.com/chaotic-ground/wikven/issues/314)) ([3173c2d](https://github.com/chaotic-ground/wikven/commit/3173c2db91103f8ec5b7d60e3062621627e37e8e))
* report a vetoed File:/MediaWiki: import as a failure ([#291](https://github.com/chaotic-ground/wikven/issues/291)) ([8140261](https://github.com/chaotic-ground/wikven/commit/8140261f6d9de63beac9945c4ba0b7cc54070470))
* require real tag context before rewriting a relative reference ([#313](https://github.com/chaotic-ground/wikven/issues/313)) ([0f04ab6](https://github.com/chaotic-ground/wikven/commit/0f04ab685a3433d7594d3ee391f63c0e12cbb727))
* require UniversalLanguageSelector explicitly and disable its webfonts ([#218](https://github.com/chaotic-ground/wikven/issues/218)) ([1b3e1b7](https://github.com/chaotic-ground/wikven/commit/1b3e1b713ba978a00a17eed66598fe14ab4a7169))
* resolve $wgWikvenLogos through Title/RepoGroup, not by hand ([#315](https://github.com/chaotic-ground/wikven/issues/315)) ([6f4de14](https://github.com/chaotic-ground/wikven/commit/6f4de146887c6afb7e4280fb97f09c52fd566f4d))
* resolve phan findings and target MediaWiki 1.45 ([be87a48](https://github.com/chaotic-ground/wikven/commit/be87a483d4228e3f8eb84a1b1ae8b3344fa9956a))
* resolve translate mark/stamp file paths against the source directory ([#220](https://github.com/chaotic-ground/wikven/issues/220)) ([0aa95ea](https://github.com/chaotic-ground/wikven/commit/0aa95eaf3e91856ca14b91c7cc3566234db87d0b))
* retry and cache the build's Wikimedia Commons lookups ([#257](https://github.com/chaotic-ground/wikven/issues/257)) ([2ac2cce](https://github.com/chaotic-ground/wikven/commit/2ac2ccef0c1d855417411b65aff8c62f027ceeff))
* run the job queue in a fixed order ([#281](https://github.com/chaotic-ground/wikven/issues/281)) ([6aa694e](https://github.com/chaotic-ground/wikven/commit/6aa694edc8d2f370ecec4b3aa7e389b888c36790))
* say what language the licenses page's copies are in ([#562](https://github.com/chaotic-ground/wikven/issues/562)) ([4242977](https://github.com/chaotic-ground/wikven/commit/42429778c65bd6d58f670586c61e48329faab1a9))
* send a Special:MyLanguage link to the file it means ([#611](https://github.com/chaotic-ground/wikven/issues/611)) ([1aad459](https://github.com/chaotic-ground/wikven/commit/1aad459e1b7b3ede74952ed53e3fe9298ec0015a))
* set the maintenance user without StubGlobalUser ([8128dc5](https://github.com/chaotic-ground/wikven/commit/8128dc567987953add045159f36fb2b940f70175)), closes [#93](https://github.com/chaotic-ground/wikven/issues/93)
* settle the search bundle's language order between bakes ([#451](https://github.com/chaotic-ground/wikven/issues/451)) ([e7fc96c](https://github.com/chaotic-ground/wikven/commit/e7fc96c3b3da374c53d51d655a86b089e81782c5))
* show the search box when SifterSearch provides static search ([#99](https://github.com/chaotic-ground/wikven/issues/99)) ([a9cd689](https://github.com/chaotic-ground/wikven/commit/a9cd689b90f12bd41788ccd7d97f7a10f80d9dbf))
* small build-script and config papercuts ([#76](https://github.com/chaotic-ground/wikven/issues/76)) ([1302e23](https://github.com/chaotic-ground/wikven/commit/1302e2387f02364ab9e88b61beeb0ea44112c283))
* split translation units only on adjacent newlines, as Translate does ([#295](https://github.com/chaotic-ground/wikven/issues/295)) ([0feeebf](https://github.com/chaotic-ground/wikven/commit/0feeebf2c413a1e9cd0bbbd3ef412ca474b4256a))
* stop a new unit inheriting a deleted one's translations ([#564](https://github.com/chaotic-ground/wikven/issues/564)) ([5fdd228](https://github.com/chaotic-ground/wikven/commit/5fdd228cfcea13e9191160a37731f187711e9ccc))
* stop suppressing real categories; drop Version __NOINDEX__ ([#122](https://github.com/chaotic-ground/wikven/issues/122)) ([a3686bf](https://github.com/chaotic-ground/wikven/commit/a3686bfeb0bde0abc4c32acf593c908de5ab9c36))
* stop the build when an image of yours did not import ([#569](https://github.com/chaotic-ground/wikven/issues/569)) ([c78b572](https://github.com/chaotic-ground/wikven/commit/c78b572660e27ceade0c4f224c6f6b5628590167))
* stop the RLPAGEMODULES rewrite from editing article prose ([#296](https://github.com/chaotic-ground/wikven/issues/296)) ([9ef6b8a](https://github.com/chaotic-ground/wikven/commit/9ef6b8af95722146dbb3dc7f8fcb5a13df80c68c))
* stop three things going quiet ([#599](https://github.com/chaotic-ground/wikven/issues/599)) ([9103f0c](https://github.com/chaotic-ground/wikven/commit/9103f0ccc943a81fcc6a6428f4a28136fa2d8fd4))
* stop ULS reaching for input methods the export cannot serve ([#402](https://github.com/chaotic-ground/wikven/issues/402)) ([af60e0e](https://github.com/chaotic-ground/wikven/commit/af60e0e73d9ebb5afad77651a216e2a3dec047d8))
* strip the clocks ImageMagick writes into exported thumbnails ([#276](https://github.com/chaotic-ground/wikven/issues/276)) ([a1bb8d0](https://github.com/chaotic-ground/wikven/commit/a1bb8d05d8ad3f130a6dd63f0f0841bc0d2ca4e0))
* switch skins correctly from a translated page ([#258](https://github.com/chaotic-ground/wikven/issues/258)) ([6f85fe6](https://github.com/chaotic-ground/wikven/commit/6f85fe607ee720c226d4df8a1b4756828efdb1d1))
* take the DISPLAYTITLE spellings from the magic word registry ([#293](https://github.com/chaotic-ground/wikven/issues/293)) ([7c88a7a](https://github.com/chaotic-ground/wikven/commit/7c88a7a88e503351d02e99443405de8c782c2844))
* take the translations comment down rather than turn it into an all-clear ([#595](https://github.com/chaotic-ground/wikven/issues/595)) ([6b88a10](https://github.com/chaotic-ground/wikven/commit/6b88a10fe870bcc01788586ce4d7b989fcfe3942))
* **tf:** skip the vulnerability-alerts import when the resource is absent ([#265](https://github.com/chaotic-ground/wikven/issues/265)) ([b5d3ba3](https://github.com/chaotic-ground/wikven/commit/b5d3ba32d7b2d1d5251d407732ca942b86b5cbd3))
* translate a page whose file name is not spelled like its title ([#567](https://github.com/chaotic-ground/wikven/issues/567)) ([6ec596a](https://github.com/chaotic-ground/wikven/commit/6ec596a3f66dc273746cfee20c49125049df1dc1))
* turn the parser cache off so a bake reads its own finished state ([#335](https://github.com/chaotic-ground/wikven/issues/335)) ([91036c3](https://github.com/chaotic-ground/wikven/commit/91036c3a914a0f36aac7d251fa20b6cfabb81119))
* use CSSMin's data: URI encoder in AssetLocalizer ([#310](https://github.com/chaotic-ground/wikven/issues/310)) ([6fe63d5](https://github.com/chaotic-ground/wikven/commit/6fe63d51be29663410014a24868b5e5c0c94529a))
* validate .wikven.yaml value shapes and flag warnings clearly ([#163](https://github.com/chaotic-ground/wikven/issues/163)) ([021f819](https://github.com/chaotic-ground/wikven/commit/021f819cc42263e1e82cb4feec764b55db89ea65))
* wrap a word too long for the screen instead of scrolling the page ([#545](https://github.com/chaotic-ground/wikven/issues/545)) ([818a0e9](https://github.com/chaotic-ground/wikven/commit/818a0e918d926c1ee1c033a8bfdf07ae639eba87))


### Performance

* freeze page_touched once, in the orchestrator ([#431](https://github.com/chaotic-ground/wikven/issues/431)) ([a2d878b](https://github.com/chaotic-ground/wikven/commit/a2d878b497007c63f3b343778e0649dc99fa8516))
* render the skins beside each other ([#437](https://github.com/chaotic-ground/wikven/issues/437)) ([33bbbfb](https://github.com/chaotic-ground/wikven/commit/33bbbfbcfa60e8f4d3f8fe0790d02c5e5c0e1027))
* stop rendering the history pages the export throws away ([#432](https://github.com/chaotic-ground/wikven/issues/432)) ([39fe30c](https://github.com/chaotic-ground/wikven/commit/39fe30c5db309f58a8ea21f48c2ab182337e0353))


### Refactoring

* group the composite actions under actions/ ([#359](https://github.com/chaotic-ground/wikven/issues/359)) ([01f0f3d](https://github.com/chaotic-ground/wikven/commit/01f0f3d42a6624cdff5c55884779a9de368ab61e))
