![Wikven logo](docs/logo.png)

# Wikven

**Wikven** is a static web page generator that uses [MediaWiki], the software used on [Wikipedia].

Read more on <https://chaotic-ground.github.io/wikven/>.

## Use in a GitHub workflow

Bake a wiki source tree into a static site with the composite action. Pin it to a
full commit SHA rather than a tag: a tag can be moved to point at altered code, a
commit SHA cannot, so pinning it is a basic defense against a supply-chain attack.

```yaml
- uses: chaotic-ground/wikven/action@19392b75d379de9e349ef2963c11ec5545c3e6ab  # v1.0.0
  with:
    source: docs   # directory of source files (default: src)
    output: dist   # where the static site is written (default: dist)
```

Copy the SHA of the [release](https://github.com/chaotic-ground/wikven/releases) you
want and record its version in the trailing comment. It runs the published Docker
image; pin a release tag (e.g. `ghcr.io/chaotic-ground/wikven:1.0.0`) via the `image`
input for reproducible builds.

[![Lint](https://github.com/chaotic-ground/wikven/actions/workflows/lint.yaml/badge.svg)](https://github.com/chaotic-ground/wikven/actions/workflows/lint.yaml)
[![codecov](https://codecov.io/gh/chaotic-ground/wikven/graph/badge.svg)](https://codecov.io/gh/chaotic-ground/wikven)

[mediawiki]: https://www.mediawiki.org/
[wikipedia]: https://www.wikipedia.org/
