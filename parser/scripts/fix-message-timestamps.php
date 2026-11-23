#!/usr/bin/env php
<?php

// Восстанавливает TIMESTAMP-поля из Unix-времени в raw_data.
// Перед изменением сохраняет прежние эпохи в message_timestamp_backup_20261123.

$options = getopt('', ['apply', 'chat:']);
$apply = array_key_exists('apply', $options);

$db = [
    'host' => getenv('DB_HOST') ?: 'mysql',
    'port' => getenv('DB_PORT') ?: 3306,
    'name' => getenv('DB_NAME') ?: 'parser',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
        $db['user'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec("SET time_zone = '+00:00'");

    $chatIds = isset($options['chat'])
        ? [(int) $options['chat']]
        : $pdo->query('SELECT id FROM chats ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    $dateEpoch = "CAST(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.date')) AS UNSIGNED)";
    $editEpoch = "CAST(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.edit_date')) AS UNSIGNED)";
    $needsFix = "raw_data IS NOT NULL AND JSON_EXTRACT(raw_data, '$.date') IS NOT NULL
        AND (NOT (UNIX_TIMESTAMP(date) <=> $dateEpoch)
          OR NOT (UNIX_TIMESTAMP(edit_date) <=> $editEpoch))";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE chat_id = ? AND $needsFix");

    if ($apply) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS message_timestamp_backup_20261123 (
                chat_id BIGINT NOT NULL,
                id BIGINT NOT NULL,
                date_epoch BIGINT UNSIGNED NULL,
                edit_date_epoch BIGINT UNSIGNED NULL,
                PRIMARY KEY (chat_id, id)
            )
        ");

        $batchStmt = $pdo->prepare(
            'SELECT id FROM messages WHERE chat_id = ? AND id > ? ORDER BY id LIMIT 1000'
        );

        $backupStmt = $pdo->prepare("
            INSERT IGNORE INTO message_timestamp_backup_20261123
                (chat_id, id, date_epoch, edit_date_epoch)
            SELECT chat_id, id, UNIX_TIMESTAMP(date), UNIX_TIMESTAMP(edit_date)
            FROM messages
            WHERE chat_id = ? AND id > ? AND id <= ? AND $needsFix
        ");

        $updateStmt = $pdo->prepare("
            UPDATE messages
            SET date = FROM_UNIXTIME($dateEpoch),
                edit_date = FROM_UNIXTIME($editEpoch)
            WHERE chat_id = ? AND id > ? AND id <= ? AND $needsFix
        ");

        $topicsStmt = $pdo->prepare("
            UPDATE forum_topics ft
            SET last_message_date = (
                SELECT MAX(m.date)
                FROM messages m
                WHERE m.chat_id = ft.chat_id AND m.topic_id = ft.id
            )
            WHERE ft.chat_id = ?
        ");
    }

    foreach ($chatIds as $chatId) {
        $countStmt->execute([$chatId]);
        $pending = (int) $countStmt->fetchColumn();

        echo "Чат $chatId: дат к исправлению $pending.\n";

        if (!$apply) {
            continue;
        }

        $lastId = 0;
        $updated = 0;

        while (true) {
            $batchStmt->execute([$chatId, $lastId]);
            $ids = $batchStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!$ids) {
                break;
            }

            $nextId = (int) end($ids);
            $params = [$chatId, $lastId, $nextId];
            $pdo->beginTransaction();

            try {
                $backupStmt->execute($params);
                $updateStmt->execute($params);
                $updated += $updateStmt->rowCount();
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();

                throw $e;
            }

            $lastId = $nextId;

            if ($updated > 0 && $updated % 10000 < 1000) {
                echo "Чат $chatId: исправлено $updated дат.\n";
            }
        }

        $topicsStmt->execute([$chatId]);
        $countStmt->execute([$chatId]);
        $remaining = (int) $countStmt->fetchColumn();

        echo "Чат $chatId: исправлено $updated, осталось $remaining.\n";

        if ($remaining !== 0) {
            throw new RuntimeException("Не все даты чата $chatId удалось исправить.");
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Ошибка исправления дат: {$e->getMessage()}\n");
    exit(1);
}
