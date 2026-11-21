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

    if (isset($options['chat'])) {
        $chat = $collector->getChatById($options['chat']);

        if (!$chat) {
            echo "Чат с ID {$options['chat']} не найден. Его необходимо добавить с помощью команды: php add-chat.php.\n";
            exit(1);
        }

        $chats = [$chat];
    } else {
        $chats = $collector->getChatsForSync();
    }

    if (empty($chats)) {
        echo "Чатов для синхронизации не найдено.\n";
        exit(0);
    }

    echo "Найдено чатов: " . count($chats) . ".\n";

    foreach ($chats as $chat) {
        echo "- чат \"{$chat['title']}\" (ID: {$chat['id']})\n";
    }

    $failed = false;

    foreach ($chats as $chat) {
        try {
            $collector->syncChat($chat['id']);

            if ($chat['peer_type'] === 'supergroup' && (bool) $chat['is_forum']) {
                $collector->syncForumTopics($chat['id']);
            }
        } catch (Throwable $e) {
            echo "Ошибка синхронизации чата \"{$chat['title']}\" (ID: {$chat['id']}): " . $e->getMessage() . ".\n";
            $failed = true;
        }

        sleep(Collector::SLEEP_TIME);
    }

    if ($failed) {
        exit(1);
    }
} catch (Throwable $e) {
    echo "Критическая ошибка: " . $e->getMessage() . ".\n";
    exit(1);
}
