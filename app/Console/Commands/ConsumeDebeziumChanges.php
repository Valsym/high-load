<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Junges\Kafka\Facades\Kafka;

#[Signature('products:consume-debezium')]
#[Description('Consume product changes from Debezium CDC topic (dbserver1.public.products) and write to ClickHouse')]
class ConsumeDebeziumChanges extends Command
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

    public function handle(): int
    {
        $topic = 'dbserver1.public.products';

        $this->info('Starting Debezium CDC consumer...');
        $this->info("Listening for messages on topic: {$topic}");
        $this->newLine();

        $processed = 0;

        $consumer = Kafka::consumer([$topic])
            ->withHandler(function ($message) use (&$processed) {
                try {
                    $body = $message->getBody();

                    // Debezium формат: { "payload": { "op": "c|u|d", "before": {...}, "after": {...}, "source": {...} } }
                    // или flattened: { "op": "c", "before": null, "after": {...} }
                    $payload = $body['payload'] ?? $body;

                    if (!isset($payload['op'])) {
                        $this->warn('Invalid Debezium message structure (no op field), skipping');
                        return;
                    }

                    $op = $payload['op']; // c=create, u=update, d=delete, r=read (snapshot)
                    $after = $payload['after'] ?? null;
                    $before = $payload['before'] ?? null;

                    // Маппинг Debezium op -> наша action
                    $actionMap = [
                        'c' => 'created',
                        'r' => 'created',  // snapshot read = create
                        'u' => 'updated',
                        'd' => 'deleted',
                    ];

                    $action = $actionMap[$op] ?? 'unknown';

                    if ($op === 'd' && $before) {
                        // DELETE — берём данные из before
                        $product = $this->extractProduct($before);
                        $product['action'] = $action;

                        $this->insertProductChange($product);
                        $this->line("Processed: {$action} product #{$product['product_id']} ({$product['name']})");

                    } elseif (in_array($op, ['c', 'r', 'u']) && $after) {
                        // CREATE / UPDATE — берём данные из after
                        $product = $this->extractProduct($after);
                        $product['action'] = $action;

                        $this->insertProductChange($product);
                        $this->line("Processed: {$action} product #{$product['product_id']} ({$product['name']})");

                    } else {
                        $this->warn("Unhandled Debezium event: op={$op}, has_before=" . ($before ? 'yes' : 'no') . ", has_after=" . ($after ? 'yes' : 'no'));
                    }

                    $processed++;

                } catch (\Exception $e) {
                    $this->error('Error processing Debezium message: ' . $e->getMessage());
                }
            })
            ->build();

        $consumer->consume();

        $this->newLine();
        $this->info("Done. Processed {$processed} messages.");
        return 0;
    }

    /**
     * Извлечь плоский массив продукта из Debezium after/before.
     */
    private function extractProduct(array $data): array
    {
        return [
            'product_id'  => $data['id'] ?? 0,
            'name'        => $data['name'] ?? '',
            'code'        => $data['code'] ?? '',
            'price'       => (float) ($data['price'] ?? 0),
            'total'       => (int) ($data['total'] ?? 0),
            'section_id'  => (int) ($data['section_id'] ?? 0),
            'section_name' => '', // Debezium не знает section_name — будет заполнено отдельно
        ];
    }

    /**
     * Вставить запись в ClickHouse.
     */
    private function insertProductChange(array $product): void
    {
        // 1. Вставляем в product_changes
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
                $product['action'],
            ]
        );

        // 2. Обновляем агрегированную статистику по секции
        // NOTE: section_name не приходит из CDC, поэтому используем COALESCE
        $this->clickHouseQuery(
            'INSERT INTO default.product_stats_by_section (section_id, section_name, products_count, avg_price, min_price, max_price, total_stock)
             SELECT
                 p.section_id,
                 COALESCE(max(p.section_name), ?),
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