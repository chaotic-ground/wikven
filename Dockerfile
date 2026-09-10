# Digest-pinned and version-tagged, so dependabot's bumps read as versions. The pin fixes the base
# images alone: apk below resolves against the current Alpine index, so the image is not reproducible.
FROM composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer

# The alpine variant: nothing here serves over HTTP, so the default variant's Apache never starts.
# Same extensions, 873MB against 1.5GB. A bump onto a new release branch has to take the bundled
# extensions with it; their branch is in updatecli/updatecli.d/mediawiki-extensions.yaml.
FROM mediawiki:1.46.0-fpm-alpine@sha256:b0e9413c015268322cfb67908e5f92121372c7407f09f97a4ce8938a4351e4ad

# composer installs third-party extensions at bake time. rsvg-convert renders SVG thumbnails, and
# alpine splits ImageMagick's delegates out, so its convert reads only the PNG family without these.
# Together they cover every type FileExtensions allows; the rest (TIFF, PDF, HEIC, RAW) is refused.
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache rsvg-convert imagemagick-jpeg imagemagick-webp

# base_convert without gmp is long division written in PHP, one round per digit, and a bake asks for
# it constantly: every file lock names itself by a sha1 in base 36, and so does every generated id.
# The base image builds the extensions a wiki serving readers needs, and this is not one of them.
# Worth 2% of a bake, measured; bcmath, the middle path, is not here either.
#
# Compiled rather than installed: no package carries a gmp built for this PHP. The headers and the
# toolchain come and go inside the one layer, leaving the shared library and the extension.
RUN apk add --no-cache gmp \
 && apk add --no-cache --virtual .gmp-build $PHPIZE_DEPS gmp-dev \
 && docker-php-ext-install -j "$(nproc)" gmp \
 && apk del --no-network .gmp-build

# Fetched before wikven's own code is copied in, so an edit there does not bust the slow layers.

# "Stable source" describes the bytes, not the service in front of them: GitHub served 500s for
# hours on 2026-08-17, and codeload answers 429 under load. --retry-all-errors as well as --retry,
# because curl counts a connection reset as non-transient and would not repeat it.
ARG CURL_RETRY="--retry 5 --retry-delay 2 --retry-all-errors"

# And who is asking: curl signs with its own version, naming the library rather than the project.
# No wikven version -- the release this is cut from is not known until its code is copied in.
ARG CURL_AGENT="Wikven image build (+https://github.com/chaotic-ground/wikven)"

# SifterSearch's release tarball carries the per-arch Pagefind binary a clone omits. Downloaded
# before extracting rather than piped: a retried transfer restarts, and tar reading one has already
# been fed the first attempt's bytes.
ARG TARGETARCH
ARG SIFTERSEARCH_VERSION=v0.8.0
RUN arch="$TARGETARCH" \
 && if [ "$arch" = amd64 ]; then arch=x64; fi \
 && curl -fsSL $CURL_RETRY -A "$CURL_AGENT" -o /tmp/siftersearch.tar.gz \
      "https://github.com/chaotic-ground/SifterSearch/releases/download/${SIFTERSEARCH_VERSION}/SifterSearch-linux-${arch}.tar.gz" \
 && tar -xzf /tmp/siftersearch.tar.gz -C /var/www/html/extensions/ \
 && rm /tmp/siftersearch.tar.gz

