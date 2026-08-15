# Base images are digest-pinned for reproducible builds; Dependabot keeps them current.
FROM composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 AS composer

FROM mediawiki:1.46@sha256:38989f476fd3226bd608816547e2f8eee88c1582d656e9b39c65a2e5ddbdacc6

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
# renovate: datasource=github-releases depName=chaotic-ground/SifterSearch
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
