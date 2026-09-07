# A standalone FrankenPHP binary embedding MediaWiki and Wikven, so a bake runs without Docker.
# Built per architecture by .github/workflows/binary.yml and attached to the release.
#
# Usage (build the wikven image first, e.g. `docker build -t wikven .`):
#   docker build -f binary.Dockerfile --target export -o type=local,dest=out .
#   # -> out/wikven. Run it with: WIKVEN_WORKDIR=. ./wikven build
#   # On a GitHub API rate limit, add: --secret id=github-token,env=GITHUB_TOKEN

# Stage 1: the tree to embed -- the wikven image, less what a bake never reads. Locales stay, so
# the binary is not English-only.
FROM wikven AS app
# One entry point per subcommand, plus the prelude they share.
COPY bin/build.php bin/prepare.php bin/translate.php /var/www/html/
RUN find /var/www/html -type d -name tests -prune -exec rm -rf {} + \
 && rm -rf \
      /var/www/html/HISTORY \
      /var/www/html/UPGRADE \
      /var/www/html/RELEASE-NOTES-* \
      /var/www/html/cache/* \
      /var/www/html/images/*

# Stage 2: a static PHP + FrankenPHP with the app embedded. curl is left out -- its HTTP/3 static
# libs fail to link and nothing needs it, Guzzle going through PHP's stream wrappers. Pinned like
# the base above, though build-static.sh fetches a nightly static-php-cli that picks the libraries.
FROM dunglas/frankenphp:static-builder-musl-1.12.7@sha256:5b7c8d9c3da7fc672154796039365f4e25ae9ff77a1cafb783c6db313863655d AS builder
WORKDIR /go/src/app
COPY --from=app /var/www/html ./dist/app
# A small Caddy module registers the build, serve and translate subcommands, so the binary is run
# as `./wikven build` rather than `./wikven php-cli build.php`.
#
# This replaces FrankenPHP's default module list rather than adding to it, so it is also the list of
# what the binary leaves out. Vulcain is out because nothing here reaches a REST push gateway and it
# is AGPL-3.0; binary.yml asserts it is gone.
# Mercure, the other AGPL default, cannot be dropped -- frankenphp's own Caddy package imports it --
# so every FrankenPHP binary carries it and the licenses page names it. cbrotli is what
# `wikven serve` compresses with.
COPY caddy /go/wikven-caddy
ENV SPC_CMD_VAR_FRANKENPHP_XCADDY_MODULES="--with github.com/dunglas/caddy-cbrotli --with github.com/chaotic-ground/wikven/caddy=/go/wikven-caddy"
ENV PHP_VERSION=8.3
ENV PHP_EXTENSIONS="gd,intl,pdo_sqlite,sqlite3,mbstring,dom,xml,simplexml,xmlreader,xmlwriter,fileinfo,iconv,ctype,filter,tokenizer,phar,session,calendar,opcache,openssl,sodium,zlib,bcmath,exif"
ENV PHP_EXTENSION_LIBS="libpng,libjpeg,freetype,libwebp"
# static-php-cli resolves sources through api.github.com: 60 requests an hour per IP anonymously,
# shared with every runner on it. The token raises that to 1000.
RUN --mount=type=secret,id=github-token \
    GITHUB_TOKEN="$(cat /run/secrets/github-token 2>/dev/null || true)" \
    EMBED=dist/app ./build-static.sh

# Stage 3: just the binary, named per arch by build-static.sh, for `--target export -o`.
FROM scratch AS export
COPY --from=builder /go/src/app/dist/frankenphp-linux-* /wikven
