# Отказоустойчивость: PostgreSQL Replication + Redis Sentinel

## Содержание

1. [Архитектура](#архитектура)
2. [PostgreSQL Streaming Replication](#postgresql-streaming-replication)
   - [Конфигурация Primary](#конфигурация-primary)
   - [Конфигурация Replica](#конфигурация-replica)
   - [Инициализация реплики](#инициализация-реплики)
   - [Проверка репликации](#проверка-репликации)
3. [Redis Sentinel](#redis-sentinel)
   - [Конфигурация Sentinel](#конфигурация-sentinel)
   - [Конфигурация Laravel](#конфигурация-laravel)
   - [Проверка Sentinel](#проверка-sentinel)
4. [Тестирование failover-сценариев](#тестирование-failover-сценариев)
   - [Сценарий 1: Отказ Redis master](#сценарий-1-отказ-redis-master)
   - [Сценарий 2: Отказ PostgreSQL primary](#сценарий-2-отказ-postgresql-primary)
   - [Сценарий 3: Восстановление после отказа](#сценарий-3-восстановление-после-отказа)
5. [Docker Compose конфигурация](#docker-compose-конфигурация)
6. [Полезные команды](#полезные-команды)

---

## Архитектура

```
┌─────────────────────────────────────────────────────────────┐
│                      Docker Network (sail)                   │
│                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌────────────────┐ │
│  │  PostgreSQL   │    │  PostgreSQL  │    │     Redis      │ │
│  │   Primary     │◄──►│   Replica    │    │     Master     │ │
│  │  :5432        │    │  :5433       │    │    :6379       │ │
│  │  (write/read) │    │  (read-only) │    │  (write/read)  │ │
│  └──────┬───────┘    └──────────────┘    └───────┬────────┘ │
│         │                                        │          │
│         │ streaming replication (WAL)            │ monitor   │
│         │                                        ▼          │
│         │                              ┌────────────────┐   │
│         │                              │ Redis Sentinel │   │
│         │                              │  3 узла        │   │
│         │                              │ :26379-26381   │   │
│         │                              │ кворум: 2      │   │
│         │                              └────────────────┘   │
│         │                                        │          │
│         ▼                                        ▼          │
│  ┌──────────────────────────────────────────────────────┐   │
│  │              Laravel Octane (laravel.test)            │   │
│  │  DB_HOST=pgsql-primary → primary для записи/чтения   │   │
│  │  Redis → через Sentinel (автоматический failover)    │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

---

## PostgreSQL Streaming Replication

### Конфигурация Primary

Файл: [`docker/postgres/postgresql-primary.conf`](docker/postgres/postgresql-primary.conf)

Ключевые параметры:

```ini
# Включаем репликацию
wal_level = replica
max_wal_senders = 3          # максимум WAL-отправителей (реплик)
wal_keep_size = 256          # MB WAL-сегментов для отстающих реплик
max_replication_slots = 3    # слоты репликации

# Настройки primary
listen_addresses = '*'
hot_standby = on             # реплика может отвечать на read-only запросы
```

**Что можно менять:**
- `max_wal_senders` — увеличить, если будет больше реплик
- `wal_keep_size` — увеличить, если реплика часто отстаёт
- `max_replication_slots` — должен быть >= количеству реплик

### Конфигурация Replica

Файл: [`docker/postgres/postgresql-replica.conf`](docker/postgres/postgresql-replica.conf)

```ini
# Режим реплики
hot_standby = on             # разрешаем read-only запросы
hot_standby_feedback = on    # предотвращает конфликты VACUUM на primary

# Подключение к primary (переопределяется в entrypoint)
primary_conninfo = 'host=pgsql-primary port=5432 user=sail password=password'
primary_slot_name = 'replica_1_slot'
```

**Что можно менять:**
- `primary_conninfo` — если меняются креды или хост primary
- `primary_slot_name` — имя слота репликации (должен совпадать со слотом на primary)

### Инициализация реплики

Файл: [`docker/postgres/replica-entrypoint.sh`](docker/postgres/replica-entrypoint.sh)

Процесс:
1. Ждём, пока primary станет доступен
2. Выполняем `pg_basebackup` — копируем данные с primary
3. Создаём `standby.signal` — переводим PostgreSQL в режим реплики
4. Запускаем PostgreSQL

```bash
# Ручная инициализация (если нужно пересоздать реплику):
docker compose exec pgsql-replica bash -c '
    rm -rf /var/lib/postgresql/data/*
    pg_basebackup -h pgsql-primary -D /var/lib/postgresql/data -U sail -v -P --slot=replica_1_slot
    touch /var/lib/postgresql/data/standby.signal
'
```

### Проверка репликации

```bash
# На primary — список слотов репликации
docker compose exec pgsql-primary psql -U sail -d laravel -c "SELECT * FROM pg_replication_slots;"

# На primary — статус WAL-отправителей
docker compose exec pgsql-primary psql -U sail -d laravel -c "SELECT * FROM pg_stat_replication;"

# На replica — статус применения WAL
docker compose exec pgsql-replica psql -U sail -d laravel -c "SELECT * FROM pg_stat_wal_receiver;"

# Проверка, что replica в hot_standby режиме
docker compose exec pgsql-replica psql -U sail -d laravel -c "SELECT pg_is_in_recovery();"
# Должно вернуть: t (true) — реплика читает, но не пишет
```

**Ожидаемый вывод `pg_stat_replication` на primary:**
```
  pid  | usesysid | usename | application_name |  state  | sync_state | slot_name
-------+----------+---------+------------------+---------+------------+-----------
 12345 |    16385 | sail    | walreceiver      | streaming | async     | replica_1_slot
```

---

## Redis Sentinel

### Конфигурация Sentinel

Файлы: [`docker/redis/sentinel1.conf`](docker/redis/sentinel1.conf), [`sentinel2.conf`](docker/redis/sentinel2.conf), [`sentinel3.conf`](docker/redis/sentinel3.conf)

Все три конфига идентичны, кроме имени файла:

```conf
port 26379
sentinel monitor mymaster redis 6379 2
sentinel down-after-milliseconds mymaster 5000
sentinel failover-timeout mymaster 10000
sentinel parallel-syncs mymaster 1
sentinel resolve-hostnames yes
sentinel announce-hostnames yes
```

**Параметры и их настройка:**

| Параметр | Значение | Что меняет |
|----------|----------|------------|
| `sentinel monitor mymaster redis 6379 2` | `redis` — хост мастера, `6379` — порт, `2` — кворум | Кворум = сколько сентинелов должно согласиться, что мастер недоступен. Для 3 сентинелов оптимально 2. |
| `down-after-milliseconds` | 5000 ms | Через сколько миллисекунд без ответа сентинел считает мастер недоступным |
| `failover-timeout` | 10000 ms | Таймаут на выполнение failover |
| `parallel-syncs` | 1 | Сколько реплик одновременно синхронизируются с новым мастером |
| `resolve-hostnames` | yes | Разрешать hostname через DNS (нужно в Docker) |
| `announce-hostnames` | yes | Анонсировать hostname вместо IP |

**Как менять:**
- Если Redis master называется иначе — поменять `mymaster` и хост во всех трёх конфигах
- Если нужно ускорить failover — уменьшить `down-after-milliseconds` (но не ниже 1000 ms, иначе ложные срабатывания)
- Если добавить Redis replica — увеличить `parallel-syncs`

### Конфигурация Laravel

Файл: [`config/database.php`](config/database.php)

**Обычное подключение (без Sentinel):**
```php
'default' => [
    'host' => env('REDIS_HOST', 'redis'),
    'port' => env('REDIS_PORT', '6379'),
    // ...
],
```

**Sentinel-подключение (для failover):**
```php
'sentinel' => [
    'host' => env('REDIS_SENTINEL_HOST', 'redis-sentinel-1'),
    'port' => env('REDIS_SENTINEL_PORT', '26379'),
    // ...
],
```

**Переключение между режимами:**
- В `.env` указать `REDIS_HOST=redis` для прямого подключения
- Или использовать `redis.sentinel` конфиг для подключения через Sentinel

### Проверка Sentinel

```bash
# Информация о мастере
docker compose exec redis-sentinel-1 redis-cli -p 26379 sentinel master mymaster

# Список всех сентинелов в кластере
docker compose exec redis-sentinel-1 redis-cli -p 26379 sentinel sentinels mymaster

# Текущее состояние
docker compose exec redis-sentinel-1 redis-cli -p 26379 sentinel get-master-addr-by-name mymaster

# Логи сентинела
docker compose logs redis-sentinel-1
```

**Ожидаемый вывод `sentinel master mymaster`:**
```
name: mymaster
ip: redis
port: 6379
flags: master
num-slaves: 0
num-other-sentinels: 2
quorum: 2
```

---

## Тестирование failover-сценариев

### Сценарий 1: Отказ Redis master

**Цель:** Проверить, что Sentinel автоматически переключит мастер при отказе.

```bash
# 1. Останавливаем Redis master
docker compose stop redis

# 2. Ждём ~10 секунд (down-after-milliseconds 5000ms + failover-timeout 10000ms)
sleep 10

# 3. Проверяем, что Sentinel определил новый мастер
docker compose exec redis-sentinel-1 redis-cli -p 26379 sentinel get-master-addr-by-name mymaster
# Ожидаем: "0" (нет мастера, т.к. нет реплик для промоушна)

# 4. Смотрим логи сентинелов
docker compose logs redis-sentinel-1 --tail 20

# 5. Возвращаем мастер
docker compose start redis
sleep 5

# 6. Проверяем, что мастер снова доступен
docker compose exec redis-sentinel-1 redis-cli -p 26379 sentinel master mymaster | grep -E '^(flags|ip)'
# Ожидаем: flags: master, ip: redis
```

**Важно:** В текущей конфигурации у Redis нет реплик, поэтому failover не приведёт к промоушну — мастер просто станет недоступен, пока не вернётся. Для полноценного failover нужно добавить Redis replica.

### Сценарий 2: Отказ PostgreSQL primary

**Цель:** Проверить, что replica содержит актуальные данные.

```bash
# 1. Пишем данные в primary
docker compose exec laravel.test php artisan tinker --execute="\App\Models\Product::factory()->create(['name' => 'failover-test'])"

# 2. Проверяем, что данные есть на replica
docker compose exec pgsql-replica psql -U sail -d laravel -c "SELECT name FROM products WHERE name = 'failover-test';"
# Ожидаем: 1 запись

# 3. Останавливаем primary
docker compose stop pgsql-primary

# 4. Продвигаем replica до primary (вручную)
docker compose exec pgsql-replica bash -c "pg_ctl promote -D /var/lib/postgresql/data"

# 5. Проверяем, что replica теперь принимает запись
docker compose exec pgsql-replica psql -U sail -d laravel -c "INSERT INTO products (name, code, price, section_id) VALUES ('after-failover', 'FAIL-TEST', 100, 1);"
# Ожидаем: INSERT 0 1

# 6. Возвращаем primary (потребуется пересоздать реплику)
docker compose start pgsql-primary
# После восстановления нужно переинициализировать старый primary как новую реплику
```

### Сценарий 3: Восстановление после отказа

**Полный цикл восстановления PostgreSQL:**

```bash
# 1. Останавливаем всё
docker compose down

# 2. Удаляем volume реплики (чтобы пересоздать с нуля)
docker volume rm high-rps-pg_sail-pgsql-replica

# 3. Запускаем заново
docker compose up -d

# 4. Проверяем репликацию
docker compose exec pgsql-primary psql -U sail -d laravel -c "SELECT * FROM pg_stat_replication;"
```

---

## Docker Compose конфигурация

Файл: [`compose.yaml`](compose.yaml)

**Сервисы отказоустойчивости:**

| Сервис | Зависимости | Healthcheck | Назначение |
|--------|-------------|-------------|------------|
| `pgsql-primary` | — | `pg_isready` | Основная БД (запись/чтение) |
| `pgsql-replica` | `pgsql-primary: healthy` | `pg_isready` | Реплика (read-only) |
| `redis` | — | `redis-cli ping` | Кэш (мастер) |
| `redis-sentinel-1` | `redis: healthy` | — | Мониторинг Redis |
| `redis-sentinel-2` | `redis: healthy` | — | Мониторинг Redis |
| `redis-sentinel-3` | `redis: healthy` | — | Мониторинг Redis |
| `laravel.test` | `pgsql-primary: healthy`, `redis: started` | — | Приложение |

**Важные моменты:**
- `laravel.test` зависит от `pgsql-primary` (ждёт healthcheck), но не от replica — приложение работает и без неё
- Sentinels запускаются после Redis, но не блокируют запуск приложения
- Все сервисы в одной сети `sail` — обращаются по именам сервисов (DNS)

---

## Полезные команды

```bash
# PostgreSQL
docker compose exec pgsql-primary psql -U sail -d laravel -c "\dt"           # список таблиц
docker compose exec pgsql-primary psql -U sail -d laravel -c "\du"           # список пользователей
docker compose exec pgsql-primary psql -U sail -d laravel -c "\l"            # список БД
docker compose exec pgsql-primary psql -U sail -d laravel -c "SHOW wal_level;"  # уровень WAL

# Redis
docker compose exec redis redis-cli ping                                      # проверка доступности
docker compose exec redis redis-cli info replication                          # статус репликации
docker compose exec redis redis-cli info sentinel                             # статус сентинела (на мастере)

# Логи
docker compose logs pgsql-primary --tail 50                                   # логи primary
docker compose logs pgsql-replica --tail 50                                   # логи replica
docker compose logs redis-sentinel-1 --tail 50                                # логи sentinel-1

# Перезапуск конкретного сервиса
docker compose restart pgsql-replica
docker compose restart redis-sentinel-1

# Полный перезапуск стека
docker compose down && docker compose up -d