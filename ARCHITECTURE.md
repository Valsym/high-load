# Архитектура проекта

## Общая схема

```
┌─────────────────────────────────────────────────────────────────────────────────────┐
│                          Docker Compose (Sail)                                      │
│                                                                                     │
│  ┌──────────┐   ┌──────────┐   ┌──────────────────┐   ┌───────────────┐           │
│  │  Nginx   │──▶│ Laravel  │──▶│  PostgreSQL      │   │   ClickHouse  │           │
│  │  :80     │   │ PHP-FPM  │   │  Primary :5432   │   │   :8123       │           │
│  └──────────┘   │ :9000    │   └────────┬─────────┘   └───────────────┘           │
│                 │          │            │                                           │
│                 └──────────┘            │ streaming replication                    │
│                      │                 │                                           │
│                      │                 ▼                                           │
│                      │      ┌──────────────────┐                                  │
│                      │      │  PostgreSQL       │                                  │
│                      │      │  Replica :5433    │                                  │
│                      │      └──────────────────┘                                  │
│                      │                                                            │
│                      │              ┌─────────────────┐                           │
│                      │              │  Redis Master    │                           │
│                      │              │  :6379           │                           │
│                      │              └────────┬────────┘                           │
│                      │              ┌────────┴────────┐                           │
│                      │              │                 │                           │
│                      │         ┌────▼────┐    ┌───────▼───┐                      │
│                      │         │Sentinel1│    │ Sentinel2 │  Sentinel3            │
│                      │         │ :26379  │    │ :26380    │  :26381               │
│                      │         └─────────┘    └───────────┘                      │
│                      │                                                            │
│                      │              ▼                                              │
│                      │     ┌──────────────┐                                      │
│                      │     │   Debezium   │                                      │
│                      │     │  Connect     │                                      │
│                      │     │  :8083       │                                      │
│                      │     └──────┬───────┘                                      │
│                      │            │                                               │
│                      │            ▼                                               │
│                      │     ┌──────────────┐                                      │
│                      │     │    Kafka     │                                      │
│                      │     │   :9092      │                                      │
│                      │     └──────┬───────┘                                      │
│                      │            │                                               │
│                      │            ▼                                               │
│                      │     ┌──────────────┐                                      │
│                      │     │  Consumer    │                                      │
│                      └────▶│  (Artisan)   │──────────────────────────────────────▶│
│                            └──────────────┘     ClickHouse                        │
│                                                                                   │
│  ┌──────────┐              ┌──────────┐                                          │
│  │Prometheus│◀─────────────│ Laravel  │                                          │
│  │  :9090   │   scrape     │ /prometheus                                          │
│  └────┬─────┘              └──────────┘                                          │
│       │                                                                           │
│       ▼                                                                           │
│  ┌──────────┐                                                                    │
│  │  Grafana │                                                                    │
│  │  :3000   │                                                                    │
│  └──────────┘                                                                    │
└─────────────────────────────────────────────────────────────────────────────────────┘
```

## Компоненты

### Laravel PHP-FPM
- **Порт**: 9000 (внутренний), 80 (внешний через Nginx)
- **pm.max_children**: до 8
- **pm.max_requests**: 500 (защита от утечек памяти)
- **Кэш**: Redis (консистентность между репликами через Sentinel)

### PostgreSQL 18 — Primary
- Logical replication включён (`wal_level = logical`)
- Streaming replication: настроен слот `replica_slot` для реплики
- Индексы: `code`, `section_id`, `price`, составной (`id`, `name`, `code`, `price`, `section_id`)
- Слот репликации: `debezium_slot_products` (для CDC), `replica_slot` (для streaming replication)

### PostgreSQL 18 — Replica (read-only)
- Streaming replication с primary
- `hot_standby = on` — разрешены read-only запросы
- Автоматический base backup при первом запуске через `pg_basebackup`
- Порт: 5433 (внешний)

### Redis Master + Sentinel (3 узла)
- **Redis Master**: порт 6379, AOF + snapshot persistence
- **Sentinel quorum**: 2 (для автоматического failover)
- **down-after-milliseconds**: 5000ms
- **failover-timeout**: 10000ms
- Sentinel автоматически повышает реплику до мастера при отказе текущего мастера

### Kafka + Zookeeper
- **Версия**: Confluent 7.6
- **Топик CDC**: `dbserver1.public.products`
- **Топик ручных сообщений**: `product_changes`

### Debezium Connect
- **Версия**: 2.5.4.Final
- **Plugin**: pgoutput (встроенный logical replication PostgreSQL)
- **Snapshot mode**: initial
- Подключается к **pgsql-primary**

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
Client → Nginx:80 → Laravel PHP-FPM:9000 → PostgreSQL Primary
                                         → Redis Master (кэш, через Sentinel)
```

### CDC (Change Data Capture)
```
PostgreSQL Primary (WAL) → Debezium Connect → Kafka Topic → Artisan Consumer → ClickHouse
```

### Метрики
```
Laravel Middleware → Cache (file) → /prometheus endpoint → Prometheus → Grafana
```

## Отказоустойчивость

### PostgreSQL Streaming Replication
- Primary принимает все записи
- Replica синхронизируется через WAL (streaming replication)
- При отказе primary: replica можно повысить вручную (`pg_ctl promote`) или через Patroni/Pgpool-II
- Debezium подключается только к primary (CDC требует записи)

### Redis Sentinel
- 3 узла Sentinel мониторят Redis master
- При недоступности мастера >5s — Sentinel инициирует выборы
- Quorum = 2 (минимум 2 Sentinel должны согласиться)
- Laravel использует `predis` или `phpredis` с поддержкой Sentinel
- После failover клиенты автоматически подключаются к новому мастеру