# High‑Load API на Laravel Octane

> Pet‑проект по достижению 1600+ RPS на VPS (4 vCPU) с полным циклом оптимизации: от PHP‑FPM до Octane, от индексов до балансировки.

## Результаты производительности

| Конфигурация                       | RPS     | p95 латентность | Сервер          |
|------------------------------------|---------|----------------|-----------------|
| PHP‑FPM (без оптимизаций)          | 33      | 220 ms          | 1 vCPU / 1 GB   |
| PHP‑FPM + OPcache + кэши           | 42      | 63 ms           | 1 vCPU / 1 GB   |
| Laravel Octane (Swoole)            | 350     | 31 ms           | 1 vCPU / 1 GB   |
| Octane + 2 vCPU                    | 480     | 24 ms           | 2 vCPU / 4 GB   |
| Octane + 4 vCPU + keepalive        | 1450    | 100 ms          | 4 vCPU / 6 GB   |
| **Окончательная настройка (индексы, max‑requests=1000)** | **1622** | 98 ms | 4 vCPU / 6 GB |

> Скриншоты тестов `wrk` прилагаются в папке `/screenshots`.

## Стек

- Laravel 13, Octane (Swoole)
- PostgreSQL, Redis
- Nginx (reverse‑proxy с keepalive), Supervisor
- PHP 8.4, OPcache, Composer optimize
- Нагрузочное тестирование: `wrk`

## Пошаговые улучшения

1. **Переход на Octane** – RPS вырос с 42 до 350 (8x) на 1 vCPU.
2. **Масштабирование воркеров** – на 2 vCPU (2 воркера) → 480 RPS.
3. **Увеличение vCPU до 4** – 4 воркера → 1450 RPS.
4. **Тонкая настройка**:
   - `keepalive 16` в Nginx upstream
   - Индексы в PostgreSQL (`code`, `section_id`, `price`)
   - `--max-requests=1000` в Octane (перезапуск воркеров)
   - **Результат:** **1622 RPS**

## 🖼 Скриншоты

![wrk test 33 RPS](screenshots/rps-33.png)
![wrk test 1622 RPS](screenshots/rps-1622.png)

## ⚙️ Как развернуть аналогичный сервер

[Краткая инструкция — ссылка на отдельный файл DEPLOY.md](DEPLOY.md)

## Горизонтальное масштабирование (планируется)

Балансировщик Nginx + два сервера приложений с Octane.



