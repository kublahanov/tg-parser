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
    'media_path' => '/media',
];

$pdo = new PDO(
    "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['dbname']}",
    $config['db']['user'],
    $config['db']['password']
);

// Параметры
$mediaId = $argv[1] ?? null;
$action = $argv[2] ?? 'info';

if (!$mediaId) {
    echo "Usage: php show-media.php <media_id> [info|open|path]\n";
    exit(1);
}

// Получаем информацию о медиа
$stmt = $pdo->prepare("
    SELECT 
        m.*,
        msg.date as message_date,
        msg.text as message_text
    FROM media m
    JOIN messages msg ON m.message_chat_id = msg.chat_id AND m.message_id = msg.id
    WHERE m.id = ?
");

$stmt->execute([$mediaId]);
$media = $stmt->fetch();

if (!$media) {
    echo "Media not found\n";
    exit(1);
}

$fullPath = $config['media_path'] . '/' . $media['file_path'];

switch ($action) {
    case 'info':
        echo "ID: {$media['id']}\n";
        echo "Type: {$media['media_type']}\n";
        echo "Message date: {$media['message_date']}\n";
        echo "MIME: {$media['mime_type']}\n";
        echo "Size: " . round($media['file_size'] / 1024, 2) . " KB\n";
        echo "Path: {$media['file_path']}\n";
        echo "Full path: $fullPath\n";
        echo "Downloaded: " . ($media['downloaded'] ? 'Yes' : 'No') . "\n";

        break;

    case 'path':
        echo $fullPath . "\n";

        break;

    case 'open':
        if (!file_exists($fullPath)) {
            echo "File not found: $fullPath\n";
            exit(1);
        }

        // Определяем команду для открытия
        $cmd = '';
        switch ($media['media_type']) {
            case 'photo':
                $cmd = "xdg-open"; // Linux
                break;
            case 'video':
                $cmd = "vlc"; // или xdg-open
                break;
            case 'audio':
                $cmd = "vlc";
                break;
            default:
                $cmd = "xdg-open";
        }

        system("$cmd \"$fullPath\"");
        echo "Opening: $fullPath\n";

        break;
}
