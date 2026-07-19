# Base images are digest-pinned for reproducible builds; Dependabot keeps them current.
FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS composer

FROM mediawiki:1.45@sha256:6dc859706b561acf90a0f92786280f6e461946c4cc2fa8ea5c74be6c27251d2c

# composer + unzip to install third-party extensions/skins at build time (git/tar/gzip present).
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*

# Bundled extensions come from stable external sources; fetch them before copying wikven's own
# code so edits to that code do not bust the (slow) download/clone layers.

# SifterSearch (client-side Pagefind search) ships built in. Its release tarball carries the
# per-arch Pagefind binary a git clone omits, so fetch the one matching this build's architecture.
ARG TARGETARCH
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
ARG TRANSLATE_VERSION=REL1_45
ARG ULS_VERSION=REL1_45
RUN git clone --depth 1 --branch "$ULS_VERSION" \
      https://github.com/wikimedia/mediawiki-extensions-UniversalLanguageSelector.git \
      /var/www/html/extensions/UniversalLanguageSelector \
 && git clone --depth 1 --branch "$TRANSLATE_VERSION" \
      https://github.com/wikimedia/mediawiki-extensions-Translate.git \
      /var/www/html/extensions/Translate \
 && composer update --no-dev --no-interaction \
      --working-dir=/var/www/html/extensions/Translate

COPY ./ /var/www/html/extensions/Wikven
COPY includes/WikvenSettings.php /var/www/html/
COPY bin/entrypoint /usr/local/bin/entrypoint
# Entry point is wikven's run script; the arg is the subcommand (default "build"; "serve" previews).
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["build"]
