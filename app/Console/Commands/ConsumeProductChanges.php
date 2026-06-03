<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Junges\Kafka\Facades\Kafka;

#[Signature('products:consume-kafka')]
#[Description('Consume product changes from Kafka and write aggregated data to ClickHouse')]
class ConsumeProductChanges extends Command
{
    private string $clickhouseUrl;
    private string $clickhouseAuth;

    public function __construct()
    {
        parent::__construct();

        $host = env('CLICKHOUSE_HOST', 'clickhouse');
        $port = env('CLICKHOUSE_PORT', '8123');
        $user = env('CLICKHOUSE_USERNAME', 'default');
        $pass = env('CLICKHOUSE_PASSWORD', 'clickhouse');
        $db = env('CLICKHOUSE_DATABASE', 'default');

        $this->clickhouseUrl = "http://{$host}:{$port}/?database={$db}";
        $this->clickhouseAuth = base64_encode("{$user}:{$pass}");
    }

    public function handle()
    {
        $this->info('Starting Kafka consumer for product_changes...');
        $this->info('Listening for messages on topic: product_changes');
        $this->newLine();

        $processed = 0;

        $consumer = Kafka::consumer(['product_changes'])
            ->withHandler(function ($message) use (&$processed) {
                try {
                    $body = $message->getBody();

                    if (!isset($body['action']) || !isset($body['product'])) {
                        $this->warn('Invalid message structure, skipping');
                        return;
                    }

                    $action = $body['action'];
                    $product = $body['product'];

                    // 1. Вставляем запись в product_changes (лог событий)
                    $this->clickHouseQuery(
                        'INSERT INTO default.product_changes (product_id, name, code, price, total, section_id, section_name, action) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $product['product_id'],
                            $product['name'],
                            $product['code'],
                            $product['price'],
                            $product['total'],
                            $product['section_id'],
                            $product['section_name'],
                            $action,
                        ]
                    );

                    // 2. Обновляем агрегированную статистику по секции
                    $this->clickHouseQuery(
                        'INSERT INTO default.product_stats_by_section (section_id, section_name, products_count, avg_price, min_price, max_price, total_stock)
                         SELECT
                             p.section_id,
                             ?,
                             count(*),
                             avg(p.price),
                             min(p.price),
                             max(p.price),
                             sum(p.total)
                         FROM default.product_changes p
                         WHERE p.section_id = ?
                         GROUP BY p.section_id',
                        [$product['section_name'], $product['section_id']]
                    );

                    $this->line("Processed: {$action} product #{$product['product_id']} ({$product['name']})");
                    $processed++;

                } catch (\Exception $e) {
                    $this->error('Error processing message: ' . $e->getMessage());
                }
            })
            ->build();

        $consumer->consume();

        $this->newLine();
        $this->info("Done. Processed {$processed} messages.");
        return 0;
    }

    private function clickHouseQuery(string $query, array $params = []): void
    {
        $parts = explode('?', $query);
        $sql = '';
        foreach ($parts as $i => $part) {
            $sql .= $part;
            if (isset($params[$i])) {
                $value = $params[$i];
                if (is_null($value)) {
                    $sql .= 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $sql .= (string) $value;
                } else {
                    $sql .= "'" . str_replace("'", "\\'", (string) $value) . "'";
                }
            }
        }

        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $this->clickhouseAuth,
        ])->withoutVerifying()
            ->withBody($sql, 'text/plain')
            ->post($this->clickhouseUrl);

        if ($response->failed()) {
            throw new \RuntimeException(
                "ClickHouse query failed: {$response->body()}"
            );
        }
    }
}
