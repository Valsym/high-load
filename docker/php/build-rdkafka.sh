#!/bin/bash
set -e

# Скрипт сборки php-rdkafka внутри контейнера laravel.test
RDKAFKA_VERSION="6.0.5"
RDKAFKA_DIR="php-rdkafka-${RDKAFKA_VERSION}"

cd /tmp

# 1. Установка librdkafka (C-библиотека)
if ! dpkg -l | grep -q librdkafka-dev; then
    echo "Installing librdkafka-dev..."
    apt-get update && apt-get install -y --no-install-recommends librdkafka-dev
else
    echo "librdkafka-dev already installed"
fi

# 2. Скачивание исходников php-rdkafka
if [ ! -f "${RDKAFKA_DIR}.tar.gz" ]; then
    echo "Downloading php-rdkafka v${RDKAFKA_VERSION}..."
    curl -L -o "${RDKAFKA_DIR}.tar.gz" \
        "https://github.com/arnaud-lb/php-rdkafka/archive/refs/tags/${RDKAFKA_VERSION}.tar.gz"
fi

# 3. Распаковка
if [ ! -d "$RDKAFKA_DIR" ]; then
    echo "Extracting..."
    tar xzf "${RDKAFKA_DIR}.tar.gz"
fi

cd "$RDKAFKA_DIR"

# 4. Сборка
echo "Building..."
phpize
./configure --with-rdkafka
make -j$(nproc)
make install

# 5. Включение расширения
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
INI_DIR="/etc/php/${PHP_VERSION}/cli/conf.d"

if [ ! -d "$INI_DIR" ]; then
    INI_DIR="/etc/php/${PHP_VERSION}/mods-available"
    mkdir -p "$INI_DIR"
fi

echo "extension=rdkafka.so" > "${INI_DIR}/20-rdkafka.ini"

# 6. Проверка
echo ""
echo "Verifying..."
php -m | grep -i rdkafka && echo "rdkafka loaded successfully!" || echo "FAILED to load rdkafka"

# 7. Очистка
cd /tmp
rm -rf "$RDKAFKA_DIR" "${RDKAFKA_DIR}.tar.gz"
echo "Done"