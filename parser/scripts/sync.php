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

try {
    $collector = new Collector($config['db'], $config['api_url'], $config['media_path']);

    $chats = $collector->getChatsForSync();

    if (empty($chats)) {
        echo "No chats to sync. Add some with: php add-chat.php <chat_identifier>\n";
        exit(0);
    }

    echo "Found " . count($chats) . " chats to sync\n";

    foreach ($chats as $chat) {
        try {
            $result = $collector->syncChat($chat['id'], 100);
            echo "Chat {$chat['title']}: +{$result['added']} messages\n";
        } catch (Exception $e) {
            echo "Error syncing chat {$chat['title']}: " . $e->getMessage() . "\n";
        }

        // Небольшая задержка между чатами
        sleep(2);
    }

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
