#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/Collector.php';

$config = [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'mysql',
        'port' => getenv('DB_PORT') ?: 3306,
        'dbname' => getenv('DB_NAME') ?: 'parser',
        'user' => getenv('DB_USER') ?: 'parser',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
    'api_url' => getenv('API_URL') ?: 'http://telegram_api:9503/api',
    'media_path' => getenv('MEDIA_PATH') ?: '/media',
];

$collector = new Collector($config['db'], $config['api_url'], $config['media_path']);

if ($argc < 2) {
    echo "Usage: php add-chat.php @channel_name\n";
    exit(1);
}

$peer = $argv[1];

$chatId = $collector->addChat($peer);

echo "Chat added with ID: $chatId\n";
