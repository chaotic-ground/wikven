# Skin preview

A source tree for looking at a skin rather than publishing a site.

One page carries every element a skin has to style — the whole heading ladder, running prose long
enough to judge a measure by, nested and mixed lists, a definition list, a plain table and a
floating one, thumbnails on both sides, a frameless image, a gallery, a block quotation, indents,
preformatted text and an unbreakable token — so you can bake it once and see the surface all at
once, instead of finding out three sites later that nobody gave the definition lists a margin.

`.wikven.yml` sets `WikvenSkinPreview`, so wikven leaves the chrome alone: the personal menu, the
toolbox, the tabs and the footer are your skin's own work, not wikven's reading of them.

## Using it

Add your skin to `.wikven.yml`:

```yaml
skins:
  - YourSkin
```

A skin wikven does not bundle also needs a source in `WikvenRepositories`; the Extensions page on
the wikven site has the shape of one.

Then bake this directory as the source. With the image:

```sh
docker run --rm -v "$PWD:/workspace" ghcr.io/chaotic-ground/wikven
```

where `/workspace/src` is this directory and the site is written to `/workspace/dist`. In a
workflow, point the bake action at it:

```yaml
- uses: chaotic-ground/wikven/actions/bake@main
  with:
    source: examples/skin-preview
```

Open `dist/index.html`, and `dist/<your-skin>/index.html` for every skin after the first.

## What you will see that a real site would not

A toolbox full of `Special:` links that go nowhere, a login that does nothing, a talk tab with
nothing behind it. That is the point: it is what your skin renders. `WikvenSkinPreview` is off by
default for exactly this reason, and a published site should leave it off.

## Editing it

This tree is meant to be edited. If your skin styles something this page does not exercise, add
it — the page is only useful to the extent it covers what you are working on.
