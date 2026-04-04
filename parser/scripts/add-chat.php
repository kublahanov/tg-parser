#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/Collector.php';

$config = [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'mysql',
        'port' => getenv('DB_PORT') ?: 3306,
        'dbname' => getenv('DB_NAME') ?: 'parser',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
    'api_url' => getenv('API_URL') ?: 'http://telegram-api:9503/api',
    'media_path' => getenv('MEDIA_PATH') ?: '/media',
];

if ($argc < 2) {
    echo "Использовать в виде: php add-chat.php <chat_identifier>\n";
    echo "Примеры:\n";
    echo "  php add-chat.php @durov\n";
    echo "  php add-chat.php https://t.me/durov\n";
    echo "  php add-chat.php -1001234567890\n";
    exit(1);
}

$peer = $argv[1];

try {
    $collector = new Collector($config['db'], $config['api_url'], $config['media_path']);
    $chatId = $collector->addChat($peer);
    echo "Чат добавлен успешно. ID: $chatId\n";
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
