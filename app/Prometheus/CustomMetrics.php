<?php

namespace App\Prometheus;

use Illuminate\Support\Facades\DB;
use Spatie\Prometheus\Facades\Prometheus;

class CustomMetrics
{
    /**
     * Регистрация пользовательских метрик:
     * - Использование памяти PHP
     * - Размер таблиц ClickHouse
     * - Kafka consumer lag
     */
    public static function register(): void
    {
        // Память PHP
        Prometheus::addGauge('app_memory_bytes')
            ->label('type')
            ->value(function () {
                return [
                    [memory_get_usage(true), ['type' => 'current']],
                    [memory_get_peak_usage(true), ['type' => 'peak']],
                ];
            });

        // Размер таблиц ClickHouse
        Prometheus::addGauge('clickhouse_table_size_bytes')
            ->label('table')
            ->label('database')
            ->value(function () {
                return self::getClickHouseTableSizes();
            });

        // Количество записей в таблицах ClickHouse
        Prometheus::addGauge('clickhouse_table_rows')
            ->label('table')
            ->label('database')
            ->value(function () {
                return self::getClickHouseTableRows();
            });
    }

    /**
     * Получить размер таблиц ClickHouse в байтах.
     */
    private static function getClickHouseTableSizes(): array
    {
        try {
            $results = DB::connection('clickhouse')
                ->select("
                    SELECT
                        table,
                        database,
                        total_bytes
                    FROM system.tables
                    WHERE database NOT IN ('system', 'INFORMATION_SCHEMA')
                ");

            return array_map(fn ($row) => [
                (int) ($row['total_bytes'] ?? 0),
                ['table' => $row['table'] ?? '', 'database' => $row['database'] ?? ''],
            ], $results);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Получить количество записей в таблицах ClickHouse.
     */
    private static function getClickHouseTableRows(): array
    {
        try {
            $results = DB::connection('clickhouse')
                ->select("
                    SELECT
                        table,
                        database,
                        total_rows
                    FROM system.tables
                    WHERE database NOT IN ('system', 'INFORMATION_SCHEMA')
                        AND total_rows > 0
                ");

            return array_map(fn ($row) => [
                (int) ($row['total_rows'] ?? 0),
                ['table' => $row['table'] ?? '', 'database' => $row['database'] ?? ''],
            ], $results);
        } catch (\Throwable $e) {
            return [];
        }
    }
}