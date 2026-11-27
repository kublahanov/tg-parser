#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/Collector.php';

class HistoryAuditCollector extends Collector
{
    public function fetchHistoryPage(int $chatId, int $offsetId): array
    {
        return $this->apiRequest('messages.getHistory', [
            'peer' => $chatId,
            'limit' => self::MESSAGE_LIMIT,
            'offset_id' => $offsetId,
        ]);
    }
}

$options = getopt('', ['chat:', 'max-pages:']);
$maxPages = isset($options['max-pages']) ? max(1, (int) $options['max-pages']) : PHP_INT_MAX;

$db = [
    'host' => getenv('DB_HOST') ?: 'mysql',
    'port' => getenv('DB_PORT') ?: 3306,
    'dbname' => getenv('DB_NAME') ?: 'parser',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $db['host'],
        $db['port'],
        $db['dbname']
    );

    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $collector = new HistoryAuditCollector($db, getenv('API_URL') ?: 'http://api:9503/api');
    $stateDir = __DIR__ . '/../var/history-audit';

    if (!is_dir($stateDir) && !mkdir($stateDir, 0755, true) && !is_dir($stateDir)) {
        throw new RuntimeException("Не удалось создать каталог $stateDir.");
    }

    if (isset($options['chat'])) {
        $chatIds = [(int) $options['chat']];
    } else {
        $chatIds = $pdo->query('SELECT id FROM chats WHERE is_archived = 0 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    foreach ($chatIds as $chatId) {
        $path = $stateDir . '/' . $chatId . '.jsonl';
        $state = fopen($path, 'c+');

        if ($state === false || !flock($state, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException("Сверка чата $chatId уже выполняется или файл недоступен.");
        }

        $lastId = 0;
        $pages = 0;
        $checked = 0;
        $missingTotal = 0;
        $complete = false;
        $validEnd = 0;

        while (($line = fgets($state)) !== false) {
            $entry = json_decode($line, true);

            if (
                !str_ends_with($line, "\n")
                || !is_array($entry)
                || !isset($entry['last_id'], $entry['checked'], $entry['missing'], $entry['complete'])
            ) {
                break;
            }

            $lastId = (int) $entry['last_id'];
            $pages++;
            $checked += (int) $entry['checked'];
            $missingTotal += count($entry['missing']);
            $complete = (bool) $entry['complete'];
            $validEnd = ftell($state);
        }

        ftruncate($state, $validEnd);
        fseek($state, $validEnd);
        echo "Чат $chatId: возобновление после ID $lastId, проверено $checked, отсутствует $missingTotal.\n";

        if ($complete) {
            echo "Чат $chatId: сверка уже завершена.\n";
            fclose($state);
            continue;
        }

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $collector->fetchHistoryPage((int) $chatId, $lastId);
            $messages = $response['messages'] ?? [];
            $nextId = $lastId;
            $ids = [];

            foreach ($messages as $message) {
                $id = (int) ($message['id'] ?? 0);

                if ($id <= 0 || ($lastId > 0 && $id >= $lastId) || ($nextId > 0 && $id >= $nextId)) {
                    throw new RuntimeException("Чат $chatId: история не продвинулась к старым сообщениям.");
                }

                $nextId = $id;

                if (($message['_'] ?? '') !== 'messageEmpty' && isset($message['date'])) {
                    $ids[] = $id;
                }
            }

            $missing = [];

            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $query = $pdo->prepare("SELECT id FROM messages WHERE chat_id = ? AND id IN ($placeholders)");
                $query->execute(array_merge([(int) $chatId], $ids));
                $existing = array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);

                foreach ($ids as $id) {
                    if (!isset($existing[$id])) {
                        $missing[] = $id;
                    }
                }
            }

            $entry = [
                'last_id' => $nextId,
                'checked' => count($ids),
                'missing' => $missing,
                'complete' => !$messages,
            ];

            $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";

            if (fwrite($state, $line) !== strlen($line) || !fflush($state)) {
                throw new RuntimeException("Не удалось записать прогресс сверки чата $chatId.");
            }

            $lastId = $nextId;
            $pages++;
            $checked += count($ids);
            $missingTotal += count($missing);

            if (!$messages || $pages % 10 === 0 || $missing) {
                echo "Чат $chatId: страниц $pages, проверено $checked, отсутствует $missingTotal, ID $lastId.\n";
            }

            if (!$messages) {
                echo "Чат $chatId: сверка завершена.\n";
                break;
            }

            usleep(500000);
        }

        fclose($state);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Ошибка сверки: {$e->getMessage()}\n");
    exit(1);
}
