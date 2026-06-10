FROM sail-8.5/app

# Установка librdkafka (C-библиотека), если ещё не установлена
RUN if ! dpkg -l | grep -q librdkafka-dev; then \
        apt-get update \
        && apt-get install -y --no-install-recommends librdkafka-dev \
        && apt-get clean \
        && rm -rf /var/lib/apt/lists/*; \
    fi

# Установка php-rdkafka через pecl, если ещё не установлен
RUN if ! php -m | grep -qi rdkafka; then \
        pecl install rdkafka \
        && docker-php-ext-enable rdkafka; \
    fi

# Проверка установки
RUN php -m | grep -i rdkafka