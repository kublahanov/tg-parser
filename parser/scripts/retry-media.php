#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/Collector.php';

$options = getopt('', ['chat:', 'limit:', 'after-id:']);

$db = [
    'host' => getenv('DB_HOST') ?: 'mysql',
    'port' => getenv('DB_PORT') ?: 3306,
    'dbname' => getenv('DB_NAME') ?: 'parser',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];

try {
    $collector = new Collector($db, getenv('API_URL') ?: 'http://api:9503/api', getenv('MEDIA_PATH') ?: '/media');

    $result = $collector->retryMissingMedia(
        isset($options['limit']) ? max(1, (int) $options['limit']) : 100,
        $options['chat'] ?? null,
        isset($options['after-id']) ? max(0, (int) $options['after-id']) : 0
    );

    echo "Попыток: {$result['attempted']}, скачано: {$result['downloaded']}, последний ID: {$result['last_id']}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Ошибка повторной загрузки: {$e->getMessage()}\n");
    exit(1);
}
