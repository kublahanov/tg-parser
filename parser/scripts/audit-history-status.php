#!/usr/bin/env php
<?php

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: 'mysql',
    getenv('DB_PORT') ?: 3306,
    getenv('DB_NAME') ?: 'parser'
);

$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$maxQuery = $pdo->prepare('SELECT MAX(id) FROM messages WHERE chat_id = ?');
$paths = glob(__DIR__ . '/../var/history-audit/*.jsonl');

if (!$paths) {
    echo "Сверка ещё не запускалась.\n";
    exit(0);
}

foreach ($paths as $path) {
    $chatId = (int) basename($path, '.jsonl');
    $maxQuery->execute([$chatId]);
    $savedMaxId = (int) $maxQuery->fetchColumn();
    $pages = 0;
    $checked = 0;
    $new = 0;
    $gaps = 0;
    $cursor = 0;
    $complete = false;
    $file = fopen($path, 'r');

    while (($line = fgets($file)) !== false) {
        $entry = json_decode($line, true);

        if (!str_ends_with($line, "\n") || !is_array($entry) || !isset($entry['missing'])) {
            break;
        }

        $pages++;
        $checked += (int) $entry['checked'];
        $cursor = (int) $entry['last_id'];
        $complete = (bool) $entry['complete'];

        foreach ($entry['missing'] as $id) {
            if ($id > $savedMaxId) {
                $new++;
            } else {
                $gaps++;
            }
        }
    }

    fclose($file);

    echo "Чат $chatId: " . ($complete ? 'завершено' : 'в процессе') .
        ", страниц $pages, проверено $checked, новых $new, пропусков $gaps, последний ID $cursor.\n";
}
