# High‑Load Laravel: оптимизация и оркестрация

> Пет‑проект: полный цикл оптимизации Laravel‑приложения (от PHP‑FPM до Kubernetes) и внедрение AI‑агентов для автоматизации. **Результат: рост RPS с 33 до 1622** на VPS (4 vCPU, 6 GB RAM).

## Ключевые достижения

- **48× рост RPS** (33 → 1622) на VPS за счёт перехода на Laravel Octane (Swoole), OPcache, оптимизации запросов и настройки инфраструктуры. Подробнее — в [`DEPLOY.md`](DEPLOY.md).
- **CDC Pipeline**: PostgreSQL → Kafka → ClickHouse через Debezium (автоматический) и кастомный продюсер (ручной). Подробнее — в [`CDC.md`](CDC.md).
- **Prometheus + Grafana мониторинг**: RPS, latency, память PHP, размер таблиц ClickHouse. Подробнее — в [`PROMETHEUS_GRAFANA.md`](PROMETHEUS_GRAFANA.md).
- **ELK (Elasticsearch, Logstash, Kibana)** — сбор и анализ логов Laravel (ветка `feature/elk-kibana`).
- **Горизонтальное масштабирование**: локальный Docker‑кластер (3 реплики, Nginx‑балансировщик) в ветке `horizontal-scaling`.
- **Kubernetes (k3d)** с HPA для автомасштабирования в ветке `feature/k8s-local`.
- **AI‑автоматизация**: Laravel Boost (MCP‑сервер) + SourceCraft для выполнения рутинных задач (Artisan‑команды, анализ БД, Tinker).

## Результаты производительности

| Конфигурация | RPS | p95 латентность | Сервер |
|---|---|---|---|
| PHP‑FPM (базовая) | 33 | 220 ms | 1 vCPU / 1 GB |
| PHP‑FPM + OPcache + кэши | 42 | 63 ms | 1 vCPU / 1 GB |
| Laravel Octane (1 vCPU) | 350 | 31 ms | 1 vCPU / 1 GB |
| Octane + 2 vCPU | 480 | 24 ms | 2 vCPU / 4 GB |
| **Финальная настройка** (индексы, keepalive, max‑requests) | **1622** | 98 ms | 4 vCPU / 6 GB |

> Тесты проводились на VPS. Скриншоты `wrk` — в папке [`screenshots/`](screenshots/).
> Настройка VPS с Octane описана в [`DEPLOY.md`](DEPLOY.md). Локально Octane используется в ветках `optimize/products-api-performance`, `horizontal-scaling`, `feature/k8s-local`.

## Технологический стек

| Категория | Технологии |
|-----------|-----------|
| **Backend** | Laravel 13, PHP 8.4/8.5 (OPcache, Composer optimize), Laravel Octane (Swoole) |
| **Базы данных** | PostgreSQL 18 (индексы: `code`, `section_id`, `price`, составной `id/name/code/price/section_id`), ClickHouse (MergeTree, ReplacingMergeTree) |
| **Кэш** | Redis (файловый кэш для Prometheus-метрик) |
| **Message Broker** | Kafka (Confluent 7.6), Zookeeper |
| **CDC** | Debezium Connect 2.5 (pgoutput plugin) |
| **Мониторинг** | Prometheus, Grafana (дашборды с RPS, latency, памятью, ClickHouse) |
| **Логирование** | ELK (Elasticsearch, Logstash, Kibana) — ветка `feature/elk-kibana` |
| **Инфраструктура** | Docker Compose, Laravel Sail, Nginx (reverse-proxy), Supervisor (Octane workers) |
| **Оркестрация** | k3d (локальный Kubernetes), HPA (автомасштабирование) |
| **Тестирование** | `wrk` (нагрузочное), Xdebug (отключался для замеров) |
| **AI & Automation** | GitHub Copilot, Laravel Boost + SourceCraft (MCP-сервер) |

## Архитектура и ключевые решения

