<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('debezium:status')]
#[Description('Show Debezium connector status from Kafka Connect REST API')]
class DebeziumConnectorStatus extends Command
{
    private const CONNECT_URL = 'http://kafka-connect:8083';

    public function handle(): int
    {
        // Список всех коннекторов
        $this->info('Fetching connectors list...');

        try {
            $response = Http::timeout(5)->get(self::CONNECT_URL . '/connectors');
            if ($response->failed()) {
                $this->error("Kafka Connect not available: " . $response->body());
                return 1;
            }

            $connectors = $response->json();

            if (empty($connectors)) {
                $this->warn('No connectors registered');
                return 0;
            }

            foreach ($connectors as $name) {
                $this->line("────────────────────────────────────────");
                $this->info("Connector: {$name}");

                $status = Http::get(self::CONNECT_URL . "/connectors/{$name}/status");
                if ($status->successful()) {
                    $data = $status->json();
                    $this->line("  State:  {$data['connector']['state']}");
                    foreach ($data['tasks'] ?? [] as $task) {
                        $this->line("  Task[{$task['id']}]: {$task['state']}");
                        if (!empty($task['trace'])) {
                            $this->error("  Error: {$task['trace']}");
                        }
                    }
                }

                // Показываем топики, которые слушает коннектор
                $config = Http::get(self::CONNECT_URL . "/connectors/{$name}/config");
                if ($config->successful()) {
                    $cfg = $config->json();
                    $this->line("  Table:  " . ($cfg['table.include.list'] ?? 'N/A'));
                    $this->line("  Topic:  " . ($cfg['database.server.name'] ?? 'N/A') . ".public.*");
                }
            }

            $this->newLine();
            $this->info('Done');

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}