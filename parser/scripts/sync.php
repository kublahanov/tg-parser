#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/Collector.php';

// Парсим аргументы командной строки
$options = getopt('', ['mode:', 'limit:', 'chat:']);

$mode = $options['mode'] ?? 'new'; // old или new
$maxMessages = intval($options['limit'] ?? 0); // 0 = без ограничений
$specificChat = $options['chat'] ?? null;

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

    // Получаем чаты для синхронизации
    if ($specificChat) {
        // Проверяем, существует ли чат
        $chat = $collector->getChatById($specificChat);

        if (!$chat) {
            echo "Chat with ID $specificChat not found. Add it first with add-chat.php\n";
            exit(1);
        }

        $chats = [$chat];
    } else {
        $chats = $collector->getChatsForSync();
    }

    if (empty($chats)) {
        echo "No chats to sync. Add some with: php add-chat.php <chat_identifier>\n";
        exit(0);
    }

    echo "Found " . count($chats) . " chats to sync\n";
    echo "Mode: $mode, Max messages: " . ($maxMessages ?: 'unlimited') . "\n";

    foreach ($chats as $chat) {
        try {
            $result = $collector->syncChat($chat['id'], $mode, $maxMessages);
            echo "Chat {$chat['title']}: +{$result['added']} messages\n";
        } catch (Exception $e) {
            echo "Error syncing chat {$chat['title']}: " . $e->getMessage() . "\n";
        }

        // Задержка между чатами
        sleep(Collector::SLEEP_TIME);
    }
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
