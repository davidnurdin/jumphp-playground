# The tester: the games' PHPUnit tests in a sandbox (runner.php). PHP 8.3 with the SDL extensions of JumPHP's
# desktop runtime (the same forks and commits: JumPHP docker/desktop), so that the game tests (GameTestCase)
# play the game, without a window (SDL dummy drivers), and are filmed.
FROM php:8.3-cli-bookworm
ARG PHPUNIT=11.5.57
ARG PHP_SDL_REF=89855c8f4f63b77f9a548007b9c4afe366785b4b
ARG SDL_IMAGE_REF=fd798eb2c52b7be5874a30334d1d255436aa686e
ARG SDL_MIXER_REF=f75a31826f4484bb4af31c1a1a4cdf1463aae8b2
ARG SDL_TTF_REF=d2c634c509aa0b14168f55128435d9519d34d0e3

RUN apt-get update && apt-get install -y --no-install-recommends \
        git pkg-config procps \
        libsdl2-dev libsdl2-image-dev libsdl2-ttf-dev libsdl2-mixer-dev libpng-dev \
    && docker-php-ext-configure gd && docker-php-ext-install -j"$(nproc)" gd bcmath \
    && set -eux; \
    fetch() { mkdir -p "$3" && cd "$3" && git init -q && git remote add origin "https://github.com/$1.git" \
              && git fetch -q --depth 1 origin "$2" && git checkout -q FETCH_HEAD; }; \
    fetch davidnurdin/php-sdl   "$PHP_SDL_REF"   /usr/src/ext-sdl; \
    fetch kea/php-sdl-image     "$SDL_IMAGE_REF" /usr/src/ext-sdl_image; \
    fetch kea/php-sdl-mixer     "$SDL_MIXER_REF" /usr/src/ext-sdl_mixer; \
    fetch kea/php-sdl-ttf       "$SDL_TTF_REF"   /usr/src/ext-sdl_ttf; \
    build() { cd "$1" && phpize && ./configure $2 && make -j"$(nproc)" && make install; }; \
    build /usr/src/ext-sdl       --with-sdl; \
    build /usr/src/ext-sdl_image --with-sdl_image; \
    build /usr/src/ext-sdl_mixer --with-sdl_mixer; \
    build /usr/src/ext-sdl_ttf   --enable-sdl_ttf; \
    docker-php-ext-enable sdl sdl_image sdl_mixer sdl_ttf; \
    rm -rf /usr/src/ext-* /var/lib/apt/lists/*; \
    php -m | grep -iE '^(sdl|sdl_image|sdl_mixer|sdl_ttf|gd)$'; \
    curl -fsSL -o /opt/phpunit.phar "https://phar.phpunit.de/phpunit-${PHPUNIT}.phar" \
    && chmod 644 /opt/phpunit.phar && php /opt/phpunit.phar --version
COPY runner.php /opt/runner.php
CMD ["php", "/opt/runner.php"]
