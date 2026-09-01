# Build a standalone FrankenPHP binary that embeds MediaWiki + Wikven, so the
# static-site build runs without Docker. Built in CI per target architecture and
# attached to the GitHub release; see .github/workflows/binary.yml.
#
# Usage (the wikven image must be built first, e.g. `docker build -t wikven .`):
#   docker build -f binary.Dockerfile --target export -o type=local,dest=out .
#   # -> out/wikven, a single static executable. Run it with:
#   #    WIKVEN_WORKDIR=. ./wikven build
#   # On a GitHub API rate limit, add: --secret id=github-token,env=GITHUB_TOKEN

# Stage 1: the app tree to embed = the wikven image + the binary entry point,
# minus what the build never needs (keeping all locales, so the binary is not
# English-only).
FROM wikven AS app
# The binary's own entry points, beside the app tree they drive: one per subcommand, plus the
# prelude they share.
COPY bin/build.php bin/prepare.php bin/translate.php /var/www/html/
RUN find /var/www/html -type d -name tests -prune -exec rm -rf {} + \
 && rm -rf \
      /var/www/html/HISTORY \
      /var/www/html/UPGRADE \
      /var/www/html/RELEASE-NOTES-* \
      /var/www/html/cache/* \
      /var/www/html/images/*

# Stage 2: compile a static PHP + FrankenPHP and embed the app. curl is omitted:
# its HTTP/3 (ngtcp2/nghttp3) static libs fail to link, and it is unneeded
# (MediaWiki/Guzzle uses PHP stream wrappers over openssl).
# Digest-pinned, version-tagged so Dependabot's bumps read as versions. The pin fixes the toolchain
# but not the build: build-static.sh fetches a nightly static-php-cli, which picks the library versions.
FROM dunglas/frankenphp:static-builder-musl-1.12.7@sha256:a94970c674975833dd09ba40dd1e8b9b7ccdac562f493722c1e9f5f6d81c5ae7 AS builder
WORKDIR /go/src/app
COPY --from=app /var/www/html ./dist/app
# A small Caddy module registers the `build`, `serve` and `translate` subcommands,
# so the binary can be run as `./wikven build` instead of `./wikven php-cli
# build.php`.
#
# Setting this replaces FrankenPHP's default module list rather than adding to it, so the line is
# also the list of what the binary does not carry. Vulcain, a REST push gateway, is left out of it:
# nothing in a bake or in `wikven serve` reaches one, and it is AGPL-3.0, so linking it meant
# redistributing AGPL code for a capability nobody using this binary can call. binary.yml asserts
# it is gone from the build, because a list like this is easy to restore by copying FrankenPHP's
# defaults back over it.
#
# Mercure, the other AGPL-3.0 default, is not attempted and cannot be dropped this way: frankenphp's
# own Caddy package imports it (`go mod why` gives frankenphp/caddy -> mercure), so it is linked
# whatever this list says and every FrankenPHP binary carries it. The licenses page names it.
#
# cbrotli stays: it is what `wikven serve` compresses with.
COPY caddy /go/wikven-caddy
ENV SPC_CMD_VAR_FRANKENPHP_XCADDY_MODULES="--with github.com/dunglas/caddy-cbrotli --with github.com/chaotic-ground/wikven/caddy=/go/wikven-caddy"
ENV PHP_VERSION=8.3
ENV PHP_EXTENSIONS="gd,intl,pdo_sqlite,sqlite3,mbstring,dom,xml,simplexml,xmlreader,xmlwriter,fileinfo,iconv,ctype,filter,tokenizer,phar,session,calendar,opcache,openssl,sodium,zlib,bcmath,exif"
ENV PHP_EXTENSION_LIBS="libpng,libjpeg,freetype,libwebp"
# static-php-cli resolves most sources through api.github.com, which is 60 requests/hour per IP when
# anonymous and shared with every other runner on that IP. The token raises it to 1000/hour per repo.
RUN --mount=type=secret,id=github-token \
    GITHUB_TOKEN="$(cat /run/secrets/github-token 2>/dev/null || true)" \
    EMBED=dist/app ./build-static.sh

# Stage 3: expose just the binary (named per arch by build-static.sh) for
# `docker build --target export -o`.
FROM scratch AS export
COPY --from=builder /go/src/app/dist/frankenphp-linux-* /wikven
