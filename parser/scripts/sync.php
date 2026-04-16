#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/Collector.php';

// Парсим аргументы командной строки
// $options = getopt('', ['limit:', 'chat:']);
$options = getopt('', ['chat:']);

// $maxMessages = intval($options['limit'] ?? 0); // 0 = без ограничений
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
            echo "Чат с ID $specificChat не найден. Его необходимо добавить с помощью команды: php add-chat.php.\n";
            exit(1);
        }

        $chats = [$chat];
    } else {
        $chats = $collector->getChatsForSync();
    }

    if (empty($chats)) {
        echo "Чатов для синхронизации не найдено. Добавьте чат с помощью команды: php add-chat.php <chat_identifier>.\n";
        exit(0);
    }

    echo "Найдено чатов: " . count($chats) . ".\n";

    foreach ($chats as $chat) {
        echo "- чат \"{$chat['title']}\" (ID: {$chat['id']})\n";
    }

    // echo "Лимит сообщений: $maxMessages.\n";

    foreach ($chats as $chat) {
        try {
            // $collector->syncChat($chat['id'], $maxMessages);
            $collector->syncChat($chat['id']);
        } catch (Exception $e) {
            echo "Ошибка синхронизации чата \"{$chat['title']}\" (ID: {$chat['id']}): " . $e->getMessage() . ".\n";
        }

        // Задержка между чатами
        sleep(Collector::SLEEP_TIME);
    }
} catch (Exception $e) {
    echo "Критическая ошибка: " . $e->getMessage() . ".\n";
    exit(1);
}
