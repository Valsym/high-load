<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('clickhouse:init-tables')]
#[Description('Create ClickHouse tables (product_changes, product_stats_by_section) if they do not exist')]
class ClickHouseInitTables extends Command
{
    public function handle(): int
    {
        $host = env('CLICKHOUSE_HOST', 'clickhouse');
        $port = env('CLICKHOUSE_PORT', '8123');
        $user = env('CLICKHOUSE_USERNAME', 'default');
        $pass = env('CLICKHOUSE_PASSWORD', 'clickhouse');
        $db = env('CLICKHOUSE_DATABASE', 'default');

        $url = "http://{$host}:{$port}/?database={$db}";
        $auth = base64_encode("{$user}:{$pass}");

        $this->info('Creating ClickHouse tables...');

        $queries = [
            'product_changes' => <<<'SQL'
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
            SQL,

            'product_stats_by_section' => <<<'SQL'
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
            SQL,
        ];

        foreach ($queries as $name => $sql) {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $auth,
            ])->withoutVerifying()
                ->withBody($sql, 'text/plain')
                ->post($url);

            if ($response->successful()) {
                $this->info("  ✓ Table '{$name}' created successfully");
            } else {
                $this->error("  ✗ Table '{$name}' failed: {$response->body()}");
                return 1;
            }
        }

        $this->newLine();
        $this->info('All ClickHouse tables are ready.');
        return 0;
    }
}