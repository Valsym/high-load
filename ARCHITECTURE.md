# Архитектура проекта High‑Load Laravel

## Общая схема

Проект реализован в нескольких ветках, каждая из которых добавляет новый слой функциональности. Ниже — сводная архитектура всех компонентов.

```
┌──────────────────────────────────────────────────────────────────────────┐
│                          Docker Compose (Sail)                           │
│                                                                          │
│  ┌──────────┐   ┌──────────────┐   ┌────────────┐   ┌───────────────┐   │
│  │  Nginx   │──▶│   Laravel    │──▶│ PostgreSQL │   │   ClickHouse  │   │
│  │  :80     │   │ Octane/FPM   │   │   :5432    │   │   :8123       │   │
│  └──────────┘   └──────┬───────┘   └─────┬──────┘   └───────────────┘   │
│                        │                 │                               │
│                        │           ┌─────▼──────┐                       │
│                        │           │  Debezium  │                       │
│                        │           │  Connect   │                       │
│                        │           │   :8083    │                       │
│                        │           └─────┬──────┘                       │
│                        │                 │                              │
│                        │           ┌─────▼──────┐                       │
│                        │           │   Kafka    │                       │
│                        │           │   :9092    │                       │
│                        │           └─────┬──────┘                       │
│                        │                 │                              │
│                        │           ┌─────▼──────┐                       │
│                        └──────────▶│  Consumer  │──────────────────────▶│
│                                    │ (Artisan)  │     ClickHouse        │
│                                    └────────────┘                       │
│                                                                          │
│  ┌────────────┐              ┌────────────┐                             │
│  │ Prometheus │◀─────────────│  Laravel   │                             │
│  │   :9090    │   scrape     │ /prometheus │                             │
│  └─────┬──────┘              └────────────┘                             │
│        │                                                                │
│        ▼                                                                │
│  ┌────────────┐              ┌────────────┐                             │
│  │  Grafana   │              │    ELK     │                             │
│  │   :3000    │              │ (Kibana)   │                             │
│  └────────────┘              └────────────┘                             │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐    │
│  │              Kubernetes (k3d) — ветка feature/k8s-local          │    │
│  │  ┌──────────┐   ┌──────────┐   ┌──────────┐   ┌──────────┐      │    │
│  │  │ Ingress  │──▶│ Laravel  │──▶│PostgreSQL│   │  Redis   │      │    │
│  │  │  :80     │   │ (Pods)   │   │(Stateful)│   │(Stateful)│      │    │
│  │  └──────────┘   │ HPA      │   └──────────┘   └──────────┘      │    │
│  │                 │ 3-10 репл│                                     │    │
│  │                 └──────────┘                                     │    │
│  └──────────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐    │
│  │         Docker Cluster — ветка horizontal-scaling                │    │
│  │  ┌──────────┐   ┌──────────┐                                     │    │
│  │  │  Nginx   │──▶│ Laravel  │  (3 реплики, Round Robin)           │    │
│  │  │  :8080   │   │ (x3)     │                                     │    │
│  │  └──────────┘   └──────────┘                                     │    │
│  └──────────────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────────────┘
```

## Компоненты по веткам

### `main` — базовая архитектура
- Laravel PHP-FPM + Nginx (reverse-proxy)
- PostgreSQL + Redis
- Docker Compose (Laravel Sail)

### `optimize/products-api-performance` — оптимизация
- Переход на Laravel Octane (Swoole)
- OPcache, индексация БД, кэширование ответов
- Supervisor для управления Octane-воркерами
- **Результат**: рост RPS с 33 до 1622 на VPS

### `horizontal-scaling` — горизонтальное масштабирование
- Docker-кластер из 3 реплик Laravel Octane
- Nginx-балансировщик (Round Robin)
- Redis для консистентного кэша между инстансами

### `feature/k8s-local` — Kubernetes
- Локальный кластер k3d (1 master + 2 worker)
- Deployment с HPA (автомасштабирование по CPU, 3–10 подов)
- Stateful-сервисы: PostgreSQL, Redis

### `feature/kafka-clickhouse-grafana` — CDC Pipeline
- **Ручной режим**: API → Kafka (топик `product_changes`) → ClickHouse
- **Автоматический режим (Debezium)**: PostgreSQL WAL → Debezium Connect → Kafka (топик `dbserver1.public.products`) → ClickHouse
- Два консьюмера: `products:consume-kafka` (ручной), `products:consume-debezium` (CDC)
- Таблицы ClickHouse: `product_changes` (MergeTree), `product_stats_by_section` (ReplacingMergeTree)

### `feature/debezium` — Debezium CDC
- Debezium Connect 2.5.4.Final с pgoutput plugin
- Logical replication PostgreSQL (`wal_level = logical`)
- Слот репликации: `debezium_slot_products`
- Публикация: `debezium_pub_products`

### `feature/kafka-clickhouse-grafana` — мониторинг
- Prometheus (scrape interval: 10s)
- Grafana с дашбордом "High-Load Laravel" (авто-провижининг)
- Метрики: RPS, latency, память PHP, размер таблиц ClickHouse
- Middleware `PrometheusMetrics`, кастомный `MetricsRegistry`

### `feature/elk-kibana` — логирование
- Elasticsearch + Logstash + Kibana
- Отправка логов из Laravel-приложения
- Фильтрация и поиск событий в Kibana

## Потоки данных

### HTTP-запрос (основной)
```
Client → Nginx:80 → Laravel (Octane/FPM) → PostgreSQL
                                          → Redis (кэш)
```

### CDC (Change Data Capture) — автоматический
```
PostgreSQL (WAL) → Debezium Connect → Kafka Topic → Artisan Consumer → ClickHouse
```

### Ручной Kafka
```
API → ProductController → Kafka Topic → Artisan Consumer → ClickHouse
```

### Метрики
```
Laravel Middleware → Cache (file) → /prometheus endpoint → Prometheus → Grafana
```

### Логи
```
Laravel → Logstash → Elasticsearch → Kibana
```

## Технологический стек

| Категория | Технологии | Ветка |
|-----------|-----------|-------|
| **Backend** | Laravel 13, PHP 8.4/8.5, Octane (Swoole) / PHP-FPM | `main`, `optimize/*` |
| **Базы данных** | PostgreSQL 18, ClickHouse (MergeTree, ReplacingMergeTree) | `main`, `feature/kafka-*` |
| **Кэш** | Redis | `main` |
| **Message Broker** | Kafka (Confluent 7.6), Zookeeper | `feature/kafka-*` |
| **CDC** | Debezium Connect 2.5 (pgoutput) | `feature/debezium` |
| **Мониторинг** | Prometheus, Grafana | `feature/kafka-clickhouse-grafana` |
| **Логирование** | ELK (Elasticsearch, Logstash, Kibana) | `feature/elk-kibana` |
| **Оркестрация** | Docker Compose, k3d, HPA | `horizontal-scaling`, `feature/k8s-local` |
| **AI & Automation** | SourceCraft, Laravel Boost (MCP-сервер) | `horizontal-scaling` |

## Документация

| Файл | Описание |
|------|---------|
| [`README.md`](README.md) | Основная документация проекта |
| [`CDC.md`](CDC.md) | Change Data Capture через Debezium |
| [`DEPLOY.md`](DEPLOY.md) | Развёртывание на VPS |
| [`PROMETHEUS_GRAFANA.md`](PROMETHEUS_GRAFANA.md) | Мониторинг Prometheus + Grafana |
| [`QUICK_START.md`](QUICK_START.md) | Быстрый старт для разработчиков |