#!/bin/sh

PHP_BINARY="php"

echo -e "version\nms\nstop\n" | "$PHP_BINARY" -dphar.readonly=0 src/pocketmine/PocketMine.php --no-wizard --disable-ansi --disable-readline --debug.level=2
if ls plugins/GenisysPro/SkyLightPM.phar >/dev/null 2>&1; then
    echo Server phar created successfully.
else
    echo No phar created!
    exit 1
fi
