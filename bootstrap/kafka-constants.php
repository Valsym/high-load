<?php

/**
 * Фикс совместимости для mateusjunges/laravel-kafka v2.11 с ext-rdkafka.
 *
 * laravel-kafka использует константу RD_KAFKA_PARTITION_UA без указания глобального
 * namespace (\RD_KAFKA_PARTITION_UA), поэтому PHP ищет её в текущем namespace (Junges\Kafka).
 * Определяем её там, если ext-rdkafka не делает этого автоматически.
 */

namespace Junges\Kafka;

if (!defined('RD_KAFKA_PARTITION_UA') && !defined('Junges\Kafka\RD_KAFKA_PARTITION_UA')) {
    define('Junges\Kafka\RD_KAFKA_PARTITION_UA', 0xFFFFFFFF);
}