<?php

namespace App\Prometheus;

use Illuminate\Support\Facades\Cache;

/**
 * Персистентное хранилище метрик для экспорта в Prometheus.
 *
 * Использует Laravel Cache (file) для хранения значений между запросами,
 * что критически важно для PHP-FPM, где каждый запрос — отдельный процесс.
 *
 * Middleware пишет данные через incrementCounter/setGauge,
 * а Prometheus-коллекторы читают через getCounterValues/getGaugeValues
 * во время рендеринга /prometheus.
 */
class MetricsRegistry
{
    private const CACHE_KEY = 'prometheus_metrics_data';
    private const CACHE_STORE = 'prometheus_metrics';

    /**
     * Инкрементировать счётчик.
     */
    public static function incrementCounter(string $name, array $labels, int $by = 1): void
    {
        $data = self::load();
        $key = self::buildKey($name, $labels);
        $data['counters'][$key] = ($data['counters'][$key] ?? 0) + $by;
        self::save($data);
    }

    /**
     * Установить значение gauge.
     */
    public static function setGauge(string $name, array $labels, float $value): void
    {
        $data = self::load();
        $key = self::buildKey($name, $labels);
        $data['gauges'][$key] = $value;
        self::save($data);
    }

    /**
     * Получить все значения счётчиков для Prometheus.
     *
     * @return array<array{float, array<string, string>}>
     */
    public static function getCounterValues(string $name): array
    {
        $data = self::load();
        $result = [];
        foreach ($data['counters'] as $key => $value) {
            [$storedName, $storedLabels] = self::parseKey($key);
            if ($storedName === $name) {
                $result[] = [$value, $storedLabels];
            }
        }
        return $result;
    }

    /**
     * Получить все значения gauges для Prometheus.
     *
     * @return array<array{float, array<string, string>}>
     */
    public static function getGaugeValues(string $name): array
    {
        $data = self::load();
        $result = [];
        foreach ($data['gauges'] as $key => $value) {
            [$storedName, $storedLabels] = self::parseKey($key);
            if ($storedName === $name) {
                $result[] = [$value, $storedLabels];
            }
        }
        return $result;
    }

    /**
     * Очистить все накопленные метрики.
     */
    public static function clear(): void
    {
        Cache::store(self::CACHE_STORE)->forget(self::CACHE_KEY);
    }

    private static function load(): array
    {
        return Cache::store(self::CACHE_STORE)->get(self::CACHE_KEY, [
            'counters' => [],
            'gauges' => [],
        ]);
    }

    private static function save(array $data): void
    {
        // TTL 24 часа — данные автоматически устаревают,
        // чтобы не забивать кэш мусором
        Cache::store(self::CACHE_STORE)->put(self::CACHE_KEY, $data, now()->addHours(24));
    }

    private static function buildKey(string $name, array $labels): string
    {
        return $name . ':' . json_encode($labels);
    }

    /**
     * @return array{string, array<string, string>}
     */
    private static function parseKey(string $key): array
    {
        $colonPos = strpos($key, ':');
        $name = substr($key, 0, $colonPos);
        $labels = json_decode(substr($key, $colonPos + 1), true);
        return [$name, $labels];
    }
}