# Base images are digest-pinned for reproducible builds; Dependabot keeps the
# digests current (see .github/dependabot.yml).
FROM composer:2@sha256:e8fdff913656c23e90ebbe0d7c55ab078c2aefdbb53ff79a73af5cc0921d5b81 AS composer

FROM mediawiki:1.45@sha256:7f9d5c2cc824096367f998e76a00e3f2195546b14fddb268e4218ab7a46c205d

# composer (and unzip, which composer uses to extract dist archives) so that
# third-party extensions/skins declared in .wikven.yaml can be installed at build
# time. git/tar/gzip are already in the base image for the git and tarball methods.
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*

COPY ./ /var/www/html/extensions/Wikven
COPY WikvenSettings.php /var/www/html/

COPY run /usr/local/bin/
CMD ["/usr/local/bin/run"]
