# High‑Load Laravel: оптимизация и оркестрация

> Пет‑проект: полный цикл оптимизации Laravel‑приложения (от PHP‑FPM до Kubernetes) и внедрение AI‑агентов для автоматизации. **Результат: рост RPS с 33 до 1622** на VPS (4 vCPU, 6 GB RAM).

## Ключевые достижения

- **48× рост RPS** за счёт перехода на Laravel Octane (Swoole) и тонкой настройки инфраструктуры.
- **Stateful‑сервисы** (PostgreSQL, Redis) вынесены из приложения, настроены для работы в распределённой среде.
- **Оркестрация**: локальный Docker‑кластер (3 реплики, Nginx‑балансировщик) и Kubernetes (k3d) с HPA для автомасштабирования.
- **AI‑автоматизация**: Laravel Boost (MCP‑сервер) + SourceCraft для выполнения рутинных задач (Artisan‑команды, анализ БД, Tinker).

## Результаты производительности

| Конфигурация | RPS | p95 латентность | Сервер |
|---|---|---|---|
| PHP‑FPM (базовая) | 33 | 220 ms | 1 vCPU / 1 GB |
| PHP‑FPM + OPcache + кэши | 42 | 63 ms | 1 vCPU / 1 GB |
| Laravel Octane (1 vCPU) | 350 | 31 ms | 1 vCPU / 1 GB |
| Octane + 2 vCPU | 480 | 24 ms | 2 vCPU / 4 GB |
| **Финальная настройка** (индексы, keepalive, max‑requests) | **1622** | 98 ms | 4 vCPU / 6 GB |

> Скриншоты тестов `wrk` — в папке `/screenshots`.

## Технологический стек

- **Backend**: Laravel 13, PHP 8.4/8.5 (OPcache, Composer optimize), Laravel Octane (Swoole).
- **БД и кэш**: PostgreSQL (индексы: `code`, `section_id`, `price`), Redis.
- **Инфраструктура**: Docker Compose, Sail, Nginx (reverse‑proxy, балансировщик), Supervisor (управление Octane‑воркерами).
- **Оркестрация**: k3d (локальный Kubernetes), HPA (автомасштабирование), манифесты для stateful‑сервисов.
- **Тестирование**: `wrk` (нагрузочное), Xdebug (отключался для замеров).
- **AI & Automation**: GitHub Copilot (оптимизация кода), Laravel Boost + SourceCraft (MCP‑сервер для Artisan, Tinker, анализа БД).

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

## Kubernetes (k3d)
В ветке feature/k8s-local развёрнут локальный кластер k3d (1 master + 2 worker) с автомасштабированием (HPA).

**Ключевые манифесты:**

- postgres.yaml, redis.yaml — stateful‑сервисы.
- laravel.yaml — Deployment (3 реплики), Service, Ingress.
- hpa.yaml — HorizontalPodAutoscaler (масштабирование по CPU).

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

## Интеграция с AI‑агентом
Проект подключён к SourceCraft через Laravel Boost (MCP‑сервер). Это позволило автоматизировать рутинные задачи:
- Выполнение Artisan‑команд (миграции, генерация кода) через чат.
- Анализ схемы БД и выполнение SQL/Tinker‑запросов.
- Помощь в настройке инфраструктуры (K8s, Docker).
  
Конфигурация MCP‑сервера находится в ветке horizontal-scaling (.mcp.json).

## 📊 Мониторинг и логирование

В ветке `feature/elk-kibana` развёрнут локальный стек ELK (Elasticsearch, Logstash, Kibana) для сбора и анализа логов Laravel. Настроена отправка логов из приложения, произведена фильтрация и поиск событий в Kibana.

## Перспективы
- Вынос БД на отдельный сервер.
- Репликация PostgreSQL и Redis Sentinel для отказоустойчивости.
- Внедрение distributed tracing (OpenTelemetry) для мониторинга распределённых запросов.

## Как воспроизвести
[Полная инструкция по развёртыванию на VPS — в DEPLOY.md](DEPLOY.md)
