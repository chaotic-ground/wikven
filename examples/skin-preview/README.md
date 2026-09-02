# Skin preview

A source tree for looking at a skin rather than publishing a site.

> **This mode is experimental, and stays so.** A skin is written against several MediaWiki
> releases; a bake renders on the one wikven carries. What you get is your skin on that version,
> and no evidence about any other — less than a skin author needs, and more than this project can
> widen. Useful for seeing the surface of your own work; not a substitute for testing on the
> versions you support.

One page carries every element a skin has to style — the whole heading ladder, running prose long
enough to judge a measure by, nested and mixed lists, a definition list, a plain table and a
floating one, thumbnails on both sides, a frameless image, a gallery, a block quotation, indents,
preformatted text and an unbreakable token — so you can bake it once and see the surface all at
once, instead of finding out three sites later that nobody gave the definition lists a margin.

`.wikven.yaml` sets `WikvenBuildFor: skin-preview`, so wikven leaves the chrome alone: the personal
menu, the toolbox, the tabs and the footer are your skin's own work, not wikven's reading of them.

## Using it

Add your skin to `.wikven.yaml`:

```yaml
skins:
  - YourSkin
```

A skin wikven does not bundle also needs a source in `WikvenRepositories`; the Extensions page on
the wikven site has the shape of one.

Then bake this directory as the source. With the image:

```sh
docker run --rm \
  -v "$PWD:/workspace/src" \
  -v "$PWD/dist:/workspace/dist" \
  ghcr.io/chaotic-ground/wikven
```

The source mount is this directory itself: the build reads `/workspace/src`, so mounting the tree
one level up at `/workspace` leaves it with nothing to read. In a workflow, point the bake action
at it, which does the mounting for you:

```yaml
- uses: chaotic-ground/wikven/actions/bake@nightly-YYYY-MM-DD
  with:
    source: examples/skin-preview
```

Pin the action to a tag rather than a branch. Until a version of wikven is released that tag is a
nightly — the dated pre-releases on the releases page, newest first; once one is released,
`@v1.2.3` on its own is enough: the tag carries the `v`.

Open `dist/index.html`, and `dist/<your-skin>/index.html` for every skin after the first.

## What you will see that a real site would not

A toolbox full of `Special:` links that go nowhere, a login that does nothing, a talk tab with
nothing behind it. That is the point: it is what your skin renders. `WikvenBuildFor` is `site` by
default for exactly this reason, and a published site should leave it there.

## Editing it

This tree is meant to be edited. If your skin styles something this page does not exercise, add
it — the page is only useful to the extent it covers what you are working on.
