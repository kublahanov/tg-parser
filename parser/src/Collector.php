<?php

/**
 * Класс для сбора данных из Telegram.
 */
class Collector
{
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
            ]
        );
    }

    /**
     * Добавление чата для мониторинга.
     */
    public function addChat($peer)
    {
        // Используем API TelegramApiServer [citation:1]
        $chatInfo = $this->apiRequest('getInfo', ['id' => $peer]);

        if (!$chatInfo || !isset($chatInfo['Chat'])) {
            throw new Exception("Чат не найден: $peer");
        }

        $chat = $chatInfo['Chat'];

        $stmt = $this->pdo->prepare("
            INSERT INTO chats (id, peer_type, username, title, about, participants_count)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                about = VALUES(about),
                participants_count = VALUES(participants_count)
        ");

        $peerType = $chat['broadcast']
            ? 'channel'
            : ($chat['megagroup'] ? 'supergroup' : 'group')
        ;

        $stmt->execute([
            $chat['id'],
            $peerType,
            $chat['username'] ?? null,
            $chat['title'] ?? 'Unknown',
            $chat['about'] ?? null,
            $chat['participants_count'] ?? null,
        ]);

        return $chat['id'];
    }

    /**
     * Инкрементальная синхронизация чата.
     */
    public function syncChat($chatId, $limit = 100)
    {
        // Получаем последний синхронизированный ID
        $stmt = $this->pdo->prepare("SELECT last_sync_id FROM chats WHERE id = ?");
        $stmt->execute([$chatId]);
        $chat = $stmt->fetch();
        $lastSyncId = $chat['last_sync_id'] ?? 0;

        $messagesAdded = 0;
        $maxId = $lastSyncId;

        // Загружаем историю сообщений через API [citation:1]
        $params = [
            'peer' => $chatId,
            'limit' => $limit,
            'min_id' => $lastSyncId + 1,
        ];

        $messages = $this->apiRequest('messages.getHistory', $params);

        if (empty($messages)) {
            return ['added' => 0, 'last_id' => $maxId];
        }

        foreach ($messages as $msg) {
            $this->saveMessage($chatId, $msg);
            $maxId = max($maxId, $msg['id']);
            $messagesAdded++;

            // Если есть медиа - скачиваем
            if (isset($msg['media'])) {
                $this->processMedia($chatId, $msg['id'], $msg['media']);
            }
        }

        // Обновляем last_sync_id
        if ($maxId > $lastSyncId) {
            $stmt = $this->pdo->prepare("UPDATE chats SET last_sync_id = ? WHERE id = ?");
            $stmt->execute([$maxId, $chatId]);
        }

        return [
            'added' => $messagesAdded,
            'last_id' => $maxId,
        ];
    }

    /**
     * Сохранение сообщения.
     */
    private function saveMessage($chatId, $msg)
    {
        $text = $msg['message'] ?? $msg['text'] ?? '';

        $date = isset($msg['date']) ? date('Y-m-d H:i:s', $msg['date']) : null;

        $replyTo = $msg['reply_to']['reply_to_msg_id'] ?? $msg['reply_to_msg_id'] ?? null;

        $topicId = $msg['reply_to']['reply_to_top_id'] ?? null;

        $stmt = $this->pdo->prepare("
            INSERT INTO messages 
                (id, chat_id, topic_id, from_id, date, text, has_media, 
                 views, forwards, reply_to_msg_id, raw_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                views = VALUES(views),
                forwards = VALUES(forwards)
        ");

        $stmt->execute([
            $msg['id'],
            $chatId,
            $topicId,
            $msg['from_id'] ?? $msg['peer_id']['user_id'] ?? null,
            $date,
            $text,
            isset($msg['media']) ? 1 : 0,
            $msg['views'] ?? null,
            $msg['forwards'] ?? null,
            $replyTo,
            json_encode($msg, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Обработка медиа.
     */
    private function processMedia($chatId, $messageId, $media)
    {
        // Определяем тип медиа
        $mediaType = $this->detectMediaType($media);

        if (!$mediaType) {
            return;
        }

        $fileInfo = $this->extractFileInfo($media, $mediaType);

        // Сохраняем запись о медиа
        $stmt = $this->pdo->prepare("
            INSERT INTO media 
                (message_chat_id, message_id, media_type, file_id, file_unique_id,
                 file_size, mime_type, file_name, downloaded)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");

        $stmt->execute([
            $chatId,
            $messageId,
            $mediaType,
            $fileInfo['id'] ?? '',
            $fileInfo['unique_id'] ?? uniqid('', true),
            $fileInfo['size'] ?? null,
            $fileInfo['mime'] ?? null,
            $fileInfo['name'] ?? null,
        ]);

        $mediaId = $this->pdo->lastInsertId();

        // Скачиваем файл через API [citation:1]
        $this->downloadMedia($mediaId, $media);
    }

    /**
     * Скачивание медиафайла.
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
     * Запрос к TelegramApiServer [citation:1].
     */
    private function apiRequest($method, $params = [])
    {
        $url = "{$this->apiUrl}/{$method}";

        // Формируем query string в формате data[peer]=...
        if (!empty($params)) {
            $query = [];

            foreach ($params as $key => $value) {
                $query["data[{$key}]"] = $value;
            }

            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("API error: HTTP {$httpCode}");
        }

        return json_decode($response, true);
    }

    /**
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
        ];

        return $types[$media['_'] ?? ''] ?? null;
    }

    /**
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
                'name' => null,
            ];
        }

        if ($type === 'document' || $type === 'video' || $type === 'audio') {
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
            ];
        }

        return [];
    }
}
