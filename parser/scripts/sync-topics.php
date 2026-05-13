#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/Collector.php';

$options = getopt('', ['chat:']);

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

try {
    $collector = new Collector($config['db'], $config['api_url'], $config['media_path']);

    $pdo = new PDO(
        "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['dbname']};charset=utf8mb4",
        $config['db']['user'],
        $config['db']['password']
    );

    if (isset($options['chat'])) {
        $chatId = $options['chat'];

        $chats = [[
            'id' => $chatId,
            'title' => "Чат $chatId",
        ]];
    } else {
        $stmt = $pdo->query("
            SELECT id, title
            FROM chats
            WHERE peer_type = 'supergroup' AND is_forum = 1
            ORDER BY id
        ");

        $chats = $stmt->fetchAll();
    }

    if (empty($chats)) {
        echo "Чаты типа \"Форум\" не найдены.\n";
        exit(0);
    }

    echo "Найдено чатов: " . count($chats) . ".\n";

    foreach ($chats as $chat) {
        try {
            $result = $collector->syncForumTopics($chat['id']);
            echo "- чат \"{$chat['title']}\" (ID: {$chat['id']}), добавлено заголовков: {$result['added']}.\n";
        } catch (Exception $e) {
            echo "Ошибка синхронизации чата \"{$chat['title']}\" (ID: {$chat['id']}): " . $e->getMessage() . ".\n";
        }

        sleep(2);
    }
} catch (Exception $e) {
    echo "Критическая ошибка: " . $e->getMessage() . ".\n";
    exit(1);
}
