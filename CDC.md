# Debezium CDC (Change Data Capture)

Автоматическое отслеживание изменений в PostgreSQL через logical replication.

## Архитектура

```
PostgreSQL (logical replication)
    │
    ▼
Debezium Connect (Kafka Connect)
    │
    ▼
Kafka topic: dbserver1.public.products
    │
    ▼
Laravel artisan-команда: products:consume-debezium
    │
    ▼
ClickHouse (product_changes + product_stats_by_section)
```

## Команды

### 1. Запустить контейнеры

```bash
./vendor/bin/sail up -d kafka-connect
```

### 2. Зарегистрировать Debezium коннектор

```bash
./vendor/bin/sail artisan debezium:register-connector
```

### 3. Проверить статус коннектора

```bash
./vendor/bin/sail artisan debezium:status
```

### 4. Запустить консьюмер Debezium-событий

```bash
./vendor/bin/sail artisan products:consume-debezium
```

### 5. Проверка CDC

После запуска консьюмера откройте второй терминал и выполните проверку:

```bash
# 5a. Вставить тестовую запись напрямую в PostgreSQL (через Tinker)
./vendor/bin/sail artisan tinker
DB::insert('INSERT INTO products (name, code, section_id, total, price, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())', ['Debezium Test', 'DBCDC-TEST', 1, 20, 1500.00]);
# Нажмите Ctrl+D для выхода из Tinker

# 5b. Проверить, что запись появилась в ClickHouse (лог событий)
curl -u default:clickhouse 'http://localhost:8123/?database=default' \
  --data 'SELECT * FROM default.product_changes ORDER BY created_at DESC LIMIT 5'

# 5c. Проверить агрегированную статистику по секциям
curl -u default:clickhouse 'http://localhost:8123/?database=default' \
  --data 'SELECT * FROM default.product_stats_by_section'
```

> **Ожидаемый результат**: в терминале консьюмера появится строка `Processed: created product #<id> (Debezium Test)`, а запросы к ClickHouse покажут новые данные.


### 6. (альтернатива) Запустить старый консьюмер для ручных сообщений

```bash
./vendor/bin/sail artisan products:consume-kafka
```

## Формат сообщений Debezium

Debezium публикует сообщения в формате:

```json
{
    "payload": {
        "op": "c",        // c=create, u=update, d=delete, r=snapshot
        "before": null,   // данные до изменения (для delete/update)
        "after": {        // данные после изменения (для create/update)
            "id": 1,
            "name": "Товар",
            "code": "ABC",
            "price": 100.50,
            "total": 10,
            "section_id": 1,
            "created_at": "2026-06-08T00:00:00Z",
            "updated_at": "2026-06-08T00:00:00Z"
        },
        "source": {
            "db": "high_rps",
            "table": "products"
        }
    }
}
```

Консьюмер [`ConsumeDebeziumChanges`](app/Console/Commands/ConsumeDebeziumChanges.php) парсит этот формат и пишет в ClickHouse.

## Конфигурация коннектора

Файл: [`docker/kafka-connect/debezium-product-connector.json`](docker/kafka-connect/debezium-product-connector.json)

Ключевые параметры:
- `plugin.name: pgoutput` — использует встроенный logical replication в PostgreSQL 18
- `publication.autocreate.mode: filtered` — автоматически создаёт публикацию для таблицы products
- `slot.name: debezium_slot_products` — имя слота репликации
- `snapshot.mode: initial` — при первом запуске делает snapshot всех данных, затем переключается на CDC
- `decimal.handling.mode: double` — decimal поля конвертируются в double (для ClickHouse)

## Отладка

### Логи Kafka Connect

```bash
docker compose logs kafka-connect
```

### Проверить топики в Kafka

```bash
docker compose exec kafka kafka-topics --bootstrap-server kafka:9092 --list
```

### Прочитать сообщения из топика Debezium

```bash
docker compose exec kafka kafka-console-consumer \
    --bootstrap-server kafka:9092 \
    --topic dbserver1.public.products \
    --from-beginning \
    --max-messages 5
```

### REST API Kafka Connect

```bash
# Список коннекторов
curl http://localhost:8083/connectors

# Статус коннектора
curl http://localhost:8083/connectors/debezium-product-connector/status

# Конфиг коннектора
curl http://localhost:8083/connectors/debezium-product-connector/config

# Удалить коннектор
curl -X DELETE http://localhost:8083/connectors/debezium-product-connector
```

