FROM mediawiki:1.37

COPY ./ /var/www/html/extensions/Wikven
COPY WikvenSettings.php /var/www/html/

COPY run /usr/local/bin/
CMD ["/usr/local/bin/run"]
