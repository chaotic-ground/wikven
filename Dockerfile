# Base images are digest-pinned for reproducible builds; Dependabot keeps the
# digests current (see .github/dependabot.yml).
FROM composer:2@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS composer

FROM mediawiki:1.45@sha256:6dc859706b561acf90a0f92786280f6e461946c4cc2fa8ea5c74be6c27251d2c

# composer (and unzip, which composer uses to extract dist archives) so that
# third-party extensions/skins declared in .wikven.yaml can be installed at build
# time. git/tar/gzip are already in the base image for the git and tarball methods.
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*

COPY ./ /var/www/html/extensions/Wikven
COPY includes/WikvenSettings.php /var/www/html/

COPY bin/run /usr/local/bin/run
# The entry point is wikven's run script; the command is the subcommand it
# dispatches on (default "build"), so `docker run ... <image> serve` previews.
ENTRYPOINT ["/usr/local/bin/run"]
CMD ["build"]
