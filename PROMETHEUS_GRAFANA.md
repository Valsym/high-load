# Prometheus + Grafana Monitoring

## Архитектура

```
┌──────────────┐     scrape /prometheus     ┌────────────┐
│  Laravel     │ ◄───────────────────────── │ Prometheus │
│  (PHP-FPM)   │                             │ :9090      │
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

**Как это работает:**

1. **Middleware** (`PrometheusMetrics`) перехватывает каждый HTTP-запрос к API/Web, замеряет время ответа и инкрементирует счётчик.
2. Данные сохраняются в **Laravel Cache** (файловое хранилище `storage/framework/cache/prometheus/`) — это решает проблему PHP-FPM (каждый запрос — отдельный процесс).
3. **Prometheus** раз в 10 секунд скрейпит `/prometheus` на Laravel.
4. В момент скрейпа пакет `spatie/laravel-prometheus` вызывает Closures, которые читают данные из кэша через `MetricsRegistry`.
5. **Grafana** использует Prometheus как datasource и отображает дашборды.

---

## Быстрый старт

### 1. Поднять весь стек

```bash
# Из корня проекта (high-rps-pg)
docker compose up -d
```

Проверить, что все сервисы запущены:

```bash
docker compose ps --format 'table {{.Names}}\t{{.Status}}'
```

Ожидаемый результат — все сервисы в статусе `Up`:
- `laravel.test`
- `pgsql`
- `clickhouse`
- `kafka`, `zookeeper`
- `kafka-connect`
- `prometheus`
- `grafana`

### 2. Проверить Laravel

```bash
# Проверить, что Laravel отвечает
curl -s http://localhost/api/products | head -c 200

# Проверить, что метрики отдаются
curl -s http://localhost/prometheus
```

Ожидаемый результат — метрики:
```
# HELP app_app_memory_bytes
# TYPE app_app_memory_bytes gauge
app_app_memory_bytes{type="current"} 2097152
app_app_memory_bytes{type="peak"} 2097152
# HELP app_http_requests_total
# TYPE app_http_requests_total counter
# HELP app_http_request_duration_seconds
# TYPE app_http_request_duration_seconds gauge
```

**Важно:** `http_requests_total` и `http_request_duration_seconds` появятся только после того, как будут сделаны реальные HTTP-запросы к API (см. шаг 4).

### 3. Проверить Prometheus

```bash
# Статусы target'ов
curl -s http://localhost:9090/api/v1/targets | python3 -m json.tool | grep -E '"job"|"health"|"lastError"'
```

Ожидаемый результат:
- `laravel.test:80` — `health: "up"`
- `prometheus` — `health: "up"`

### 4. Сгенерировать нагрузку для появления метрик

```bash
# Сделать несколько запросов к API, чтобы middleware записал данные в кэш
for i in {1..10}; do
    curl -s http://localhost/api/products > /dev/null
done
```

Проверить, что метрики появились:

```bash
curl -s http://localhost/prometheus | grep -E "http_requests_total|http_request_duration_seconds"
```

Ожидаемый результат:
```
app_http_requests_total{method="GET",route="api/products",status="200"} 10
app_http_request_duration_seconds{method="GET",route="api/products",status="200"} 0.045
```

### 5. Проверить, что Prometheus собрал метрики

```bash
# Подождать 10-15 секунд (интервал скрейпа) и проверить
curl -s 'http://localhost:9090/api/v1/query?query=app_http_requests_total' | python3 -m json.tool
```

### 6. Открыть Grafana

1. Откройте браузер: `http://localhost:3000`
2. Логин: `admin`, пароль: `admin`
3. В левом меню: **Apps** (иконка квадратики) → **Dashboards**
4. Выберите дашборд **"High-Load Laravel"**

Или напрямую: `http://localhost:3000/d/high-load-laravel/high-load-laravel`

### 7. Проверить логи сервисов (при проблемах)

```bash
# Prometheus
docker compose logs prometheus --tail 50

# Grafana
docker compose logs grafana --tail 50

# Laravel
docker compose logs laravel.test --tail 50

# ClickHouse
docker compose logs clickhouse --tail 50
```

