# High‑Load Laravel: оптимизация и оркестрация

> Пет‑проект: полный цикл оптимизации Laravel‑приложения (от PHP‑FPM до Kubernetes) и внедрение AI‑агентов для автоматизации. **Результат: рост RPS с 33 до 1622** на VPS (4 vCPU, 6 GB RAM).

---

## 📋 Содержание

- [Ключевые достижения](#ключевые-достижения)
- [Результаты производительности](#результаты-производительности)
- [Технологический стек](#технологический-стек)
- [Архитектура проекта](#архитектура-проекта)
- [Быстрый старт](#быстрый-старт)
- [Docker Compose стек](#docker-compose-стек)
- [Оптимизация эндпоинта `/api/products`](#оптимизация-эндпоинта-apiproducts)
- [Горизонтальное масштабирование (Docker)](#горизонтальное-масштабирование-docker)
- [Kubernetes (k3d)](#kubernetes-k3d)
- [Kafka + CDC (Change Data Capture)](#kafka--cdc-change-data-capture)
- [ClickHouse](#clickhouse)
- [Prometheus + Grafana мониторинг](#prometheus--grafana-мониторинг)
- [Интеграция с AI-агентом](#интеграция-с-ai-агентом)
- [Структура проекта](#структура-проекта)
- [Перспективы](#перспективы)

---

## Ключевые достижения

- **48× рост RPS** за счёт перехода на Laravel Octane (Swoole) и тонкой настройки инфраструктуры.
- **Stateful‑сервисы** (PostgreSQL, Redis) вынесены из приложения, настроены для работы в распределённой среде.
- **Оркестрация**: локальный Docker‑кластер (3 реплики, Nginx‑балансировщик) и Kubernetes (k3d) с HPA для автомасштабирования.
- **Kafka + Debezium CDC**: автоматическое отслеживание изменений в PostgreSQL и репликация в ClickHouse.
- **Мониторинг**: Prometheus + Grafana с кастомными метриками (RPS, latency, память PHP, размер таблиц ClickHouse).
- **AI‑автоматизация**: Laravel Boost (MCP‑сервер) + SourceCraft для выполнения рутинных задач (Artisan‑команды, анализ БД, Tinker).

---

## Результаты производительности

| Конфигурация | RPS | p95 латентность | Сервер |
|---|---|---|---|
| PHP‑FPM (базовая) | 33 | 220 ms | 1 vCPU / 1 GB |
| PHP‑FPM + OPcache + кэши | 42 | 63 ms | 1 vCPU / 1 GB |
| Laravel Octane (1 vCPU) | 350 | 31 ms | 1 vCPU / 1 GB |
| Octane + 2 vCPU | 480 | 24 ms | 2 vCPU / 4 GB |
| **Финальная настройка** (индексы, keepalive, max‑requests) | **1622** | 98 ms | 4 vCPU / 6 GB |

> Скриншоты тестов `wrk` — в папке [`screenshots/`](screenshots/).

---

## Технологический стек

| Категория | Технологии |
|---|---|
| **Backend** | Laravel 13, PHP 8.4/8.5 (OPcache, Composer optimize), Laravel Octane (Swoole) |
| **Базы данных** | PostgreSQL 18 (индексы: `code`, `section_id`, `price`), ClickHouse |
| **Кэш** | Redis (файловый кэш для Prometheus-метрик) |
| **Message Broker** | Kafka (Confluent 7.6), Zookeeper |
| **CDC** | Debezium Connect 2.5 (pgoutput plugin) |
| **Мониторинг** | Prometheus, Grafana (дашборды с RPS, latency, памятью, ClickHouse) |
| **Инфраструктура** | Docker Compose, Laravel Sail, Nginx (reverse-proxy), Supervisor (Octane workers) |
| **Оркестрация** | k3d (локальный Kubernetes), HPA (автомасштабирование) |
| **Тестирование** | `wrk` (нагрузочное), Xdebug (отключался для замеров) |
| **AI & Automation** | GitHub Copilot, Laravel Boost + SourceCraft (MCP-сервер) |

---

## Архитектура проекта

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Docker Compose (Sail)                        │
│                                                                     │
│  ┌──────────┐   ┌──────────┐   ┌──────────┐   ┌───────────────┐   │
│  │  Nginx   │──▶│ Laravel  │──▶│PostgreSQL│   │   ClickHouse  │   │
│  │  :80     │   │ Octane   │   │  :5432   │   │   :8123       │   │
│  └──────────┘   │ Swoole   │   └──────────┘   └───────────────┘   │
│                 │ :8000    │         │                               │
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

Подробная архитектура с описанием каждого компонента — в [`ARCHITECTURE.md`](ARCHITECTURE.md).

---

## Быстрый старт

### 1. Клонирование и настройка

```bash
git clone <repo-url> high-rps-pg
cd high-rps-pg
cp .env.example .env
# Отредактируйте .env под своё окружение
```

### 2. Запуск всех сервисов

```bash
docker compose up -d
```

### 3. Установка зависимостей и миграции

```bash
./vendor/bin/sail composer install
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

### 4. Проверка

```bash
curl -s http://localhost/api/products | head -c 200
```

> Полная инструкция по развёртыванию на VPS — в [`DEPLOY.md`](DEPLOY.md).
> Быстрый старт для новых разработчиков — в [`QUICK_START.md`](QUICK_START.md).

---

## Docker Compose стек

Все сервисы описаны в [`compose.yaml`](compose.yaml):

| Сервис | Образ | Порт | Назначение |
|---|---|---|---|
| `laravel.test` | sail-8.5/app (кастомный) | `:80` | Laravel Octane (Swoole) |
| `pgsql` | postgres:18-alpine | `:5432` | PostgreSQL с logical replication |
| `clickhouse` | yandex/clickhouse-server | `:8123`, `:9000` | Аналитическая БД для CDC |
| `zookeeper` | confluentinc/cp-zookeeper:7.6 | `:2181` | Координация Kafka |
| `kafka` | confluentinc/cp-kafka:7.6 | `:9092` | Message broker |
| `kafka-connect` | debezium/connect:2.5.4 | `:8083` | Debezium CDC connector |
| `prometheus` | prom/prometheus | `:9090` | Сбор метрик |
| `grafana` | grafana/grafana | `:3000` | Визуализация метрик |

---

## Оптимизация эндпоинта `/api/products`

**Исходная проблема**: ~5 RPS локально (WSL + Docker) из‑за N+1 запросов, загрузки всех полей (включая `description`), отсутствия индексов, включённого Xdebug.

**Выполненные шаги**:
1. **Выборочная загрузка колонок** в [`ProductController`](app/Http/Controllers/Api/ProductController.php) (без `description`).
2. **Оптимизация [`ProductResource`](app/Http/Resources/ProductResource.php)**: устранён N+1 через `whenLoaded`, сокращён объём данных.
3. **Замена `paginate` на `cursorPaginate`** для ускорения больших страниц.
4. **Отключение Xdebug** через кастомный Dockerfile → ~2× рост.
5. **Индексация БД**: составной индекс (`id`, `name`, `code`, `price`, `section_id`) для ускорения сортировки.
6. **Кэширование ответа** в [`ProductController::index()`](app/Http/Controllers/Api/ProductController.php) на 2 секунды → +300%.
7. **Переход на Laravel Octane** (Swoole) + настройка Supervisor.
8. **Масштабирование воркеров** до 8 → линейный рост RPS.

Все изменения закоммичены в ветке `optimize/products-api-performance`.

---

## Горизонтальное масштабирование (Docker)

В ветке `horizontal-scaling` реализован локальный Docker‑кластер из трёх реплик приложения с балансировщиком Nginx (Round Robin). Redis используется для консистентного кэша между инстансами.

**Запуск кластера**:
```bash
git checkout horizontal-scaling
sail down
docker-compose -f docker-compose.scale.yml up --scale laravel.test=3 -d
# Балансировщик доступен на http://localhost:8080
```

**Проверка балансировки:**
```bash
for i in {1..6}; do curl -s http://localhost:8080/api/products/1 | grep -o '"server":"[^"]*"'; done
# Вывод покажет разные идентификаторы контейнеров, подтверждая работу Round Robin.
```

---

## Kubernetes (k3d)

В ветке `feature/k8s-local` развёрнут локальный кластер k3d (1 master + 2 worker) с автомасштабированием (HPA).

**Ключевые манифесты:**
- `postgres.yaml`, `redis.yaml` — stateful‑сервисы.
- `laravel.yaml` — Deployment (3 реплики), Service, Ingress.
- `hpa.yaml` — HorizontalPodAutoscaler (масштабирование по CPU).

**Запуск:**
```bash
git checkout feature/k8s-local
k3d cluster create laravel-cluster --servers 1 --agents 2 --port "8080:80@loadbalancer"
k3d image import laravel-octane:latest -c laravel-cluster
kubectl apply -f k8s/
```

**Нагрузочное тестирование:**
```bash
wrk -t4 -c100 -d30s --latency http://localhost:8080/api/products/1
# Во время теста наблюдайте за автомасштабированием:
kubectl get pods -w
# Количество подов будет расти до maxReplicas при нагрузке.
#
# metrics-server был установлен отдельно (иначе HPA не получит метрики).
kubectl apply -f https://github.com/kubernetes-sigs/metrics-server/releases/latest/download/components.yaml
```

---

## Kafka + CDC (Change Data Capture)

Проект использует **Debezium** для автоматического отслеживания изменений в PostgreSQL через logical replication.

### Схема работы

```
PostgreSQL (logical replication)
    │
    ▼
Debezium Connect (Kafka Connect) :8083
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

### Команды

```bash
# Запустить Kafka Connect
./vendor/bin/sail up -d kafka-connect

# Зарегистрировать Debezium коннектор
./vendor/bin/sail artisan debezium:register-connector

# Проверить статус
./vendor/bin/sail artisan debezium:status

# Запустить консьюмер Debezium-событий
./vendor/bin/sail artisan products:consume-debezium
```

### Ключевые файлы

| Файл | Назначение |
|---|---|
| [`docker/kafka-connect/debezium-product-connector.json`](docker/kafka-connect/debezium-product-connector.json) | Конфигурация Debezium коннектора |
| [`app/Console/Commands/ConsumeDebeziumChanges.php`](app/Console/Commands/ConsumeDebeziumChanges.php) | Консьюмер Debezium-событий → ClickHouse |
| [`app/Console/Commands/ConsumeProductChanges.php`](app/Console/Commands/ConsumeProductChanges.php) | Альтернативный консьюмер для ручных Kafka-сообщений |
| [`app/Console/Commands/RegisterDebeziumConnector.php`](app/Console/Commands/RegisterDebeziumConnector.php) | Artisan-команда регистрации коннектора |
| [`app/Console/Commands/DebeziumConnectorStatus.php`](app/Console/Commands/DebeziumConnectorStatus.php) | Проверка статуса коннектора |
| [`app/Console/Commands/ClickHouseInitTables.php`](app/Console/Commands/ClickHouseInitTables.php) | Инициализация таблиц ClickHouse |
| [`bootstrap/kafka-constants.php`](bootstrap/kafka-constants.php) | Фикс совместимости `mateusjunges/laravel-kafka` с ext-rdkafka |

> Полная документация по CDC — в [`CDC.md`](CDC.md).

---

## ClickHouse

ClickHouse используется как аналитическая БД для хранения истории изменений товаров.

### Таблицы

- **`product_changes`** — история всех изменений товаров (CDC-события)
- **`product_stats_by_section`** — агрегированная статистика по секциям

### Инициализация

```bash
./vendor/bin/sail artisan clickhouse:init-tables
```

### Проверка данных

```bash
# Подключиться к ClickHouse
docker compose exec clickhouse clickhouse-client

# Посмотреть историю изменений
SELECT * FROM high_rps.product_changes ORDER BY changed_at DESC LIMIT 10;

# Статистика по секциям
SELECT * FROM high_rps.product_stats_by_section;
```

---

## Prometheus + Grafana мониторинг

### Архитектура

```
┌──────────────┐     scrape /prometheus     ┌────────────┐
│  Laravel     │ ◄───────────────────────── │ Prometheus │
│  (Octane)    │                             │ :9090      │
│              │  MetricsRegistry            │            │
│  Middleware ─┼──► Cache (file) ──► Closures│            │
└──────────────┘                             └─────┬──────┘
                                                     │ datasource
                                                     ▼
                                             ┌────────────┐
                                             │  Grafana    │
                                             │  :3000      │
                                             │  Dashboards │
                                             └────────────┘
```

### Метрики

| Метрика | Тип | Labels | Описание |
|---|---|---|---|
| `app_http_requests_total` | Counter | `method`, `route`, `status` | Количество HTTP-запросов |
| `app_http_request_duration_seconds` | Gauge | `method`, `route`, `status` | Время ответа (последнее значение) |
| `app_app_memory_bytes` | Gauge | `type` (current/peak) | Память PHP |
| `app_clickhouse_table_size_bytes` | Gauge | `table`, `database` | Размер таблиц ClickHouse |
| `app_clickhouse_table_rows` | Gauge | `table`, `database` | Количество записей в ClickHouse |

### Быстрый запуск

```bash
# Поднять стек
docker compose up -d

# Проверить метрики
curl -s http://localhost/prometheus

# Открыть Grafana: http://localhost:3000 (admin/admin)
# Дашборд: "High-Load Laravel"
```

### Ключевые файлы

| Файл | Назначение |
|---|---|
| [`app/Http/Middleware/PrometheusMetrics.php`](app/Http/Middleware/PrometheusMetrics.php) | Middleware для сбора RPS и latency |
| [`app/Prometheus/MetricsRegistry.php`](app/Prometheus/MetricsRegistry.php) | Персистентное хранилище метрик через Laravel Cache |
| [`app/Prometheus/CustomMetrics.php`](app/Prometheus/CustomMetrics.php) | Кастомные метрики: память PHP, размер таблиц ClickHouse |
| [`app/Providers/PrometheusServiceProvider.php`](app/Providers/PrometheusServiceProvider.php) | Service Provider для метрик |
| [`config/prometheus.php`](config/prometheus.php) | Конфиг пакета `spatie/laravel-prometheus` |
| [`docker/prometheus/config/prometheus.yml`](docker/prometheus/config/prometheus.yml) | Конфиг Prometheus |
| [`docker/grafana/provisioning/dashboards/laravel-dashboard.json`](docker/grafana/provisioning/dashboards/laravel-dashboard.json) | Дашборд Grafana |

> Полная документация по мониторингу — в [`PROMETHEUS_GRAFANA.md`](PROMETHEUS_GRAFANA.md).

---

## Интеграция с AI-агентом

Проект подключён к SourceCraft через Laravel Boost (MCP‑сервер). Это позволило автоматизировать рутинные задачи:
- Выполнение Artisan‑команд (миграции, генерация кода) через чат.
- Анализ схемы БД и выполнение SQL/Tinker‑запросов.
- Помощь в настройке инфраструктуры (K8s, Docker).

Конфигурация MCP‑сервера находится в ветке `horizontal-scaling` (`.mcp.json`).

---

## Структура проекта

```
high-rps-pg/
├── app/
│   ├── Console/Commands/          # Artisan-команды (CDC, ClickHouse, Kafka)
│   ├── Http/
│   │   ├── Controllers/Api/       # API-контроллеры
│   │   ├── Middleware/             # PrometheusMetrics middleware
│   │   └── Resources/             # API Resource (ProductResource)
│   ├── Models/                    # Eloquent-модели
│   ├── Prometheus/                # Кастомные метрики
│   └── Providers/                 # Service Providers
├── bootstrap/
│   └── kafka-constants.php        # Фикс совместимости Kafka
├── config/                        # Конфиги Laravel
├── database/
│   ├── migrations/                # Миграции
│   ├── factories/                 # Фабрики
│   └── seeders/                   # Сиды
├── docker/
│   ├── clickhouse/                # Конфиги ClickHouse
│   ├── grafana/                   # Дашборды и datasource Grafana
│   ├── kafka-connect/             # Debezium connector config
│   ├── php/                       # Кастомный Dockerfile PHP
│   ├── postgres/                  # Конфиги PostgreSQL
│   └── prometheus/                # Конфиг Prometheus
├── routes/
│   ├── api.php                    # API-роуты
│   └── web.php                    # Web-роуты
├── screenshots/                   # Скриншоты нагрузочных тестов
├── compose.yaml                   # Docker Compose (все сервисы)
├── ARCHITECTURE.md                # Детальная архитектура
├── CDC.md                         # Документация CDC
├── DEPLOY.md                      # Инструкция по деплою на VPS
├── PROMETHEUS_GRAFANA.md          # Документация мониторинга
└── QUICK_START.md                 # Быстрый старт
```

---

## Перспективы

- Вынос БД на отдельный сервер.
- Репликация PostgreSQL и Redis Sentinel для отказоустойчивости.
- Внедрение distributed tracing (OpenTelemetry) для мониторинга распределённых запросов.
- Добавление Kafka Connect S3 Sink Connector для бэкапов CDC-событий.
- Materialized Views в ClickHouse для real-time агрегации.

---

## Документация

| Файл | Описание |
|---|---|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Детальная архитектура проекта |
| [`CDC.md`](CDC.md) | Change Data Capture через Debezium |
| [`DEPLOY.md`](DEPLOY.md) | Развёртывание на VPS |
| [`PROMETHEUS_GRAFANA.md`](PROMETHEUS_GRAFANA.md) | Мониторинг Prometheus + Grafana |
| [`QUICK_START.md`](QUICK_START.md) | Быстрый старт для разработчиков |
