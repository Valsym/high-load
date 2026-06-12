#!/bin/bash
set -e

clickhouse-client --query "
    CREATE TABLE IF NOT EXISTS default.product_changes (
        event_id UUID DEFAULT generateUUIDv4(),
        product_id UInt64,
        name String,
        code String,
        price Float64,
        total UInt32,
        section_id UInt64,
        section_name String,
        action String,
        created_at DateTime DEFAULT now()
    ) ENGINE = MergeTree()
    ORDER BY (created_at, product_id)
    TTL created_at + INTERVAL 30 DAY
"

clickhouse-client --query "
    CREATE TABLE IF NOT EXISTS default.product_stats_by_section (
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
"

# Материализованное представление: ежедневная статистика изменений по секциям
clickhouse-client --query "
    CREATE MATERIALIZED VIEW IF NOT EXISTS default.daily_section_stats
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
"

# Материализованное представление: статистика по товарам (последнее состояние)
clickhouse-client --query "
    CREATE MATERIALIZED VIEW IF NOT EXISTS default.product_latest_state
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
"