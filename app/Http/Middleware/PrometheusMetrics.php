<?php

namespace App\Http\Middleware;

use App\Prometheus\MetricsRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrometheusMetrics
{
    /**
     * Сбор RPS и latency по эндпоинтам.
     *
     * Данные сохраняются в MetricsRegistry (через Laravel Cache),
     * откуда Prometheus забирает их при рендеринге /prometheus.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Не собираем метрики с самого эндпоинта /prometheus
        if ($request->path() === 'prometheus') {
            return $next($request);
        }

        $start = microtime(true);

        $response = $next($request);

        $duration = microtime(true) - $start;

        $route = $request->route();
        $routeName = $route ? $route->getName() : null;
        $path = $request->path();

        // Используем имя роута если есть, иначе path
        $routeLabel = $routeName ?: $path;
        $method = $request->method();
        $statusCode = (string) $response->getStatusCode();

        $labels = [
            'method' => $method,
            'route' => $routeLabel,
            'status' => $statusCode,
        ];

        // Инкрементируем счётчик запросов
        MetricsRegistry::incrementCounter('http_requests_total', $labels);

        // Записываем время ответа (последнее значение)
        MetricsRegistry::setGauge('http_request_duration_seconds', $labels, $duration);

        return $response;
    }
}