## ClickHouse: таблицы и представления

### `product_changes` — лог событий

```sql
CREATE TABLE default.product_changes (
    event_id UUID DEFAULT generateUUIDv4(),
    product_id UInt64,
    name String,
    code String,
    price Float64,
    total UInt32,
    section_id UInt64,
    section_name String,
    action String,          -- created / updated / deleted
    created_at DateTime DEFAULT now()
) ENGINE = MergeTree()
ORDER BY (created_at, product_id)
TTL created_at + INTERVAL 30 DAY   -- автоматическое удаление записей старше 30 дней
```

TTL гарантирует, что объём таблицы не будет расти бесконечно. ClickHouse удаляет устаревшие партии при мерже фоновых частей.

### `product_stats_by_section` — агрегаты по секциям

```sql
CREATE TABLE default.product_stats_by_section (
    section_id UInt64,
    section_name String,
    products_count UInt64,
    avg_price Float64,
    min_price Float64,
    max_price Float64,
    total_stock UInt64,
    updated_at DateTime DEFAULT now()
) ENGINE = ReplacingMergeTree(updated_at)
ORDER BY (section_id)
```

Обновляется консьюмерами при каждом изменении товара. Дубли устраняются по `section_id` (остаётся запись с максимальным `updated_at`).

### `daily_section_stats` — материализованное представление (SummingMergeTree)

```sql
CREATE MATERIALIZED VIEW default.daily_section_stats
ENGINE = SummingMergeTree()
ORDER BY (date, section_id)
POPULATE
AS SELECT
    toDate(created_at) AS date,
    section_id,
    section_name,
    count() AS changes_count,
    avg(price) AS avg_price,
    min(price) AS min_price,
    max(price) AS max_price,
    sum(total) AS total_stock
FROM default.product_changes
GROUP BY date, section_id, section_name
```

Автоматически агрегирует данные из `product_changes` по дням и секциям. Позволяет быстро строить графики в Grafana без сканирования всей таблицы.

**Пример запроса:**
```sql
SELECT date, section_name, changes_count, avg_price
FROM default.daily_section_stats
WHERE date >= today() - 7
ORDER BY date DESC, changes_count DESC
```

### `product_latest_state` — материализованное представление (ReplacingMergeTree)

```sql
CREATE MATERIALIZED VIEW default.product_latest_state
ENGINE = ReplacingMergeTree(last_updated)
ORDER BY (product_id)
POPULATE
AS SELECT
    product_id,
    argMax(name, created_at) AS name,
    argMax(code, created_at) AS code,
    argMax(price, created_at) AS price,
    argMax(total, created_at) AS total,
    argMax(section_id, created_at) AS section_id,
    argMax(section_name, created_at) AS section_name,
    max(created_at) AS last_updated
FROM default.product_changes
GROUP BY product_id
```

Хранит актуальное состояние каждого товара на основе последнего события в `product_changes`. Полезно для быстрых lookup-запросов без обращения к PostgreSQL.

**Пример запроса:**
```sql
SELECT product_id, name, price, total, last_updated
FROM default.product_latest_state
WHERE price > 1000
ORDER BY price DESC
LIMIT 10
```

## Важные замечания

1. **section_name** — Debezium отслеживает только таблицу `products`, поэтому `section_name` не приходит в CDC. В ClickHouse он будет пустым. Если нужно — можно либо:
   - Добавить таблицу `sections` в коннектор (`table.include.list: public.products,public.sections`)
   - Либо джойнить в ClickHouse через `ReplicatedJoin` или словарь
   - Либо заполнять через отдельный ETL-процесс

2. **Удаления** — Debezium шлёт `op: d` с полем `before`, содержащим данные до удаления. Консьюмер корректно обрабатывает этот случай.

3. **Snapshot** — При первом запуске Debezium делает snapshot всей таблицы. Сообщения помечаются как `op: r` (read) и обрабатываются как `created`.

4. **Ручное vs автоматическое** — Оба подхода работают параллельно:
   - Ручной: [`ProductController`](app/Http/Controllers/Api/ProductController.php) → топик `product_changes` → [`ConsumeProductChanges`](app/Console/Commands/ConsumeProductChanges.php)
   - Автоматический: Debezium → топик `dbserver1.public.products` → [`ConsumeDebeziumChanges`](app/Console/Commands/ConsumeDebeziumChanges.php)