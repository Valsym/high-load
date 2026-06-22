# High‑Load API / ветка horizontal-scaling

> Горизонтальное масштабирование Laravel-приложения

Развернул локальный кластер из трёх реплик Octane за Nginx‑балансировщиком. Настроил stateless‑архитектуру (общие Redis/PostgreSQL). Провёл нагрузочное тестирование, зафиксировал рост пропускной способности при добавлении реплик. Получил практический опыт, необходимый для построения отказоустойчивых high‑load систем.


## Результаты производительности (wrk)

| Конфигурация                       | RPS     |
|------------------------------------|---------|
| 1 реплика Laravel Octane без балансировки | ~30      |
| **3 реплики Laravel Octane за Nginx‑балансировщиком** | **~50** |


## Стек

- Laravel 13, Octane (Swoole)
- PostgreSQL, Redis
- Nginx (reverse‑proxy с keepalive), Supervisor
- PHP 8.5, Composer optimize
- Нагрузочное тестирование: `wrk`


## Горизонтальное масштабирование
- Создан локальный Docker‑кластер из 3 реплик приложения (Laravel Octane) и Nginx в роли балансировщика.
- Балансировка Round Robin подтверждена через вывод `gethostname()` в API.
- Нагрузочное тестирование (`wrk -t4 -c100 -d30s`) на endpoint `/api/products` показало увеличение RPS с ~30 (один экземпляр) до ~50 (три экземпляра) при ограниченных ресурсах (Core i7 860).
- Прирост не линеен из‑за узкого места в CPU и сетевом стеке WSL, но сам факт масштабирования демонстрирует архитектурную готовность проекта к горизонтальному росту.


## Запуск

```bash
# Остановите обычный Sail
sail down

# Запустите масштабируемый кластер (3 реплики)
docker-compose -f docker-compose.scale.yml up --scale laravel.test=3 -d
```

> **Важно:** Перед первым запуском убедитесь, что `laravel/octane` установлен:
> ```bash
> vendor/bin/sail composer install --no-interaction
> ```

### Проверка работы реплик Laravel Octane

```bash
for i in {1..6}; do curl -s http://localhost:8080/api/products/1 | grep -o '"server":"[^"]*"'; done
"server":"f227366883a5"
"server":"937bd61227d0"
"server":"e9f2c1380173"
"server":"f227366883a5"
"server":"937bd61227d0"
"server":"e9f2c1380173"
```

### Нагрузочное тестирование

```bash
wrk -t4 -c100 -d30s --latency http://localhost:8080/api/products
Running 30s test @ http://localhost:8080/api/products
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency     1.14s   489.61ms   2.00s    60.78%
    Req/Sec    15.51     10.49    80.00     69.21%
  Latency Distribution
     50%    1.13s
     75%    1.56s
     90%    1.83s
     99%    1.98s
  1555 requests in 30.81s, 3.22MB read
  Socket errors: connect 0, read 0, write 0, timeout 660
Requests/sec:     50.47
Transfer/sec:    107.10KB
```


## Известные проблемы и решения

### 1. Octane не запускается — Class "Laravel\Octane\Octane" not found

**Причина:** Пакет `laravel/octane` не установлен в `vendor/`.

**Решение:**
```bash
vendor/bin/sail composer install --no-interaction
```

### 2. PostgreSQL не стартует — logical replication slot exists, but wal_level < logical

**Причина:** В данных PostgreSQL остался Debezium replication slot от предыдущей настройки, а `wal_level` теперь стоит по умолчанию (`replica`).

**Решение:** Удалить volume с данными PostgreSQL и пересоздать:
```bash
docker-compose -f docker-compose.scale.yml down
docker volume rm high-rps-pg_sail-pgsql
docker-compose -f docker-compose.scale.yml up --scale laravel.test=3 -d
vendor/bin/sail artisan migrate --seed
```

### 3. Nginx 502 Bad Gateway — no live upstreams

**Причина:** Laravel-контейнеры не запустились (см. проблему 1), nginx не видит живых upstream'ов.

**Решение:** Устранить причину падения Octane (п. 1) и перезапустить.

### 4. Балансировка не работает — все запросы уходят на один сервер

**Причина:** Nginx кэширует DNS-резолв `laravel.test` в один IP. Без `resolver` и `resolve` в upstream nginx не перерезолвивает DNS при изменении списка реплик.

**Решение:** В [`nginx-balancer.conf`](nginx-balancer.conf) добавлены:
```nginx
resolver 127.0.0.11 valid=5s;

upstream backend {
    zone backend 64k;
    server laravel.test:80 resolve;
}
```

### 5. Permission denied при удалении vendor-пакетов

**Причина:** Sail запускает composer от пользователя `sail`, но некоторые файлы созданы от `root`.

**Решение:** Удалять проблемные директории через `sudo` на хосте или через `vendor/bin/sail root-shell`.


## Структура конфигурации

| Файл | Назначение |
|------|-----------|
| [`docker-compose.scale.yml`](docker-compose.scale.yml) | Docker Compose для масштабируемого кластера |
| [`nginx-balancer.conf`](nginx-balancer.conf) | Nginx reverse-proxy с динамическим DNS |
| [`Dockerfile.octane`](Dockerfile.octane) | Альтернативный Dockerfile для Octane (multi-stage) |
| [`config/octane.php`](config/octane.php) | Конфигурация Octane (Swoole) |
