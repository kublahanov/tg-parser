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
    'api_url' => getenv('API_URL') ?: 'http://telegram-api:9503/api',
    'media_path' => getenv('MEDIA_PATH') ?: '/media',
];

$collector = new Collector($config['db'], $config['api_url'], $config['media_path']);

// Получаем список всех чатов из БД
$pdo = new PDO(
    "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
    $config['db']['user'],
    $config['db']['password']
);

$stmt = $pdo->query("SELECT id FROM chats WHERE is_archived = 0");

while ($chat = $stmt->fetch()) {
    try {
        $result = $collector->syncChat($chat['id'], 100);
        echo date('Y-m-d H:i:s') . " - Chat {$chat['id']}: +{$result['added']} messages\n";
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n";
    }

    // Задержка между чатами
    sleep(2);
}
