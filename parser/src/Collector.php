<?php

/**
 * Класс для сбора данных из Telegram.
 */
class Collector
{
    public const MESSAGE_LIMIT = 20;
    public const SLEEP_TIME = 10;

    private $pdo;
    private $apiUrl;
    private $mediaBasePath;

    /**
     * @param array $dbConfig Параметры подключения к БД
     * @param string $apiUrl URL TelegramApiServer (http://api:9503/api)
     * @param string $mediaPath Путь для сохранения медиа
     */
    public function __construct($dbConfig, $apiUrl, $mediaPath = '/media')
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->mediaBasePath = rtrim($mediaPath, '/');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'],
            $dbConfig['port'] ?? 3306,
            $dbConfig['dbname']
        );

        $this->pdo = new PDO(
            $dsn,
            $dbConfig['user'],
            $dbConfig['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            ]
        );
    }

    /**
     * Добавление чата для мониторинга.
     *
     * @param $peer
     * @return mixed
     * @throws Exception
     */
    public function addChat($peer)
    {
        // Очищаем входной параметр
        $peer = trim($peer);

        // Если передана полная ссылка, извлекаем username
        if (strpos($peer, 'https://t.me/') === 0) {
            $peer = str_replace('https://t.me/', '', $peer);
        }

        // Добавляем @ если его нет и это не числовой ID
        if (!str_starts_with($peer, '@') && !is_numeric($peer) && !str_starts_with($peer, '-100')) {
            $peer = '@' . $peer;
        }

        echo "Looking up: $peer\n";

        // Используем API TelegramApiServer [citation:1]
        $chatInfo = $this->apiRequest('getInfo', ['id' => $peer]);

        // Проверяем структуру ответа
        if (isset($chatInfo['success']) && $chatInfo['success'] === true && isset($chatInfo['response']['Chat'])) {
            $chat = $chatInfo['response']['Chat'];
        } elseif (isset($chatInfo['Chat'])) {
            $chat = $chatInfo['Chat'];
        } elseif (isset($chatInfo['response']['channel_id'])) {
            // Альтернативный формат для каналов
            $chat = [
                'id' => $chatInfo['response']['channel_id'],
                'title' => $chatInfo['response']['Chat']['title'] ?? 'Unknown',
                'username' => $chatInfo['response']['Chat']['username'] ?? null,
                'broadcast' => true
            ];
        } else {
            throw new Exception("Не удалось получить информацию о чате: " . json_encode($chatInfo));
        }

        // Определяем тип чата
        if (isset($chat['broadcast']) && $chat['broadcast']) {
            $peerType = 'channel';
        } elseif (isset($chat['megagroup']) && $chat['megagroup']) {
            $peerType = 'supergroup';
        } elseif (isset($chat['chat_id'])) {
            $peerType = 'chat';
        } else {
            $peerType = 'group';
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO chats (id, peer_type, username, title, about, participants_count)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                about = VALUES(about),
                participants_count = VALUES(participants_count)
        ");

        $stmt->execute([
            $chat['id'],
            $peerType,
            $chat['username'] ?? null,
            $chat['title'] ?? 'Unknown',
            $chat['about'] ?? null,
            $chat['participants_count'] ?? null,
        ]);

        echo "Chat added: {$chat['title']} (ID: {$chat['id']})\n";

        return $chat['id'];
    }

    /**
     * Синхронизация сообщений чата.
     *
     * @param $chatId
     * @param $limit
     * @return array|int[]
     * @throws Exception
     */
    public function syncChat($chatId, $limit = self::MESSAGE_LIMIT)
    {
        // Получаем информацию о чате
        $stmt = $this->pdo->prepare("
            SELECT last_sync_id, title FROM chats WHERE id = ?
        ");

        $stmt->execute([$chatId]);
        $chat = $stmt->fetch();

        if (!$chat) {
            throw new Exception("Chat ID $chatId not found in database");
        }

        $lastSyncId = $chat['last_sync_id'] ?? 0;

        $syncType = $lastSyncId ? 'incremental' : 'full';

        // Логируем начало синхронизации
        $logId = $this->startSyncLog($chatId, $syncType);

        $messagesAdded = 0;
        $mediaDownloaded = 0;
        $maxId = $lastSyncId;

        try {
            echo "Syncing {$chat['title']}...\n";

            if ($lastSyncId > 0) {
                // ИНКРЕМЕНТАЛЬНАЯ СИНХРОНИЗАЦИЯ (новые сообщения)
                echo "Incremental sync (from ID $lastSyncId)\n";

                $params = [
                    'peer' => $chatId,
                    'limit' => $limit,
                    'min_id' => $lastSyncId + 1
                ];

                $response = $this->apiRequest('messages.getHistory', $params);
                $messages = $response['messages'] ?? [];

                foreach ($messages as $msg) {
                    $this->saveMessage($chatId, $msg);
                    $maxId = max($maxId, $msg['id']);
                    $messagesAdded++;

                    if (isset($msg['media'])) {
                        if ($this->processMedia($chatId, $msg['id'], $msg['media'])) {
                            $mediaDownloaded++;
                        }
                    }
                }

            } else {
                // ПОЛНАЯ СИНХРОНИЗАЦИЯ (загружаем ВСЕ сообщения от новых к старым)
                echo "Full sync (loading all messages)\n";

                $offsetId = 0;
                $hasMore = true;
                $totalLoaded = 0;

                while ($hasMore) {
                    $params = [
                        'peer' => $chatId,
                        'limit' => $limit,
                        'offset_id' => $offsetId
                    ];

                    $response = $this->apiRequest('messages.getHistory', $params);
                    $messages = $response['messages'] ?? [];

                    if (empty($messages)) {
                        $hasMore = false;
                        break;
                    }

                    foreach ($messages as $msg) {
                        $this->saveMessage($chatId, $msg);
                        $maxId = max($maxId, $msg['id']);
                        $messagesAdded++;
                        $totalLoaded++;

                        if (isset($msg['media'])) {
                            if ($this->processMedia($chatId, $msg['id'], $msg['media'])) {
                                $mediaDownloaded++;
                            }
                        }

                        // Прогресс каждые 10 сообщений
                        if ($totalLoaded % 10 == 0) {
                            echo "  Progress: $totalLoaded messages\n";
                        }
                    }

                    // Получаем ID самого старого сообщения в этой пачке
                    $lastMessage = end($messages);
                    $offsetId = $lastMessage['id'];

                    // Небольшая задержка между запросами
                    echo "Sleeping for " . self::SLEEP_TIME . " seconds...\n";
                    usleep(self::SLEEP_TIME * 1000000); // 10 секунд
                }
            }

            // Обновляем last_sync_id
            if ($maxId > $lastSyncId) {
                $stmt = $this->pdo->prepare("
                    UPDATE chats SET last_sync_id = ? WHERE id = ?
                ");

                $stmt->execute([$maxId, $chatId]);
            }

            echo "Done! Added $messagesAdded messages, $mediaDownloaded media files\n";

            $this->finishSyncLog($logId, 'completed', $messagesAdded, $mediaDownloaded);
        } catch (Exception $e) {
            $this->finishSyncLog($logId, 'failed', $messagesAdded, $mediaDownloaded, $e->getMessage());
            throw $e;
        }

        return [
            'added' => $messagesAdded,
            'media' => $mediaDownloaded,
            'last_id' => $maxId
        ];
    }

    /**
     * Сохранение сообщения.
     *
     * @param $chatId
     * @param $msg
     * @return void
     */
    private function saveMessage($chatId, $msg)
    {
        // 1. Базовые поля
        $messageId = $msg['id'] ?? null;

        if (!$messageId) {
            echo "Warning: Message without ID, skipping\n";
            return;
        }

        $text = $msg['message'] ?? '';

        // 2. Дата
        $date = isset($msg['date'])
            ? date('Y-m-d H:i:s', $msg['date'])
            : null
        ;

        $editDate = isset($msg['edit_date'])
            ? date('Y-m-d H:i:s', $msg['edit_date'])
            : null
        ;

        // 3. From_id может быть в разных форматах
        $fromId = null;

        if (isset($msg['from_id'])) {
            if (is_array($msg['from_id'])) {
                $fromId = $msg['from_id']['user_id'] ?? $msg['from_id']['channel_id'] ?? null;
            } else {
                $fromId = $msg['from_id'];
            }
        }

        // 4. Reply to
        $replyTo = null;
        $topicId = null;

        if (isset($msg['reply_to'])) {
            $replyTo = $msg['reply_to']['reply_to_msg_id'] ?? null;
            $topicId = $msg['reply_to']['reply_to_top_id'] ?? null; // для форумов!
        }

        // 5. Медиа
        $hasMedia = isset($msg['media']) ? 1 : 0;

        // 6. Статистика
        $views = $msg['views'] ?? null;
        $forwards = $msg['forwards'] ?? null;

        // 7. Сохраняем в БД
        $stmt = $this->pdo->prepare("
            INSERT INTO messages (
                id, chat_id, topic_id, from_id, date, edit_date,
                text, has_media, views, forwards,
                reply_to_msg_id, post_author, raw_data
            )
            VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                views = VALUES(views),
                forwards = VALUES(forwards),
                edit_date = VALUES(edit_date),
                text = VALUES(text)
        ");

        $stmt->execute([
            $messageId,
            $chatId,
            $topicId,
            $fromId,
            $date,
            $editDate,
            $text,
            $hasMedia,
            $views,
            $forwards,
            $replyTo,
            $msg['post_author'] ?? null,
            json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        ]);
    }

    /**
     * Обработка медиа.
     *
     * @param $chatId
     * @param $messageId
     * @param $media
     * @return bool
     */
    private function processMedia($chatId, $messageId, $media)
    {
        // Определяем тип медиа
        $mediaType = $this->detectMediaType($media);

        if (!$mediaType) {
            return false;
        }

        $fileInfo = $this->extractFileInfo($media, $mediaType);

        if (!$fileInfo) {
            return false;
        }

        // Сохраняем запись о медиа
        $stmt = $this->pdo->prepare("
            INSERT INTO media (
                message_chat_id, message_id, media_type, file_id, file_unique_id,
                file_size, mime_type, file_name, width, height, duration
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $chatId,
            $messageId,
            $mediaType,
            $fileInfo['id'] ?? '',
            $fileInfo['unique_id'] ?? uniqid(),
            $fileInfo['size'] ?? null,
            $fileInfo['mime'] ?? null,
            $fileInfo['name'] ?? null,
            $fileInfo['width'] ?? null,
            $fileInfo['height'] ?? null,
            $fileInfo['duration'] ?? null
        ]);

        // Скачиваем файл через API [citation:1]
        $this->downloadMedia($this->pdo->lastInsertId(), $media);

        return true;
    }

    /**
     * Скачивание медиафайла.
     *
     * @param $mediaId
     * @param $media
     * @return void
     */
    private function downloadMedia($mediaId, $media)
    {
        try {
            // Формируем путь для сохранения
            $datePath = date('Y/m/d');
            $saveDir = "{$this->mediaBasePath}/{$datePath}";

            if (!is_dir($saveDir)) {
                if (!mkdir($saveDir, 0755, true) && !is_dir($saveDir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $saveDir));
                }
            }

            $fileName = $mediaId . '_' . uniqid('', true) . '.bin';
            $filePath = "{$datePath}/{$fileName}";
            $fullPath = "{$this->mediaBasePath}/{$filePath}";

            // Вызываем API для скачивания [citation:1]
            $ch = curl_init("{$this->apiUrl}/downloadToResponse");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['media' => $media]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);

            $fileContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $fileContent) {
                file_put_contents($fullPath, $fileContent);

                // Обновляем запись в БД
                $stmt = $this->pdo->prepare("
                    UPDATE media
                    SET file_path = ?, downloaded = 1
                    WHERE id = ?
                ");

                $stmt->execute([$filePath, $mediaId]);
            }
        } catch (Exception $e) {
            error_log("Download failed: " . $e->getMessage());
        }
    }

    /**
     * Определение типа медиа.
     *
     * @param $media
     * @return string|null
     */
    private function detectMediaType($media)
    {
        $types = [
            'messageMediaPhoto' => 'photo',
            'messageMediaDocument' => 'document',
            'messageMediaVideo' => 'video',
            'messageMediaAudio' => 'audio',
            'messageMediaVoice' => 'voice',
            'messageMediaSticker' => 'sticker'
        ];

        return $types[$media['_'] ?? ''] ?? null;
    }

    /**
     * Извлечение информации о файле.
     *
     * @param $media
     * @param $type
     * @return array|null[]
     */
    private function extractFileInfo($media, $type)
    {
        if ($type === 'photo') {
            $sizes = $media['sizes'] ?? [];
            $maxSize = end($sizes);

            return [
                'id' => $media['id'] ?? null,
                'unique_id' => $media['access_hash'] ?? null,
                'size' => $maxSize['size'] ?? null,
                'width' => $maxSize['w'] ?? null,
                'height' => $maxSize['h'] ?? null
            ];
        }

        if (in_array($type, ['document', 'video', 'audio', 'voice', 'sticker'])) {
            $doc = $media['document'] ?? $media;
            $fileName = null;

            foreach ($doc['attributes'] ?? [] as $attr) {
                if (($attr['_'] ?? '') === 'documentAttributeFilename') {
                    $fileName = $attr['file_name'] ?? null;
                    break;
                }
            }

            return [
                'id' => $doc['id'] ?? null,
                'unique_id' => $doc['access_hash'] ?? null,
                'size' => $doc['size'] ?? null,
                'mime' => $doc['mime_type'] ?? null,
                'name' => $fileName,
                'duration' => $doc['duration'] ?? null
            ];
        }

        return [];
    }

    /**
     * Запрос к TelegramApiServer [citation:1].
     *
     * @param $method
     * @param $params
     * @return mixed
     * @throws Exception
     */
    private function apiRequest($method, $params = [])
    {
        $url = "{$this->apiUrl}/{$method}";

        // Формируем query string в формате data[peer]=...
        if (!empty($params)) {
            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $url .= '?' . $query;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TelegramParser/1.0');

        // var_dump(curl_getinfo($ch));
        // exit;

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $data = json_decode($response, true);

            // Проверяем на flood wait (429 или FLOOD_WAIT в ошибках)
            if (
                isset($data['errors'][0]['message'])
                && strpos($data['errors'][0]['message'], 'FLOOD_WAIT') !== false
            ) {
                preg_match('/(\d+)/', $data['errors'][0]['message'], $matches);
                $waitTime = $matches[1] ?? 30;

                echo "⚠️ Flood control: waiting {$waitTime} seconds...\n";
                sleep($waitTime);

                // Повторяем запрос
                return $this->apiRequest($method, $params, $retryCount + 1);
            }

            throw new Exception("API error: HTTP {$httpCode}");
        }

        $data = json_decode($response, true);

        // TelegramApiServer возвращает данные в формате {"success":true,"response":...}
        if (isset($data['success']) && $data['success'] === true && isset($data['response'])) {
            return $data['response'];
        }

        return $data;
    }

    /**
     * Логирование начала синхронизации.
     *
     * @param $chatId
     * @param $type
     * @return false|string
     */
    private function startSyncLog($chatId, $type)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO sync_log (chat_id, sync_type, started_at, status)
            VALUES (?, ?, NOW(), 'running')
        ");

        $stmt->execute([$chatId, $type]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Завершение лога синхронизации.
     *
     * @param $logId
     * @param $status
     * @param $added
     * @param $media
     * @param $error
     * @return void
     */
    private function finishSyncLog($logId, $status, $added, $media, $error = null)
    {
        $stmt = $this->pdo->prepare("
            UPDATE sync_log
            SET
                status = ?, messages_added = ?, media_downloaded = ?,
                finished_at = NOW(), error_message = ?
            WHERE id = ?
        ");

        $stmt->execute([$status, $added, $media, $error, $logId]);
    }

    /**
     * Получение списка всех чатов для синхронизации.
     *
     * @return array
     */
    public function getChatsForSync()
    {
        $stmt = $this->pdo->query("
            SELECT id, title, last_sync_id
            FROM chats
            WHERE is_archived = 0
            ORDER BY last_sync_id ASC
        ");

        return $stmt->fetchAll();
    }
}
