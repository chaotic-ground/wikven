# Base images are digest-pinned for reproducible builds; Dependabot keeps them current.
FROM composer:2@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS composer

FROM mediawiki:1.45@sha256:7f9d5c2cc824096367f998e76a00e3f2195546b14fddb268e4218ab7a46c205d

# composer + unzip to install third-party extensions/skins at build time (git/tar/gzip present).
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*

COPY ./ /var/www/html/extensions/Wikven
COPY includes/WikvenSettings.php /var/www/html/

COPY bin/entrypoint /usr/local/bin/entrypoint
# Entry point is wikven's run script; the arg is the subcommand (default "build"; "serve" previews).
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["build"]
