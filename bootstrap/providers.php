<?php

use App\Providers\AppServiceProvider;
use App\Providers\PrometheusServiceProvider;
use App\Providers\RouteServiceProvider;
use PhpClickHouseLaravel\ClickhouseServiceProvider;

return [
    AppServiceProvider::class,
    PrometheusServiceProvider::class,
    RouteServiceProvider::class,
    ClickhouseServiceProvider::class,
];