### Оптимизация эндпоинта `/api/products`

**Исходная проблема**: ~5 RPS локально (WSL + Docker) из‑за N+1 запросов, загрузки всех полей (включая `description`), отсутствия индексов, включённого Xdebug.

**Выполненные шаги**:
1. **Выборочная загрузка колонок** в `ProductController` (без `description`).
2. **Оптимизация `ProductResource`**: устранён N+1 через `whenLoaded`, сокращён объём данных.
3. **Замена `paginate` на `cursorPaginate`** для ускорения больших страниц.
4. **Отключение Xdebug** через кастомный Dockerfile → ~2× рост.
5. **Индексация БД**: составной индекс (`id`, `name`, `code`, `price`, `section_id`) для ускорения сортировки.
6. **Кэширование ответа** в `ProductController::index()` на 2 секунды → +300%.
7. **Переход на Laravel Octane** (Swoole) + настройка Supervisor.
8. **Масштабирование воркеров** до 8 → линейный рост RPS.

Все изменения закоммичены в ветке `optimize/products-api-performance`.

### Горизонтальное масштабирование (Docker)

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

### Kubernetes (k3d)

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

### Интеграция с AI‑агентом

Проект подключён к SourceCraft через Laravel Boost (MCP‑сервер). Это позволило автоматизировать рутинные задачи:
- Выполнение Artisan‑команд (миграции, генерация кода) через чат.
- Анализ схемы БД и выполнение SQL/Tinker‑запросов.
- Помощь в настройке инфраструктуры (K8s, Docker).

Конфигурация MCP‑сервера находится в ветке `horizontal-scaling` (`.mcp.json`).

### Мониторинг и логирование

**Prometheus + Grafana** — реализован полноценный мониторинг (ветка `feature/kafka-clickhouse-grafana`):
- Метрики: RPS, latency, память PHP, размер таблиц ClickHouse.
- Дашборд "High-Load Laravel" с авто-провижинингом.
- Подробнее — в [`PROMETHEUS_GRAFANA.md`](PROMETHEUS_GRAFANA.md).

**ELK (Elasticsearch, Logstash, Kibana)** — в ветке `feature/elk-kibana` развёрнут локальный стек для сбора и анализа логов Laravel. Настроена отправка логов из приложения, фильтрация и поиск событий в Kibana.

## CDC Pipeline: PostgreSQL → Kafka → ClickHouse

Change Data Capture (CDC) пайплайн синхронизирует изменения товаров в аналитическое хранилище ClickHouse. Работает в двух режимах:

### Автоматический (Debezium CDC) — основной

**Debezium Source Connector** отслеживает изменения в PostgreSQL через logical replication (WAL) и публикует их в Kafka. Это позволяет видеть изменения, сделанные напрямую в БД (минуя API).

```
PostgreSQL (logical replication)
  │
  ▼
Debezium Connect ──► Kafka topic: dbserver1.public.products
                          │
                          ▼
            Artisan command: products:consume-debezium
                          │
                          ▼
                   ClickHouse
             ├─ product_changes (лог событий)
             └─ product_stats_by_section (агрегаты)
```

**Быстрый запуск:**
```bash
# Регистрация коннектора
php artisan debezium:register-connector

# Статус коннектора
php artisan debezium:status

# Запуск консьюмера CDC
php artisan products:consume-debezium
```

### Ручной (через API) — альтернатива

При создании, обновлении или удалении продукта через API отправляется сообщение в Kafka напрямую из `ProductController`. Консьюмер `products:consume-kafka` читает топик `product_changes` и пишет в ClickHouse.

```bash
php artisan products:consume-kafka
```

### Два режима работы

| Режим | Продюсер | Топик | Консьюмер | Когда использовать |
|-------|----------|-------|-----------|-------------------|
| **Автоматический (CDC)** | Debezium (WAL) | `dbserver1.public.products` | `products:consume-debezium` | Любые изменения в БД |
| **Ручной** | `ProductController` (API) | `product_changes` | `products:consume-kafka` | Изменения через API |

