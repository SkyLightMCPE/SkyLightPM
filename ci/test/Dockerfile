FROM php:7.0-zts-alpine

RUN set -xe \
	&& apk add --no-cache --virtual .php-rundeps yaml \
	&& apk add --no-cache --virtual .build-deps yaml-dev $PHPIZE_DEPS \
	&& docker-php-source extract \
	&& docker-php-ext-install -j $(getconf _NPROCESSORS_ONLN) sockets bcmath pcntl \
	&& pecl install channel://pecl.php.net/pthreads-3.1.6 channel://pecl.php.net/weakref-0.3.3 channel://pecl.php.net/yaml-2.0.0 \
	&& docker-php-ext-enable pthreads.so weakref.so yaml.so \
	&& echo "phar.readonly = off" > /usr/local/etc/php/conf.d/phar.ini \
    && echo "zend.assertions = -1" > /usr/local/etc/php/conf.d/assertions.ini \
	&& docker-php-source delete \
	&& apk del .build-deps