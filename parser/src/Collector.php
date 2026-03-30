<?php

/**
 * Класс для сбора данных из Telegram.
 */
class Collector
{
    public const MESSAGE_LIMIT = 500;
    public const SLEEP_TIME = 2;

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

        $this->pdo->exec("SET time_zone = '+03:00'");
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
                'broadcast' => true,
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
     * @param int $chatId ID чата
     * @param string $mode 'old' - загрузка старых, 'new' - загрузка новых
     * @param int $maxMessages Максимальное количество сообщений для загрузки (0 = без ограничений)
     * @return array
     * @throws Exception
     */
    public function syncChat($chatId, $mode = 'new', $maxMessages = 0)
    {
        // Лимит сообщений в рамках одного цикла
        $limit = self::MESSAGE_LIMIT;

        // Получаем информацию о чате
        $stmt = $this->pdo->prepare("SELECT last_sync_id, title FROM chats WHERE id = ?");

        $stmt->execute([$chatId]);
        $chat = $stmt->fetch();

        if (!$chat) {
            throw new Exception("Chat ID $chatId not found in database");
        }

        $lastSyncId = $chat['last_sync_id'] ?? 0;

        // Если last_sync_id = 0 и mode='new', автоматически переключаемся на 'old'
        if ($mode === 'new' && $lastSyncId == 0) {
            echo "No last_sync_id found, switching to old mode\n";
            $mode = 'old';
        }

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
                    'min_id' => $lastSyncId + 1,
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
                        'offset_id' => $offsetId,
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

                    // Задержка между запросами
                    echo "Sleeping for " . self::SLEEP_TIME . " second(s)...\n";
                    usleep(self::SLEEP_TIME * 1000000);

                    gc_collect_cycles();
                }
            }

            // Обновляем last_sync_id
            if ($maxId > $lastSyncId) {
                $stmt = $this->pdo->prepare("UPDATE chats SET last_sync_id = ? WHERE id = ?");

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
            'last_id' => $maxId,
        ];
    }

    /**
     * Получить максимальный ID сообщения в чате.
     *
     * @param $chatId
     * @return int|mixed
     */
    private function getMaxMessageId($chatId)
    {
        $stmt = $this->pdo->prepare("SELECT MAX(id) FROM messages WHERE chat_id = ?");
        $stmt->execute([$chatId]);

        return $stmt->fetchColumn() ?: 0;
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
            : null;

        $editDate = isset($msg['edit_date'])
            ? date('Y-m-d H:i:s', $msg['edit_date'])
            : null;

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
            json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
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
        $mediaType = $this->detectMediaType($media);

        if (!$mediaType) {
            return false;
        }

        $fileInfo = $this->extractFileInfo($media, $mediaType);

        // Какие типы можно скачать как файлы
        $downloadableTypes = ['photo', 'video', 'document', 'audio', 'voice', 'sticker'];

        $stmt = $this->pdo->prepare("
            INSERT INTO media (
                message_chat_id, message_id, media_type,
                file_id, file_unique_id, file_size,
                mime_type, file_name, width, height, duration,
                additional_data
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $additionalData = json_encode([
            'url' => $fileInfo['url'] ?? null,
            'site_name' => $fileInfo['site_name'] ?? null,
            'lat' => $fileInfo['lat'] ?? null,
            'long' => $fileInfo['long'] ?? null,
            'question' => $fileInfo['question'] ?? null,
            'answers' => $fileInfo['answers'] ?? null,
            'phone_number' => $fileInfo['phone_number'] ?? null,
            'first_name' => $fileInfo['first_name'] ?? null,
            'last_name' => $fileInfo['last_name'] ?? null,
            'vcard' => $fileInfo['vcard'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $stmt->execute([
            $chatId,
            $messageId,
            $mediaType,
            $fileInfo['id'] ?? '',
            $fileInfo['unique_id'] ?? uniqid(),
            $fileInfo['size'] ?? null,
            $fileInfo['mime'] ?? null,
            isset($fileInfo['name']) && $fileInfo['name']
                ? mb_substr($fileInfo['name'], 0, 512)
                : null
            ,
            $fileInfo['width'] ?? null,
            $fileInfo['height'] ?? null,
            $fileInfo['duration'] ?? null,
            $additionalData,
        ]);

        $mediaId = $this->pdo->lastInsertId();

        // Скачиваем только если это файловый тип
        if (in_array($mediaType, $downloadableTypes)) {
            $this->downloadMedia($mediaId, $media);
        }

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
            // Получаем информацию о медиа из БД
            $stmt = $this->pdo->prepare("
                SELECT m.*, msg.chat_id
                FROM media m
                JOIN messages msg ON m.message_chat_id = msg.chat_id AND m.message_id = msg.id
                WHERE m.id = ?
            ");

            $stmt->execute([$mediaId]);
            $mediaInfo = $stmt->fetch();

            if (!$mediaInfo) {
                throw new Exception("Media info not found");
            }

            // Определяем расширение по mime_type
            $extension = $this->getExtensionFromMime($mediaInfo['mime_type'], $mediaInfo['media_type']);

            // Формируем путь: /media/{chat_id}/{id}_{type}.ext
            $chatId = $mediaInfo['chat_id'];

            $fileName = sprintf(
                "%d_%s%s",
                $mediaInfo['message_id'],
                $mediaInfo['media_type'],
                $extension ? ".$extension" : ""
            );

            $relativePath = "{$chatId}/{$fileName}";
            $fullPath = $this->mediaBasePath . '/' . $relativePath;

            // Создаём директорию
            $dir = dirname($fullPath);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Скачиваем файл через API
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

                $stmt->execute([$relativePath, $mediaId]);

                echo "Downloaded: $relativePath\n";
            }
        } catch (Exception $e) {
            error_log("Download failed: " . $e->getMessage());
        }
    }

    /**
     * Определяет расширение по mime_type и типу медиа.
     *
     * @param $mime
     * @param $mediaType
     * @return string
     */
    private function getExtensionFromMime($mime, $mediaType)
    {
        $map = [
            // Изображения
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',

            // Видео
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mpeg',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/webm' => 'webm',
            'video/ogg' => 'ogv',

            // Аудио
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'weba',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',

            // Документы
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/html' => 'html',
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-tar' => 'tar',
            'application/gzip' => 'gz',
        ];

        // Специальные случаи для стикеров
        if ($mediaType === 'sticker') {
            if ($mime === 'application/x-tgsticker') {
                return 'tgs'; // Анимированные стикеры Telegram
            }

            if ($mime === 'image/webp') {
                return 'webp'; // Обычные стикеры
            }
        }

        return $map[$mime] ?? '';
    }

    /**
     * Определение типа медиа.
     *
     * @param $media
     * @return string|null
     */
    private function detectMediaType($media)
    {
        // Прямые типы
        $directTypes = [
            'messageMediaPhoto' => 'photo',
            'messageMediaVideo' => 'video',
            'messageMediaAudio' => 'audio',
            'messageMediaVoice' => 'voice',
            'messageMediaSticker' => 'sticker',
            'messageMediaGeo' => 'geo',
            'messageMediaGeoLive' => 'geo_live',
            'messageMediaContact' => 'contact',
            'messageMediaPoll' => 'poll',
            'messageMediaWebPage' => 'webpage',
            'messageMediaGame' => 'game',
            'messageMediaInvoice' => 'invoice',
            'messageMediaVenue' => 'venue',
        ];

        $type = $media['_'] ?? '';

        // Проверяем прямые типы
        if (isset($directTypes[$type])) {
            return $directTypes[$type];
        }

        // Для document нужно анализировать mime_type и атрибуты
        if ($type === 'messageMediaDocument') {
            $doc = $media['document'] ?? [];
            $mime = $doc['mime_type'] ?? '';
            $attributes = $doc['attributes'] ?? [];

            // Проверяем атрибуты
            foreach ($attributes as $attr) {
                $attrType = $attr['_'] ?? '';

                if ($attrType === 'documentAttributeVideo') {
                    return 'video';
                }

                if ($attrType === 'documentAttributeAudio') {
                    // Проверяем, голосовое или музыка
                    return $attr['voice'] ? 'voice' : 'audio';
                }

                if ($attrType === 'documentAttributeSticker') {
                    return 'sticker';
                }
            }

            // Если не определили по атрибутам, пробуем по mime
            if (str_starts_with($mime, 'video/')) {
                return 'video';
            }

            if (str_starts_with($mime, 'audio/')) {
                return 'audio';
            }

            if ($mime === 'image/webp' || $mime === 'application/x-tgsticker') {
                return 'sticker';
            }

            return 'document';
        }

        return null;
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
        // Фото
        if ($type === 'photo') {
            $sizes = $media['sizes'] ?? [];
            $maxSize = end($sizes);

            return [
                'id' => $media['id'] ?? null,
                'unique_id' => $media['access_hash'] ?? null,
                'size' => $maxSize['size'] ?? null,
                'width' => $maxSize['w'] ?? null,
                'height' => $maxSize['h'] ?? null,
            ];
        }

        // Документы и медиа-файлы
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
                'duration' => $doc['duration'] ?? null,
                'width' => $doc['w'] ?? null,
                'height' => $doc['h'] ?? null,
            ];
        }

        // Webpage (ссылки с превью)
        if ($type === 'webpage') {
            $webpage = $media['webpage'] ?? [];

            return [
                'id' => $webpage['id'] ?? null,
                'unique_id' => null,
                'size' => null,
                'mime' => 'text/html',
                'name' => $webpage['title'] ?? 'webpage',
                'url' => $webpage['url'] ?? null,
                'site_name' => $webpage['site_name'] ?? null,
            ];
        }

        // Гео-данные
        if (in_array($type, ['geo', 'geo_live'])) {
            return [
                'id' => null,
                'unique_id' => null,
                'size' => null,
                'lat' => $media['lat'] ?? $media['geo']['lat'] ?? null,
                'long' => $media['long'] ?? $media['geo']['long'] ?? null,
                'period' => $media['period'] ?? null,
            ];
        }

        // Контакт
        if ($type === 'contact') {
            return [
                'id' => null,
                'unique_id' => null,
                'size' => null,
                'phone_number' => $media['phone_number'] ?? null,
                'first_name' => $media['first_name'] ?? null,
                'last_name' => $media['last_name'] ?? null,
                'vcard' => $media['vcard'] ?? null,
            ];
        }

        // Опрос
        if ($type === 'poll') {
            $poll = $media['poll'] ?? [];

            return [
                'id' => $poll['id'] ?? null,
                'unique_id' => null,
                'size' => null,
                'question' => $poll['question'] ?? null,
                'answers' => json_encode($poll['answers'] ?? []),
                'closed' => $poll['closed'] ?? false,
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

        echo "---\n";
        echo "Request: $url\n";
        echo "---\n";

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

    /**
     * Получение чата по его Id.
     *
     * @param $chatId
     * @return mixed
     */
    public function getChatById($chatId)
    {
        $stmt = $this->pdo->query("
            SELECT id, title, last_sync_id
            FROM chats
            WHERE id = ?
        ");

        $stmt->execute([$chatId]);

        return $stmt->fetch();
    }
}
