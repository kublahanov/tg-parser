#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/Collector.php';

$options = getopt('', ['chat:', 'max-pages:']);

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: 'mysql',
    getenv('DB_PORT') ?: 3306,
    getenv('DB_NAME') ?: 'parser'
);

$dbConfig = [
    'host' => getenv('DB_HOST') ?: 'mysql',
    'port' => getenv('DB_PORT') ?: 3306,
    'dbname' => getenv('DB_NAME') ?: 'parser',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];

$pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$collector = new Collector($dbConfig, getenv('API_URL') ?: 'http://api:9503/api');

$maxPages = isset($options['max-pages']) ? max(1, (int) $options['max-pages']) : PHP_INT_MAX;

$chatIds = isset($options['chat'])
    ? [(int) $options['chat']]
    : $pdo->query('SELECT id FROM chats WHERE is_archived = 0 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

foreach ($chatIds as $chatId) {
    $maxStmt = $pdo->prepare('SELECT MAX(id) FROM messages WHERE chat_id = ?');

    $maxStmt->execute([$chatId]);
    $maxId = $maxStmt->fetchColumn();

    if (!$maxId) {
        continue;
    }

    $state = $pdo->prepare('SELECT offset_id, next_offset, is_complete FROM sender_sync_state WHERE chat_id = ?');

    $state->execute([$chatId]);
    $progress = $state->fetch() ?: ['offset_id' => 0, 'next_offset' => 0, 'is_complete' => 0];

    if ($progress['is_complete']) {
        continue;
    }

    $offset = (int) $progress['next_offset'];
    $offsetId = (int) $progress['offset_id'] ?: (int) $maxId + 1;

    for ($page = 0; $page < $maxPages; $page++) {
        $count = $collector->syncSendersPage($chatId, $offset, $offsetId);
        $offset += $count;
        $complete = $count < 100;

        $save = $pdo->prepare('INSERT INTO sender_sync_state (chat_id, offset_id, next_offset, is_complete)
            VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE
            offset_id = VALUES(offset_id),
            next_offset = VALUES(next_offset), is_complete = VALUES(is_complete)');

        $save->execute([$chatId, $offsetId, $offset, (int) $complete]);

        echo "Чат {$chatId}: обработано {$offset} сообщений" . ($complete ? ", готово" : '') . "\n";

        if ($complete) {
            break;
        }

        usleep(200000);
    }
}
