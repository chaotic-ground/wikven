# Base images are digest-pinned for reproducible builds; Dependabot keeps them current.
FROM composer:2@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS composer

FROM mediawiki:1.45@sha256:6dc859706b561acf90a0f92786280f6e461946c4cc2fa8ea5c74be6c27251d2c

# composer + unzip to install third-party extensions/skins at build time (git/tar/gzip present).
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*

COPY ./ /var/www/html/extensions/Wikven
COPY includes/WikvenSettings.php /var/www/html/

# SifterSearch (client-side Pagefind search) ships built in. Its release tarball carries the
# per-arch Pagefind binary a git clone omits, so fetch the one matching this build's architecture.
ARG TARGETARCH
ARG SIFTERSEARCH_VERSION=v0.6.0
RUN arch="$TARGETARCH" \
 && if [ "$arch" = amd64 ]; then arch=x64; fi \
 && curl -fsSL "https://github.com/chaotic-ground/SifterSearch/releases/download/${SIFTERSEARCH_VERSION}/SifterSearch-linux-${arch}.tar.gz" \
  | tar -xz -C /var/www/html/extensions/

COPY bin/entrypoint /usr/local/bin/entrypoint
# Entry point is wikven's run script; the arg is the subcommand (default "build"; "serve" previews).
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["build"]
