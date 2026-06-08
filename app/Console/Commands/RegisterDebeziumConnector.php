<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('debezium:register-connector')]
#[Description('Register Debezium PostgreSQL connector via Kafka Connect REST API')]
class RegisterDebeziumConnector extends Command
{
    private const CONNECT_URL = 'http://kafka-connect:8083';

    public function handle(): int
    {
        $configPath = base_path('docker/kafka-connect/debezium-product-connector.json');

        if (!file_exists($configPath)) {
            $this->error("Config file not found: {$configPath}");
            return 1;
        }

        $config = json_decode(file_get_contents($configPath), true);

        if (!$config || !isset($config['name'])) {
            $this->error('Invalid connector config JSON');
            return 1;
        }

        $connectorName = $config['name'];

        // 1. Проверяем, доступен ли Kafka Connect
        $this->info('Checking Kafka Connect availability...');

        try {
            $health = Http::timeout(5)->get(self::CONNECT_URL);
            if ($health->failed()) {
                $this->error("Kafka Connect is not available at " . self::CONNECT_URL);
                $this->warn("Response: " . $health->body());
                return 1;
            }
            $this->line("✓ Kafka Connect is available");
        } catch (\Exception $e) {
            $this->error("Cannot connect to Kafka Connect: " . $e->getMessage());
            return 1;
        }

        // 2. Проверяем, есть ли уже такой коннектор
        $this->info("Checking if connector '{$connectorName}' already exists...");

        $existing = Http::get(self::CONNECT_URL . "/connectors/{$connectorName}");

        if ($existing->successful()) {
            // Коннектор уже существует — обновляем конфигурацию
            $this->warn("Connector '{$connectorName}' already exists. Updating configuration...");

            $response = Http::put(
                self::CONNECT_URL . "/connectors/{$connectorName}/config",
                $config['config']
            );

            if ($response->successful()) {
                $this->info("✓ Connector '{$connectorName}' configuration updated successfully");
                $this->line("Response: " . json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error("Failed to update connector: " . $response->body());
                return 1;
            }
        } else {
            // Коннектора нет — создаём новый
            $this->info("Creating new connector '{$connectorName}'...");

            $response = Http::post(
                self::CONNECT_URL . "/connectors",
                $config
            );

            if ($response->successful()) {
                $this->info("✓ Connector '{$connectorName}' created successfully");
                $this->line("Response: " . json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error("Failed to create connector: " . $response->body());
                return 1;
            }
        }

        // 3. Показываем статус коннектора
        $this->newLine();
        $this->info("Connector status:");

        $status = Http::get(self::CONNECT_URL . "/connectors/{$connectorName}/status");

        if ($status->successful()) {
            $statusData = $status->json();
            $this->line("  Name:   {$statusData['name']}");
            $this->line("  State:  {$statusData['connector']['state']}");
            if (isset($statusData['tasks'])) {
                foreach ($statusData['tasks'] as $task) {
                    $this->line("  Task[{$task['id']}]: {$task['state']}");
                }
            }
        }

        $this->newLine();
        $this->info("Done. Debezium connector is now watching 'products' table.");
        $this->line("Changes are published to topic: dbserver1.public.products");

        return 0;
    }
}