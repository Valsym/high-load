# Архитектура проекта

## Общая схема

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Docker Compose (Sail)                        │
│                                                                     │
│  ┌──────────┐   ┌──────────┐   ┌──────────┐   ┌───────────────┐   │
│  │  Nginx   │──▶│ Laravel  │──▶│PostgreSQL│   │   ClickHouse  │   │
│  │  :80     │   │ PHP-FPM  │   │  :5432   │   │   :8123       │   │
│  └──────────┘   │ :9000    │   └──────────┘   └───────────────┘   │
│                 │          │         │                               │
│                 └──────────┘         │                               │
│                      │              │                               │
│                      │              ▼                               │
│                      │     ┌──────────────┐                        │
│                      │     │   Debezium   │                        │
│                      │     │  Connect     │                        │
│                      │     │  :8083       │                        │
│                      │     └──────┬───────┘                        │
│                      │            │                                 │
│                      │            ▼                                 │
│                      │     ┌──────────────┐                        │
│                      │     │    Kafka     │                        │
│                      │     │   :9092      │                        │
│                      │     └──────┬───────┘                        │
│                      │            │                                 │
│                      │            ▼                                 │
│                      │     ┌──────────────┐                        │
│                      └────▶│  Consumer    │────────────────────────▶│
│                            │  (Artisan)   │     ClickHouse          │
│                            └──────────────┘                        │
│                                                                     │
│  ┌──────────┐              ┌──────────┐                            │
│  │Prometheus│◀─────────────│ Laravel  │                            │
│  │  :9090   │   scrape     │ /prometheus                            │
│  └────┬─────┘              └──────────┘                            │
│       │                                                            │
│       ▼                                                            │
│  ┌──────────┐                                                      │
│  │  Grafana │                                                      │
│  │  :3000   │                                                      │
│  └──────────┘                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

## Компоненты

### Laravel PHP-FPM
- **Порт**: 9000 (внутренний), 80 (внешний через Nginx)
- **pm.max_children**: до 8
- **pm.max_requests**: 500 (защита от утечек памяти)
- **Кэш**: Redis (консистентность между репликами)

### PostgreSQL 18
- Logical replication включён (`wal_level = logical`)
- Индексы: `code`, `section_id`, `price`, составной (`id`, `name`, `code`, `price`, `section_id`)
- Слот репликации: `debezium_slot_products`

### Kafka + Zookeeper
- **Версия**: Confluent 7.6
- **Топик CDC**: `dbserver1.public.products`
- **Топик ручных сообщений**: `product_changes`

### Debezium Connect
- **Версия**: 2.5.4.Final
- **Plugin**: pgoutput (встроенный logical replication PostgreSQL)
- **Snapshot mode**: initial

### ClickHouse
- **Таблицы**: `product_changes` (MergeTree), `product_stats_by_section` (ReplacingMergeTree)
- **Порты**: 8123 (HTTP), 9000 (TCP)

### Prometheus + Grafana
- **Scrape interval**: 10s
- **Метрики**: RPS, latency, память PHP, размер таблиц ClickHouse
- **Grafana**: дашборд "High-Load Laravel" с авто-провижинингом

## Потоки данных

### HTTP-запрос (основной)
```
Client → Nginx:80 → Laravel PHP-FPM:9000 → PostgreSQL
                                        → Redis (кэш)
```

### CDC (Change Data Capture)
```
PostgreSQL (WAL) → Debezium Connect → Kafka Topic → Artisan Consumer → ClickHouse
```

### Метрики
```
Laravel Middleware → Cache (file) → /prometheus endpoint → Prometheus → Grafana