Оба режима работают параллельно и пишут в одни и те же таблицы ClickHouse.

> Полная документация по CDC, формату сообщений Debezium, конфигурации коннектора, таблицам ClickHouse и отладке — в [`CDC.md`](CDC.md).

## Prometheus + Grafana мониторинг

Реализован полноценный мониторинг на базе Prometheus и Grafana.

### Метрики

| Метрика | Тип | Labels | Описание |
|---------|-----|--------|---------|
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
|------|-----------|
| [`app/Http/Middleware/PrometheusMetrics.php`](app/Http/Middleware/PrometheusMetrics.php) | Middleware для сбора RPS и latency |
| [`app/Prometheus/MetricsRegistry.php`](app/Prometheus/MetricsRegistry.php) | Персистентное хранилище метрик через Laravel Cache |
| [`app/Prometheus/CustomMetrics.php`](app/Prometheus/CustomMetrics.php) | Кастомные метрики: память PHP, размер таблиц ClickHouse |
| [`app/Providers/PrometheusServiceProvider.php`](app/Providers/PrometheusServiceProvider.php) | Service Provider для метрик |
| [`config/prometheus.php`](config/prometheus.php) | Конфиг пакета `spatie/laravel-prometheus` |
| [`docker/prometheus/config/prometheus.yml`](docker/prometheus/config/prometheus.yml) | Конфиг Prometheus |
| [`docker/grafana/provisioning/dashboards/laravel-dashboard.json`](docker/grafana/provisioning/dashboards/laravel-dashboard.json) | Дашборд Grafana |

> Полная документация по мониторингу — в [`PROMETHEUS_GRAFANA.md`](PROMETHEUS_GRAFANA.md).

## ClickHouse

ClickHouse используется как аналитическая БД для хранения истории изменений товаров.

### Таблицы

- **`product_changes`** — история всех изменений товаров (CDC-события).
  `TTL created_at + INTERVAL 30 DAY` — автоматическое удаление записей старше 30 дней.
- **`product_stats_by_section`** — агрегированная статистика по секциям (ReplacingMergeTree).

### Материализованные представления

- **`daily_section_stats`** (SummingMergeTree) — ежедневная агрегация изменений по секциям.
- **`product_latest_state`** (ReplacingMergeTree) — актуальное состояние каждого товара.

> Подробное описание, инициализация и примеры запросов — в [`CDC.md`](CDC.md#clickhouse-таблицы-и-представления).

## Перспективы

### Среднесрочные (P1)

- **Distributed tracing (OpenTelemetry).** Внедрить сквозную трассировку запросов через Nginx → Laravel Octane → PostgreSQL / Kafka / ClickHouse. Это даст полную картину узких мест в распределённой архитектуре (особенно при горизонтальном масштабировании).
- **Отказоустойчивость.** Репликация PostgreSQL (streaming replication) + Redis Sentinel для автоматического переключения при сбое узла. Критично для сценариев, когда CDC-пайплайн не должен терять события.

### Инфраструктура (P2)

- **Выделенные серверы для БД.** Вынос PostgreSQL, Redis и ClickHouse на отдельные машины — снизит конкуренцию за ресурсы с воркерами Octane.
- **CI/CD.** Автоматизация деплоя на VPS и в k3d через GitHub Actions: сборка образа, прогон тестов, rolling update.

## Документация

| Файл | Описание |
|------|---------|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Детальная архитектура проекта |
| [`CDC.md`](CDC.md) | Change Data Capture через Debezium |
| [`DEPLOY.md`](DEPLOY.md) | Развёртывание на VPS |
| [`PROMETHEUS_GRAFANA.md`](PROMETHEUS_GRAFANA.md) | Мониторинг Prometheus + Grafana |
| [`QUICK_START.md`](QUICK_START.md) | Быстрый старт для разработчиков |
