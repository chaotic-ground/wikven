# Changelog

## [1.0.0](https://github.com/chaotic-ground/wikven/compare/v0.1.0...v1.0.0) (2026-06-14)


### ⚠ BREAKING CHANGES

* WikvenLogos (mirror $wgLogos), logos in docs/, SVG uploads ([#40](https://github.com/chaotic-ground/wikven/issues/40))
* the "WikvenLogo: <file>" config key is replaced by a
* **config:** configuration lives in .wikven.yaml with a new schema; .wikven.json is no longer read.

### Features

* add a `wikven build` command to the binary ([9ed60b7](https://github.com/chaotic-ground/wikven/commit/9ed60b762cfe5889fbbcb401b39440f589179437))
* add a configurable header logo and the Wikven brand mark ([a67ba00](https://github.com/chaotic-ground/wikven/commit/a67ba0057036ba228e5ed82b726c784799594c05))
* add a configurable header logo and the Wikven brand mark ([994ed33](https://github.com/chaotic-ground/wikven/commit/994ed33bb2e0244a6a16d8b03bf4a79a1fc7eb78))
* add a wikven build command to the binary ([#49](https://github.com/chaotic-ground/wikven/issues/49)) ([31a5e05](https://github.com/chaotic-ground/wikven/commit/31a5e05532419877ad161deca979404a6da5ccee))
* **config:** adopt MediaWiki's YAML settings format ([ae7e652](https://github.com/chaotic-ground/wikven/commit/ae7e65263108edadfdf6f6b38c7e230e0ae2c5bd))
* **config:** read .wikven.json as a fallback to .wikven.yaml ([048c0ea](https://github.com/chaotic-ground/wikven/commit/048c0ea8d5429fc427f3fe623f8415adfb3496e2))
* **config:** read .wikven.json as a fallback to .wikven.yaml ([ae8ada1](https://github.com/chaotic-ground/wikven/commit/ae8ada1718c62e6eb5461473d1f5d37e3cd16b28))
* distribute a standalone binary ([3af94e9](https://github.com/chaotic-ground/wikven/commit/3af94e9b88f792246b56edec521b2401caaa61f2))
* distribute a standalone binary ([#45](https://github.com/chaotic-ground/wikven/issues/45)) ([0a71ced](https://github.com/chaotic-ground/wikven/commit/0a71cedf6c773c79cb5af1165b88b227859b3402))
* enable bundled extensions safely, skipping ones not in the image ([707b921](https://github.com/chaotic-ground/wikven/commit/707b921c8dd229b22029a679e88fe7a211751ec2))
* enable bundled extensions safely, skipping ones not in the image ([26b41f6](https://github.com/chaotic-ground/wikven/commit/26b41f6cbc5ae3dd1a5ebbbb0d44ba58bda655bd))
* export site styles and teal-brand the docs ([e3cfa0a](https://github.com/chaotic-ground/wikven/commit/e3cfa0aa0e608de7838b9d1e1f6d7b01915ed661))
* export site styles and teal-brand the docs ([#46](https://github.com/chaotic-ground/wikven/issues/46)) ([18ba51a](https://github.com/chaotic-ground/wikven/commit/18ba51a6bf36402e90f0f58a08ad87947908ef40))
* hide the edit and history tabs when their URLs are not configured ([#17](https://github.com/chaotic-ground/wikven/issues/17)) ([b207bef](https://github.com/chaotic-ground/wikven/commit/b207bef3f548ad383e2b833b6d40007864ea7df2)), closes [#11](https://github.com/chaotic-ground/wikven/issues/11)
* **images:** export File: pages and support description sidecars ([662108d](https://github.com/chaotic-ground/wikven/commit/662108d1db4fb8dbd882f30e9e42e031cd41d5b8))
* load MediaWiki defaults from a default.yaml config layer ([6463fc2](https://github.com/chaotic-ground/wikven/commit/6463fc25c3b83ff6481b091ceed0d616a38e9514))
* load MediaWiki defaults from a default.yaml config layer ([#41](https://github.com/chaotic-ground/wikven/issues/41)) ([ca200b5](https://github.com/chaotic-ground/wikven/commit/ca200b5b36c49aa2fd795ef43bc9ac407e3e92df))
* localize direct-path skin assets and the placeholder logo ([59c9cfc](https://github.com/chaotic-ground/wikven/commit/59c9cfc89b7cf6ed81cbc697d7eafc02988b3c46))
* localize ResourceLoader icon images in the static export ([6c60985](https://github.com/chaotic-ground/wikven/commit/6c60985fe1a6c9557c6efbc745c52086fed18cb9))
* **logo:** drop the bread body, keep the folds, round the oven mouth ([7307e61](https://github.com/chaotic-ground/wikven/commit/7307e610674793ae3fb3ca43e980236e09e37e83))
* **logo:** drop the bread, round the oven mouth, move to assets/ ([#37](https://github.com/chaotic-ground/wikven/issues/37)) ([f642e41](https://github.com/chaotic-ground/wikven/commit/f642e41b4a3673549062e88d5eacbd9dbdcae6bd))
* make skin JavaScript work in the static export ([746401e](https://github.com/chaotic-ground/wikven/commit/746401e204227b83f5133a5249367319f4583264))
* move logos into docs/ and allow SVG uploads ([64a14e1](https://github.com/chaotic-ground/wikven/commit/64a14e14a3dea0be19f694c9dc44324a7c5c9db2))
* persist Vector "move to sidebar" pin state on the static export ([4405cf9](https://github.com/chaotic-ground/wikven/commit/4405cf9c61ddd5b072afd4ce9f9c11305f9f350e))
* persist Vector "move to sidebar" pin state on the static export ([df1ded4](https://github.com/chaotic-ground/wikven/commit/df1ded4e2034aeec4d9129eb0b301481451c7a44))
* remove the non-functional search box from the static output ([cd90325](https://github.com/chaotic-ground/wikven/commit/cd90325648df701bd6285442a9ca9a395d271cce))
* replace WikvenLogo with WikvenLogos mirroring $wgLogos ([0bde57a](https://github.com/chaotic-ground/wikven/commit/0bde57a6a7faef8ee4249a748d996fb8381712b5))
* run site JavaScript and gadgets ([bcdfaaf](https://github.com/chaotic-ground/wikven/commit/bcdfaaffd944c933dae40e4d485d84cc1302d541))
* run site JavaScript and gadgets ([#39](https://github.com/chaotic-ground/wikven/issues/39)) ([9529a7d](https://github.com/chaotic-ground/wikven/commit/9529a7d6ca590210bbb138edab0c51dfdda3c911))
* set the real last-modified date on the footer ([#18](https://github.com/chaotic-ground/wikven/issues/18)) ([46b5ffa](https://github.com/chaotic-ground/wikven/commit/46b5ffab15453d5b61eb309c7d6c51c9c39f558d)), closes [#12](https://github.com/chaotic-ground/wikven/issues/12)
* ship a built-in favicon ([9db7b84](https://github.com/chaotic-ground/wikven/commit/9db7b8431ec441d41a755f0f1c4ef80d3db47389))
* store hotlinked Commons images locally for a self-contained export ([4daf8fc](https://github.com/chaotic-ground/wikven/commit/4daf8fc62c96a3837a38bf1927ac2e2ba886b0e3))
* store hotlinked Commons images locally for a self-contained export ([79d74ae](https://github.com/chaotic-ground/wikven/commit/79d74ae3cbbbdda042abffc9a273090a21a165e8))
* support local images ([a73aba1](https://github.com/chaotic-ground/wikven/commit/a73aba11f197fadb3b9e91fe85d4bca119036bb3))
* support local images ([#38](https://github.com/chaotic-ground/wikven/issues/38)) ([9322e90](https://github.com/chaotic-ground/wikven/commit/9322e90793b848f600ef4e10fcbe9669f41751c5))
* support third-party extensions and skins ([fc95348](https://github.com/chaotic-ground/wikven/commit/fc95348929df9fe42a2dfc4b368953398969fda1))
* support third-party extensions and skins ([#43](https://github.com/chaotic-ground/wikven/issues/43)) ([552fede](https://github.com/chaotic-ground/wikven/commit/552fede16db5b753f8f12ae31bbb27f3e31aed80))
* switch docs skin to Vector and hide read-only chrome ([198e96f](https://github.com/chaotic-ground/wikven/commit/198e96f39fc8ca864943e76d010ade5547bc8e71))
* switch the docs site to the MinervaNeue skin ([6c42b21](https://github.com/chaotic-ground/wikven/commit/6c42b21c3034299f052896d4c108a6fed7f685bd))
* upgrade to MediaWiki 1.45 ([dea7ffc](https://github.com/chaotic-ground/wikven/commit/dea7ffcecf4dd76ed87b2add8ddda86b279b3c7b))
* upgrade to MediaWiki 1.45 ([#52](https://github.com/chaotic-ground/wikven/issues/52)) ([a8d4907](https://github.com/chaotic-ground/wikven/commit/a8d490770b359424fa6fb56f7dcceec58f2ad84c))
* WikvenLogos (mirror $wgLogos), logos in docs/, SVG uploads ([#40](https://github.com/chaotic-ground/wikven/issues/40)) ([d65227b](https://github.com/chaotic-ground/wikven/commit/d65227b97e12997e01d564fe9cccd666c086a5d3))


### Bugfixes

* address mediawiki-codesniffer findings ([410a642](https://github.com/chaotic-ground/wikven/commit/410a642982fb384d84e25d8d2907a89af039a7e6))
* link images to their Commons page instead of a dead local File page ([f17d96d](https://github.com/chaotic-ground/wikven/commit/f17d96d2c831b979b84aca0cf4f96d55a6152702))
* link images to their Commons page instead of a dead local File page ([580f7d4](https://github.com/chaotic-ground/wikven/commit/580f7d4597301e2b177359e1111ec7874be739d0))
* **repo:** declare GitHub Pages so it is not removed ([3d41e30](https://github.com/chaotic-ground/wikven/commit/3d41e30088463adaf226d7467b57372ac4df5004))
* **repo:** re-gate ruleset bypass actors for the stateless CI plan ([ee52078](https://github.com/chaotic-ground/wikven/commit/ee520787ef0bf98a08fe5329fe78baf3b9a895f5))
* **repo:** stop the OpenTofu ruleset from re-planning on every CI run ([df894d9](https://github.com/chaotic-ground/wikven/commit/df894d9ba2cd231a3293d7db76f4550552a36519))
* **repo:** stop the OpenTofu ruleset from re-planning on every CI run ([#36](https://github.com/chaotic-ground/wikven/issues/36)) ([75ec3cc](https://github.com/chaotic-ground/wikven/commit/75ec3ccb55bbca03523c762558d019829a5746f3))
* wrap Template:ml in &lt;onlyinclude&gt; so it emits no trailing newline ([47b4d57](https://github.com/chaotic-ground/wikven/commit/47b4d5742361da19e7c535c3a0ed261aa34ed2f7))
