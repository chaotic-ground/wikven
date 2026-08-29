![Wikven logo](docs/logo.png)

# Wikven

**Wikven** is a static web page generator that uses [MediaWiki], the software used on [Wikipedia].

Read more on <https://chaotic-ground.github.io/wikven/>.

## License

Wikven is licensed [GPL-3.0-or-later](LICENSE).

The Docker image and the standalone binary it produces redistribute third-party software under
that software's own licenses, acknowledged here. GPL-2.0-or-later and GPL-3.0-or-later are
compatible, so this is attribution rather than a conflict.

| Component | License | Bundled in |
| --- | --- | --- |
| [MediaWiki](https://www.mediawiki.org/), with its bundled skins and extensions | GPL-2.0-or-later | image, binary |
| [PHP](https://www.php.net/) | PHP License 3.01 | binary |
| [FrankenPHP](https://frankenphp.dev/) | MIT | binary |
| [Caddy](https://caddyserver.com/) | Apache-2.0 | binary |
| [Composer](https://getcomposer.org/) | MIT | image |

The binary is compiled with FrankenPHP, which also links several Caddy modules; see the FrankenPHP
project for their licenses. The image keeps MediaWiki's own `COPYING`, and each component's full
license text is available from its project.

That is what wikven ships. What a site built with it publishes is its own set, and every build
writes it out: see the Licenses page of any wikven site, [this documentation's
included](https://chaotic-ground.github.io/wikven/Licenses).

[![Lint](https://github.com/chaotic-ground/wikven/actions/workflows/lint.yml/badge.svg)](https://github.com/chaotic-ground/wikven/actions/workflows/lint.yml)
[![codecov](https://codecov.io/gh/chaotic-ground/wikven/graph/badge.svg)](https://codecov.io/gh/chaotic-ground/wikven)

[mediawiki]: https://www.mediawiki.org/
[wikipedia]: https://www.wikipedia.org/
