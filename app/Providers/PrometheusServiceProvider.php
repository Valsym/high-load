<?php

namespace App\Providers;

use App\Http\Middleware\PrometheusMetrics;
use App\Prometheus\CustomMetrics;
use App\Prometheus\MetricsRegistry;
use Illuminate\Support\ServiceProvider;
use Spatie\Prometheus\Facades\Prometheus;

class PrometheusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем пользовательские метрики (ClickHouse, память)
        CustomMetrics::register();

        // HTTP-метрики: счётчик запросов (RPS)
        // setInitialValue() принимает Closure, который вызывается
        // при каждом рендеринге /prometheus и читает данные из кэша
        Prometheus::addCounter('http_requests_total')
            ->label('method')
            ->label('route')
            ->label('status')
            ->setInitialValue(function () {
                return MetricsRegistry::getCounterValues('http_requests_total');
            });

        // HTTP-метрики: время ответа (последнее значение)
        Prometheus::addGauge('http_request_duration_seconds')
            ->label('method')
            ->label('route')
            ->label('status')
            ->value(function () {
                return MetricsRegistry::getGaugeValues('http_request_duration_seconds');
            });
    }

    /**
     * После загрузки всех сервисов регистрируем middleware
     * для сбора RPS и latency на всех маршрутах.
     */
    public function boot(): void
    {
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('api', PrometheusMetrics::class);
        $router->pushMiddlewareToGroup('web', PrometheusMetrics::class);
    }
}
