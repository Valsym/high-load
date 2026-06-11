# Быстрый старт

## Требования

- Docker Desktop (или Docker Engine + docker compose)
- Git
- Make (опционально)

## 1. Клонирование

```bash
git clone <repo-url> high-rps-pg
cd high-rps-pg
```

## 2. Настройка окружения

```bash
cp .env.example .env
```

Отредактируйте `.env` при необходимости. По умолчанию всё работает из коробки.

## 3. Запуск всех сервисов

```bash
docker compose up -d
```

Это поднимет:
- Laravel PHP-FPM на `:80` (через Nginx)
- PostgreSQL на `:5432`
- Redis (через Sail)
- ClickHouse на `:8123`
- Kafka + Zookeeper на `:9092` / `:2181`
- Kafka Connect (Debezium) на `:8083`
- Prometheus на `:9090`
- Grafana на `:3000`

## 4. Установка зависимостей

```bash
./vendor/bin/sail composer install
./vendor/bin/sail artisan key:generate
```

## 5. Миграции и сиды

```bash
./vendor/bin/sail artisan migrate --seed
```

## 6. Проверка

```bash
# API
curl -s http://localhost/api/products | head -c 200

# Метрики
curl -s http://localhost/prometheus

# Grafana: http://localhost:3000 (admin/admin)
```

## 7. CDC (опционально)

```bash
# Зарегистрировать Debezium коннектор
./vendor/bin/sail artisan debezium:register-connector

# Инициализировать таблицы ClickHouse
./vendor/bin/sail artisan clickhouse:init-tables

# Запустить консьюмер
./vendor/bin/sail artisan products:consume-debezium
```

## Полезные команды

```bash
# Логи
docker compose logs -f laravel.test

# Tinker
./vendor/bin/sail artisan tinker

# Очистка кэша метрик
./vendor/bin/sail artisan cache:clear --store=prometheus_metrics

# Пересборка образа
docker compose build laravel.test
```

## Документация

| Файл | Описание |
|---|---|
| [`README.md`](README.md) | Основная документация проекта |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Архитектура |
| [`CDC.md`](CDC.md) | Change Data Capture |
| [`DEPLOY.md`](DEPLOY.md) | Деплой на VPS |
| [`PROMETHEUS_GRAFANA.md`](PROMETHEUS_GRAFANA.md) | Мониторинг |