---

## Описание компонентов

### Laravel (app/)

| Файл | Назначение |
|------|-----------|
| [`app/Http/Middleware/PrometheusMetrics.php`](app/Http/Middleware/PrometheusMetrics.php) | Middleware для сбора RPS и latency. Добавлен в группы `api` и `web` через `PrometheusServiceProvider::boot()`. |
| [`app/Prometheus/MetricsRegistry.php`](app/Prometheus/MetricsRegistry.php) | Персистентное хранилище метрик через Laravel Cache (file). Решает проблему PHP-FPM (статики не живут между запросами). |
| [`app/Prometheus/CustomMetrics.php`](app/Prometheus/CustomMetrics.php) | Регистрация кастомных метрик: память PHP, размер таблиц ClickHouse. |
| [`app/Providers/PrometheusServiceProvider.php`](app/Providers/PrometheusServiceProvider.php) | Service Provider: регистрирует метрики в пакете и подключает middleware. |
| [`config/prometheus.php`](config/prometheus.php) | Конфиг пакета `spatie/laravel-prometheus`. Cache store: `prometheus_metrics`. |
| [`config/cache.php`](config/cache.php) | Добавлен store `prometheus_metrics` с file-драйвером. |

### Docker

| Файл | Назначение |
|------|-----------|
| [`docker/prometheus/config/prometheus.yml`](docker/prometheus/config/prometheus.yml) | Конфиг Prometheus: targets `laravel.test:80/prometheus` (scrape_interval 10s) и `localhost:9090`. |
| [`docker/grafana/provisioning/datasources/datasource.yml`](docker/grafana/provisioning/datasources/datasource.yml) | Datasource: Prometheus (`http://prometheus:9090`). |
| [`docker/grafana/provisioning/dashboards/dashboard.yml`](docker/grafana/provisioning/dashboards/dashboard.yml) | Provisioning директория для dashboard JSON. |
| [`docker/grafana/provisioning/dashboards/laravel-dashboard.json`](docker/grafana/provisioning/dashboards/laravel-dashboard.json) | Dashboard "High-Load Laravel" с панелями: RPS, latency, память, ClickHouse. |

### Метрики

| Метрика | Тип | Labels | Описание |
|---------|-----|--------|----------|
| `app_http_requests_total` | Counter | `method`, `route`, `status` | Количество HTTP-запросов |
| `app_http_request_duration_seconds` | Gauge | `method`, `route`, `status` | Время ответа (последнее значение) |
| `app_app_memory_bytes` | Gauge | `type` (current/peak) | Память PHP |
| `app_clickhouse_table_size_bytes` | Gauge | `table`, `database` | Размер таблиц ClickHouse |
| `app_clickhouse_table_rows` | Gauge | `table`, `database` | Количество записей в ClickHouse |

---

## Возможные проблемы

### Метрики не появляются в Prometheus

1. Проверить, что middleware активен:
   ```bash
   curl -s http://localhost/prometheus | grep http_requests_total
   ```
   Если метрики есть — проблема в Prometheus.

2. Проверить target в Prometheus:
   ```bash
   curl -s http://localhost:9090/api/v1/targets | python3 -m json.tool
   ```
   Искать `"job": "laravel"` и `"health": "up"`.

3. Если target `down` — проверить, что Laravel доступен из контейнера Prometheus:
   ```bash
   docker compose exec prometheus wget -qO- http://laravel.test:80/prometheus
   ```

### Grafana не запускается (permission denied)

```bash
# Исправить права на директорию данных
sudo chown -R 472:472 docker/grafana/data
docker compose up -d grafana
```

### ClickHouse не запускается

```bash
# Проверить логи
docker compose logs clickhouse --tail 20

# Пересоздать контейнер (если проблема в конфигах)
docker compose rm -sf clickhouse
docker compose up -d clickhouse
```

### Очистка кэша метрик

Если метрики устарели или накопились невалидные данные:

```bash
# Очистить кэш prometheus_metrics
docker compose exec laravel.test php artisan cache:clear --store=prometheus_metrics
```

Или вручную:
```bash
rm -rf storage/framework/cache/prometheus/*