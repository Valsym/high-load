#!/bin/bash
set -e

CLICKHOUSE_PASSWORD="${CLICKHOUSE_PASSWORD:-clickhouse}"

clickhouse-client --password "$CLICKHOUSE_PASSWORD" --query "
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
"

clickhouse-client --password "$CLICKHOUSE_PASSWORD" --query "
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