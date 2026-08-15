# Base images are digest-pinned and version-tagged so Dependabot's bumps read as version numbers
# rather than digests. Dependabot keeps them current. The pin fixes the base images alone: apk,
# codeload and Packagist below are all read at build time, so the image is not reproducible.
FROM composer:2.10.2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 AS composer

# The alpine variant, because nothing here serves over HTTP: `build` runs a maintenance script and
# `serve` runs PHP's own server, so the Apache the default variant carries is never started. Same
# PHP extensions, 873MB against 1.5GB.
FROM mediawiki:1.46.0-fpm-alpine@sha256:b0e9413c015268322cfb67908e5f92121372c7407f09f97a4ce8938a4351e4ad

# composer to install third-party extensions/skins at bake time (git/tar/gzip/unzip present).
# rsvg-convert renders SVG thumbnails, and alpine splits ImageMagick's delegates out, so its
# convert reads only the PNG family until these are added. Together they cover every type
# FileExtensions allows: png and gif are built in, svg goes through rsvg, and these two are the
# rest. The formats still absent (TIFF, PDF, HEIC, camera RAW) are ones uploads reject anyway.
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache rsvg-convert imagemagick-jpeg imagemagick-webp

# Bundled extensions come from stable external sources; fetch them before copying wikven's own
# code so edits to that code do not bust the (slow) download/clone layers.

# SifterSearch (client-side Pagefind search) ships built in. Its release tarball carries the
# per-arch Pagefind binary a git clone omits, so fetch the one matching this build's architecture.
ARG TARGETARCH
# Bumped by updatecli, see updatecli/updatecli.d/siftersearch.yaml; no package manager reads this.
ARG SIFTERSEARCH_VERSION=v0.6.1
RUN arch="$TARGETARCH" \
 && if [ "$arch" = amd64 ]; then arch=x64; fi \
 && curl -fsSL "https://github.com/chaotic-ground/SifterSearch/releases/download/${SIFTERSEARCH_VERSION}/SifterSearch-linux-${arch}.tar.gz" \
  | tar -xz -C /var/www/html/extensions/

# Content i18n (opt-in via WikvenI18nLanguages): Translate renders translated pages and the
# <languages/> bar; UniversalLanguageSelector is its hard load-time dependency. Both track this
# image's MediaWiki branch. Translate pulls its runtime Composer deps (spyc) into its own vendor/,
# which its load_composer_autoloader then loads.
ENV COMPOSER_ALLOW_SUPERUSER=1
ARG TRANSLATE_VERSION=REL1_46
ARG ULS_VERSION=REL1_46
# Composer refuses to resolve at all when any package in the tree carries a security advisory,
# including a require-dev one that --no-dev then never installs. Translate's dev requirements pin
# a phpcs release that has one, which broke every build reaching this layer without a warm cache.
# The audit still reports on what is installed; only the hard stop is off.
# Fetched as tarballs: a clone carries a .git nothing here reads, and transfers several times the
# bytes for it. Downloaded before extracting rather than piped, so a failed fetch fails the build
# instead of feeding tar an empty stream.
RUN composer config --global policy.advisories.block false \
 && ext=/var/www/html/extensions \
 && curl -fsSL -o /tmp/uls.tar.gz \
      "https://codeload.github.com/wikimedia/mediawiki-extensions-UniversalLanguageSelector/tar.gz/refs/heads/$ULS_VERSION" \
 && curl -fsSL -o /tmp/translate.tar.gz \
      "https://codeload.github.com/wikimedia/mediawiki-extensions-Translate/tar.gz/refs/heads/$TRANSLATE_VERSION" \
 && mkdir -p "$ext/UniversalLanguageSelector" "$ext/Translate" \
 && tar -xzf /tmp/uls.tar.gz --strip-components=1 -C "$ext/UniversalLanguageSelector" \
 && tar -xzf /tmp/translate.tar.gz --strip-components=1 -C "$ext/Translate" \
 && rm /tmp/uls.tar.gz /tmp/translate.tar.gz \
 && composer install --no-dev --no-interaction \
      --working-dir="$ext/Translate"

COPY ./ /var/www/html/extensions/Wikven
COPY includes/WikvenSettings.php /var/www/html/
COPY bin/entrypoint /usr/local/bin/entrypoint
# Entry point is wikven's run script; the arg is the subcommand (default "build"; "serve" previews).
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["build"]