# Content i18n, opt-in by listing Translate in a site's .wikven.yaml; UniversalLanguageSelector is
# its load-time dependency. Both track this image's MediaWiki branch. Translate pulls spyc into its
# own vendor/, which its load_composer_autoloader loads.
ENV COMPOSER_ALLOW_SUPERUSER=1
# Commits, not the branch tip: REL1_46 takes translatewiki updates weekly, so a branch pin would
# build a different Translate from the same wikven commit a week later. updatecli moves them.
ARG TRANSLATE_VERSION=afbd690fcf71a21dbd3939f50f97e0cff88a840d
ARG ULS_VERSION=f914eba81f7f7196140febbfce3ed6e17d65ba22
# Translate asks for both by range, so an unpinned install takes whatever Packagist serves that day.
# Exact versions, as temporary constraints rather than written into Translate's manifest.
ARG SPYC_VERSION=0.6.3
ARG COMPOSER_INSTALLERS_VERSION=v2.3.0
# Composer refuses to resolve at all while anything in the tree carries an advisory, including a
# require-dev one --no-dev never installs -- which Translate's pinned phpcs has. The audit still
# reports; only the hard stop is off.
#
# Tarballs rather than clones, which carry a .git nothing here reads. The php check ahead of the
# update fails the build if Translate's runtime requirements stop matching the ARGs above, so a new
# dependency cannot slip in unpinned.
RUN composer config --global policy.advisories.block false \
 && ext=/var/www/html/extensions \
 && curl -fsSL $CURL_RETRY -A "$CURL_AGENT" -o /tmp/uls.tar.gz \
      "https://codeload.github.com/wikimedia/mediawiki-extensions-UniversalLanguageSelector/tar.gz/$ULS_VERSION" \
 && curl -fsSL $CURL_RETRY -A "$CURL_AGENT" -o /tmp/translate.tar.gz \
      "https://codeload.github.com/wikimedia/mediawiki-extensions-Translate/tar.gz/$TRANSLATE_VERSION" \
 && mkdir -p "$ext/UniversalLanguageSelector" "$ext/Translate" \
 && tar -xzf /tmp/uls.tar.gz --strip-components=1 -C "$ext/UniversalLanguageSelector" \
 && tar -xzf /tmp/translate.tar.gz --strip-components=1 -C "$ext/Translate" \
 && rm /tmp/uls.tar.gz /tmp/translate.tar.gz \
 && php -r '$have = array_keys(json_decode(file_get_contents($argv[1]), true)["require"]); $want = explode(" ", $argv[2]); sort($have); sort($want); if ($have !== $want) { fwrite(STDERR, "Translate requires " . implode(" ", $have) . ", pinned " . implode(" ", $want) . "\n"); exit(1); }' \
      -- "$ext/Translate/composer.json" "composer/installers mustangostang/spyc" \
 && composer update --no-dev --no-interaction \
      --working-dir="$ext/Translate" \
      --with "mustangostang/spyc:$SPYC_VERSION" \
      --with "composer/installers:$COMPOSER_INSTALLERS_VERSION"

# SiteUrl builds every absolute URL on Guzzle's PSR-7 Uri, which is here as core's dependency
# rather than wikven's. Nothing declares it on wikven's behalf, so a release that dropped guzzle
# would otherwise be found by a site whose sitemap had gone missing.
RUN php -r 'require "/var/www/html/vendor/autoload.php"; if (!class_exists("GuzzleHttp\\Psr7\\Uri") || !class_exists("GuzzleHttp\\Psr7\\UriResolver")) { fwrite(STDERR, "MediaWiki no longer vendors guzzlehttp/psr7; SiteUrl needs it\n"); exit(1); }'

# A bake is not one PHP process. The entrypoint runs four, and the build starts one child per parse
# shard and one per skin, each compiling MediaWiki from source over again: opcache ships enabled for
# the web and off for the command line. The file cache is what separate processes share.
#
# Its directory has to exist before PHP starts, or opcache drops the setting without a word. Last of
# the RUNs, so the image ships that directory empty and a bake fills it with its own compiles.
#
# The sizes are the base image's, raised: a bake's heaviest process holds 50MB of opcodes and 2,100
# files, and a site loads extensions of its own on top. Its revalidate_freq goes back to zero: 60
# seconds suits a web process that outlives the files it serves, and a bake writes PHP to disk after
# PHP has started -- the extensions it fetches, and the line the entrypoint appends to
# LocalSettings.php. Named to sort after opcache-recommended.ini, conf.d being read in order.
RUN mkdir -p /var/cache/wikven-opcache \
 && printf '%s\n' \
      'opcache.enable_cli=1' \
      'opcache.file_cache=/var/cache/wikven-opcache' \
      'opcache.revalidate_freq=0' \
      'opcache.memory_consumption=256' \
      'opcache.max_accelerated_files=10000' \
      > /usr/local/etc/php/conf.d/zz-wikven-opcache.ini

COPY ./ /var/www/html/extensions/Wikven
COPY includes/WikvenSettings.php /var/www/html/
COPY bin/entrypoint /usr/local/bin/entrypoint
# The arg is the subcommand: "build" bakes a site, "serve" previews one.
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["build"]
