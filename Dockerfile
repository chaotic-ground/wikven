FROM mediawiki:1.45

# composer (and unzip, which composer uses to extract dist archives) so that
# third-party extensions/skins declared in .wikven.yaml can be installed at build
# time. git/tar/gzip are already in the base image for the git and tarball methods.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*

COPY ./ /var/www/html/extensions/Wikven
COPY WikvenSettings.php /var/www/html/

COPY run /usr/local/bin/
CMD ["/usr/local/bin/run"]
