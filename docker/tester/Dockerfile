# The tester: the games' PHPUnit tests in a sandbox (runner.php). PHP 8.3 as the engine in the browser.
FROM php:8.3-cli-alpine
ARG PHPUNIT=11.5.57
RUN apk add --no-cache su-exec procps \
 && wget -q -O /opt/phpunit.phar "https://phar.phpunit.de/phpunit-${PHPUNIT}.phar" \
 && chmod 644 /opt/phpunit.phar && php /opt/phpunit.phar --version
COPY runner.php /opt/runner.php
CMD ["php", "/opt/runner.php"]
