<?php

/**
 * Класс для сбора данных из Telegram.
 */
class Collector
{
    public const SLEEP_TIME = 2; // Время ожидания между циклами загрузки (сек.)
    public const MESSAGE_LIMIT = 100; // Лимит сообщений в рамках одного цикла загрузки

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
     * Добавление чата для синхронизации.
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

        $this->echo("Ищем: $peer.", 'success');

        // Используем API TelegramApiServer [citation:1]
        $chatInfo = $this->apiRequest('getInfo', ['id' => $peer]);

        // Проверяем структуру ответа
        if (isset($chatInfo['success']) && $chatInfo['success'] === true && isset($chatInfo['response']['Chat'])) {
            $chatTgData = $chatInfo['response']['Chat'];
        } elseif (isset($chatInfo['Chat'])) {
            $chatTgData = $chatInfo['Chat'];
        } elseif (isset($chatInfo['response']['channel_id'])) {
            // Альтернативный формат для каналов
            $chatTgData = [
                'id' => $chatInfo['response']['channel_id'],
                'title' => $chatInfo['response']['Chat']['title'] ?? 'Unknown',
                'username' => $chatInfo['response']['Chat']['username'] ?? null,
                'broadcast' => true,
            ];
        } else {
            throw new Exception("Не удалось получить информацию о чате: " . json_encode($chatInfo) . "!");
        }

        // Определяем тип чата
        if (isset($chatTgData['broadcast']) && $chatTgData['broadcast']) {
            $peerType = 'channel';
        } elseif (isset($chatTgData['megagroup']) && $chatTgData['megagroup']) {
            $peerType = 'supergroup';
        } elseif (isset($chatTgData['chat_id'])) {
            $peerType = 'chat';
        } else {
            $peerType = 'group';
        }

        // Получаем информацию о чате
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM chats
            WHERE
                is_archived = 0
                AND id = ?
        ");

        $stmt->execute([$chatTgData['id']]);
        $chat = $stmt->fetch();

        if (!$chat) {
            // Добавляем новый чат
            $stmt = $this->pdo->prepare(
                "
                INSERT INTO chats (id, peer_type, username, title, about, is_forum)
                VALUES (?, ?, ?, ?, ?, ?)
            "
            );

            $stmt->execute([
                $chatTgData['id'],
                $peerType,
                $chatTgData['username'] ?? null,
                $chatTgData['title'] ?? 'Unknown',
                $chatTgData['about'] ?? null,
                (int) ($chatTgData['forum'] ?? false),
            ]);

            $this->echo(
                "Чат успешно добавлен: \"{$chatTgData['title']}\" (ID: {$chatTgData['id']}).",
                'success'
            );

            return $chatTgData['id'];
        }

        // Запрос подтверждения обновления
        $this->echo(
            "Чат уже добавлен: \"{$chatTgData['title']}\" (ID: {$chatTgData['id']})!",
            'warning'
        );

        $this->echo('Вы хотите обновить информацию о нём? (y/n): ');

        $handle = fopen("php://stdin", "r");
        $input = trim(fgets($handle));
        fclose($handle);

        if (strtolower($input) !== 'y' && strtolower($input) !== 'yes') {
            throw new Exception("Добавление чата отменено пользователем!");
        }

        // Обновляем информацию о чате
        $stmt = $this->pdo->prepare("
            UPDATE chats
            SET
                title = ?,
                username = ?,
                about = ?,
                is_forum = COALESCE(?, is_forum)
            WHERE id = ?
        ");

        $stmt->execute([
            $chatTgData['title'] ?? 'Unknown',
            $chatTgData['username'] ?? null,
            $chatTgData['about'] ?? null,
            isset($chatTgData['forum']) ? (int) $chatTgData['forum'] : null,
            $chatTgData['id'],
        ]);

        $this->echo(
            "Информация о чате обновлена: \"{$chatTgData['title']}\" (ID: {$chatTgData['id']}).",
            'success'
        );

        return $chatTgData['id'];
    }

    /**
     * Синхронизация сообщений чата.
     *
     * @param $chatId
     * @return int[]
     * @throws Exception
     */
    public function syncChat($chatId)
    {
        $stmt = $this->pdo->prepare("SELECT id, title, is_old_uploaded FROM chats WHERE id = ?");
        $stmt->execute([$chatId]);
        $chat = $stmt->fetch();

        if (!$chat) {
            throw new Exception("Чат с ID $chatId не найден!");
        }

        $this->echo("Обработка чата: \"{$chat['title']}\" (id: {$chat['id']}).");

        $messagesAdded = 0;
        $mediaDownloaded = 0;
        $logId = $this->startSyncLog($chatId);

        try {
            if (!$chat['is_old_uploaded']) {
                // После прерванной первой загрузки продолжаем строго раньше самого старого сообщения.
                $stmt = $this->pdo->prepare('SELECT MIN(id) FROM messages WHERE chat_id = ?');
                $stmt->execute([$chatId]);
                $offsetId = (int) $stmt->fetchColumn();
                $stopAtId = null;
                $this->echo('Загрузка старых сообщений.', 'success');
            } else {
                // Идём от новых сообщений к последнему уже сохранённому ID.
                $stmt = $this->pdo->prepare('SELECT MAX(id) FROM messages WHERE chat_id = ?');
                $stmt->execute([$chatId]);
                $stopAtId = (int) $stmt->fetchColumn();
                $offsetId = 0;
                $this->echo('Загрузка новых сообщений.', 'success');
            }

            while (true) {
                $response = $this->apiRequest('messages.getHistory', [
                    'peer' => $chatId,
                    'limit' => self::MESSAGE_LIMIT,
                    'offset_id' => $offsetId,
                ]);

                $messages = $response['messages'] ?? [];

                if (!$messages) {
                    if ($stopAtId === null || $stopAtId === 0) {
                        $this->setChatIsOldUploaded($chatId);
                    }

                    break;
                }

                $lastMessage = end($messages);
                $lastId = (int) ($lastMessage['id'] ?? 0);

                if ($lastId <= 0 || ($offsetId > 0 && $lastId >= $offsetId)) {
                    throw new Exception('История Telegram не продвинулась к более старым сообщениям.');
                }

                $this->saveSenders($response);
                $reachedSavedHistory = false;

                foreach ($messages as $msg) {
                    if ($stopAtId !== null && $stopAtId > 0 && (int) ($msg['id'] ?? 0) <= $stopAtId) {
                        $reachedSavedHistory = true;
                        break;
                    }

                    if (empty($msg['id']) || !isset($msg['date']) || ($msg['_'] ?? '') === 'messageEmpty') {
                        continue;
                    }

                    if ($this->saveMessage($chatId, $msg)) {
                        $messagesAdded++;
                    }

                    if (isset($msg['media']) && $this->processMedia($chatId, $msg['id'], $msg['media'])) {
                        $mediaDownloaded++;
                    }
                }

                $this->echo('Получено ' . count($messages) . " сообщений, добавлено новых: $messagesAdded.", 'info');

                if ($reachedSavedHistory) {
                    break;
                }

                $offsetId = $lastId;
                $this->prepareForNextStep();
            }

            $this->finishSyncLog($logId, 'completed', $messagesAdded, $mediaDownloaded);
            $this->echo("Завершено! Добавлено сообщений: $messagesAdded, скачано файлов: $mediaDownloaded.", 'success');
        } catch (Throwable $e) {
            $this->finishSyncLog($logId, 'failed', $messagesAdded, $mediaDownloaded, $e->getMessage());
            throw $e;
        }

        return [
            'messages_added' => $messagesAdded,
            'media_downloaded' => $mediaDownloaded,
        ];
    }

    /**
     * Получение списка всех чатов для синхронизации.
     *
     * @return array
     */
    public function getChatsForSync()
    {
        $stmt = $this->pdo->query("
            SELECT id, title, peer_type, is_forum
            FROM chats
            WHERE is_archived = 0
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
        $stmt = $this->pdo->prepare("
            SELECT id, title, peer_type, is_forum
            FROM chats
            WHERE id = ?
        ");

        $stmt->execute([$chatId]);

        return $stmt->fetch();
    }

    /**
     * Синхронизация полного списка тем форума.
     *
     * @param int $chatId ID чата
     * @return array
     * @throws Exception
     */
    public function syncForumTopics($chatId)
    {
        $stmt = $this->pdo->prepare('SELECT id, title, peer_type, is_forum FROM chats WHERE id = ?');
        $stmt->execute([$chatId]);
        $chat = $stmt->fetch();

        if (!$chat) {
            throw new Exception("Чат с ID $chatId не найден!");
        }

        if ($chat['peer_type'] !== 'supergroup' || !(bool) $chat['is_forum']) {
            throw new Exception("Чат \"{$chat['title']}\" не является форумом.");
        }

        $this->echo("Синхронизация тем чата \"{$chat['title']}\".");
        $offsetDate = 0;
        $offsetId = 0;
        $offsetTopic = 0;
        $seen = [];
        $added = 0;

        while (true) {
            $response = $this->apiRequest('messages.getForumTopics', [
                'peer' => $chatId,
                'limit' => 100,
                'offset_date' => $offsetDate,
                'offset_id' => $offsetId,
                'offset_topic' => $offsetTopic,
            ]);

            $topics = $response['topics'] ?? [];

            if (!$topics) {
                break;
            }

            $messageDates = [];

            foreach ($response['messages'] ?? [] as $message) {
                if (isset($message['id'], $message['date'])) {
                    $messageDates[(int) $message['id']] = (int) $message['date'];
                }
            }

            foreach ($topics as $topic) {
                if (($topic['_'] ?? '') !== 'forumTopic' || !isset($topic['id']) || isset($seen[$topic['id']])) {
                    continue;
                }

                $seen[$topic['id']] = true;

                if ($this->saveForumTopic($chatId, $topic)) {
                    $added++;
                }
            }

            $lastTopic = end($topics);
            $lastId = (int) ($lastTopic['top_message'] ?? 0);
            $lastTopicId = (int) ($lastTopic['id'] ?? 0);
            $lastDate = $messageDates[$lastId] ?? 0;

            if (!$lastId || !$lastTopicId || !$lastDate
                || ($offsetDate === $lastDate && $offsetId === $lastId && $offsetTopic === $lastTopicId)
            ) {
                throw new Exception('Не удалось продолжить загрузку списка тем форума.');
            }

            $offsetDate = $lastDate;
            $offsetId = $lastId;
            $offsetTopic = $lastTopicId;
            $this->echo('Получено тем: ' . count($seen) . '.', 'info');
            $this->prepareForNextStep();
        }

        $stmt = $this->pdo->prepare("
            UPDATE forum_topics ft
            SET messages_count = (
                SELECT COUNT(*) FROM messages m
                WHERE m.chat_id = ft.chat_id AND m.topic_id = ft.id
            ),
            last_message_date = (
                SELECT MAX(m.date) FROM messages m
                WHERE m.chat_id = ft.chat_id AND m.topic_id = ft.id
            )
            WHERE ft.chat_id = ?
        ");

        $stmt->execute([$chatId]);

        $this->echo("Завершено! Получено тем: " . count($seen) . ", новых: $added.", 'success');

        return ['added' => $added, 'total' => count($seen)];
    }

    // Совместимость с прежними вызовами.
    public function syncForumTopicsById($chatId)
    {
        return $this->syncForumTopics($chatId);
    }

    /**
     * Сохранение темы форума в БД.
     *
     * @param int $chatId ID чата
     * @param array $topic Данные темы из API
     * @return void
     */
    protected function saveForumTopic($chatId, $topic)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO forum_topics (
                id, chat_id, title, date, icon_color, icon_emoji_id,
                is_closed, is_pinned, is_hidden, is_short
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                icon_color = VALUES(icon_color),
                icon_emoji_id = VALUES(icon_emoji_id),
                is_closed = VALUES(is_closed),
                is_pinned = VALUES(is_pinned),
                is_hidden = VALUES(is_hidden),
                is_short = VALUES(is_short),
                updated_at = NOW()
        ");

        $stmt->execute([
            $topic['id'],
            $chatId,
            $topic['title'] ?? '',
            $topic['date'] ?? time(),
            $topic['icon_color'] ?? null,
            $topic['icon_emoji_id'] ?? null,
            (int) ($topic['closed'] ?? false),
            (int) ($topic['pinned'] ?? false),
            (int) ($topic['hidden'] ?? false),
            (int) ($topic['short'] ?? false),
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Сохраняем имена отправителей из вспомогательных данных ответа Telegram.
     */
    protected function saveSenders(array $response): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO senders (id, display_name, username)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                username = VALUES(username)
        ");

        foreach ($response['users'] ?? [] as $user) {
            $name = trim(implode(' ', array_filter([
                $user['first_name'] ?? '',
                $user['last_name'] ?? '',
            ], static fn ($part) => $part !== '')));

            $name = $name ?: ($user['username'] ?? null);

            if (isset($user['id']) && $name) {
                $stmt->execute([$user['id'], $name, $user['username'] ?? null]);
            }
        }

        foreach ($response['chats'] ?? [] as $chat) {
            if (isset($chat['id']) && !empty($chat['title'])) {
                $stmt->execute([$chat['id'], $chat['title'], $chat['username'] ?? null]);
            }
        }
    }

    /**
     * Обновляет справочник отправителей для одной страницы истории без загрузки медиа.
     */
    public function syncSendersPage($chatId, int $offsetId, int $limit = 100): array
    {
        $response = $this->apiRequest('messages.getHistory', [
            'peer' => $chatId,
            'limit' => $limit,
            'offset_id' => $offsetId,
        ]);

        $this->saveSenders($response);

        $messages = $response['messages'] ?? [];
        $lastMessage = $messages ? end($messages) : null;
        $lastId = (int) ($lastMessage['id'] ?? $offsetId);

        if ($messages && ($lastId <= 0 || $lastId >= $offsetId)) {
            throw new Exception('История Telegram не продвинулась к более старым сообщениям.');
        }

        return ['count' => count($messages), 'last_id' => $lastId];
    }

    /**
     * Сохранение сообщения.
     *
     * @param $chatId
     * @param $msg
     * @return void
     */
    protected function saveMessage($chatId, $msg)
    {
        // 1. Базовые поля
        $messageId = $msg['id'] ?? null;

        if (!$messageId) {
            $this->echo('Внимание: Сообщение без ID, пропускаем.', 'warning');
            return false;
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
                topic_id = VALUES(topic_id),
                from_id = VALUES(from_id),
                has_media = VALUES(has_media),
                views = VALUES(views),
                forwards = VALUES(forwards),
                edit_date = VALUES(edit_date),
                text = VALUES(text),
                reply_to_msg_id = VALUES(reply_to_msg_id),
                post_author = VALUES(post_author),
                raw_data = VALUES(raw_data)
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
            json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Обработка медиа.
     *
     * @param $chatId
     * @param $messageId
     * @param $media
     * @return bool
     */
    protected function processMedia($chatId, $messageId, $media)
    {
        $mediaType = $this->detectMediaType($media);

        if (!$mediaType) {
            return false;
        }

        $fileInfo = $this->extractFileInfo($media, $mediaType);

        // Какие типы можно скачать как файлы
        $downloadableTypes = ['photo', 'video', 'document', 'audio', 'voice', 'sticker'];

        $existingStmt = $this->pdo->prepare("
            SELECT id, downloaded, file_path
            FROM media
            WHERE message_chat_id = ? AND message_id = ? AND media_type = ?
            ORDER BY downloaded DESC, id
            LIMIT 1
        ");
        $existingStmt->execute([$chatId, $messageId, $mediaType]);
        $existing = $existingStmt->fetch();

        if ($existing) {
            if (!in_array($mediaType, $downloadableTypes, true)) {
                return false;
            }

            if ($existing['downloaded'] && $existing['file_path']
                && is_file($this->mediaBasePath . '/' . $existing['file_path'])
            ) {
                return false;
            }

            return $this->downloadMedia($existing['id'], $media);
        }

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

        return in_array($mediaType, $downloadableTypes, true)
            ? $this->downloadMedia($mediaId, $media)
            : false;
    }

    /**
     * Скачивание медиафайла.
     *
     * @param $mediaId
     * @param $media
     * @return bool
     */
    protected function downloadMedia($mediaId, $media)
    {
        $temporaryPath = null;
        $output = null;

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
                throw new Exception("Информации о медиа не найдено!");
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

            // Пишем ответ сразу во временный файл, не загружая всё видео в память PHP.
            $temporaryPath = $fullPath . '.' . $mediaId . '.' . getmypid() . '.part';
            $output = fopen($temporaryPath, 'wb');

            if ($output === false) {
                throw new Exception("Не удалось открыть временный файл $temporaryPath.");
            }

            $ch = curl_init("{$this->apiUrl}/downloadToResponse");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['media' => $media]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_FILE, $output);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);

            $ok = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($output);
            $output = null;

            if ($ok && $httpCode === 200 && filesize($temporaryPath) > 0) {
                if (!rename($temporaryPath, $fullPath)) {
                    throw new Exception("Не удалось сохранить файл $relativePath.");
                }

                $temporaryPath = null;

                // Обновляем запись в БД
                $stmt = $this->pdo->prepare("
                    UPDATE media
                    SET file_path = ?, downloaded = 1
                    WHERE id = ?
                ");

                $stmt->execute([$relativePath, $mediaId]);

                $this->echo("Скачано: $relativePath.", 'description');

                return true;
            }

            throw new Exception("TelegramApiServer не вернул файл: HTTP $httpCode. $curlError");
        } catch (Throwable $e) {
            if (is_resource($output)) {
                fclose($output);
            }

            if ($temporaryPath && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            error_log('Скачивание не удалось: ' . $e->getMessage() . ".\n");

            return false;
        }
    }

    /**
     * Повторяет скачивание ранее не полученных файлов без создания новых записей.
     */
    public function retryMissingMedia(int $limit = 100, $chatId = null, int $afterId = 0): array
    {
        $sql = "
            SELECT med.id, msg.raw_data
            FROM media med
            JOIN messages msg ON msg.chat_id = med.message_chat_id AND msg.id = med.message_id
            WHERE med.downloaded = 0
              AND med.id > ?
              AND med.media_type IN ('photo', 'video', 'document', 'audio', 'voice', 'sticker')
        ";

        $params = [$afterId];

        if ($chatId !== null) {
            $sql .= ' AND med.message_chat_id = ?';
            $params[] = $chatId;
        }

        $sql .= ' ORDER BY med.id LIMIT ' . max(1, $limit);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $attempted = 0;
        $downloaded = 0;
        $lastId = $afterId;

        foreach ($stmt->fetchAll() as $row) {
            $lastId = (int) $row['id'];
            $message = json_decode($row['raw_data'] ?? '', true);

            if (empty($message['media'])) {
                continue;
            }

            $attempted++;

            if ($this->downloadMedia($row['id'], $message['media'])) {
                $downloaded++;
            }
        }

        return ['attempted' => $attempted, 'downloaded' => $downloaded, 'last_id' => $lastId];
    }

    /**
     * Определяет расширение по mime_type и типу медиа.
     *
     * @param $mime
     * @param $mediaType
     * @return string
     */
    protected function getExtensionFromMime($mime, $mediaType)
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
    protected function detectMediaType($media)
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
                    return ($attr['voice'] ?? false) ? 'voice' : 'audio';
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
    protected function extractFileInfo($media, $type)
    {
        // Фото
        if ($type === 'photo') {
            $photo = $media['photo'] ?? $media;
            $sizes = $photo['sizes'] ?? [];
            $maxSize = $sizes ? end($sizes) : [];

            return [
                'id' => $photo['id'] ?? null,
                'unique_id' => $photo['access_hash'] ?? null,
                'size' => $maxSize['size'] ?? null,
                'mime' => 'image/jpeg',
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
    protected function apiRequest($method, $params = [])
    {
        $url = "{$this->apiUrl}/{$method}";

        // Формируем query string в формате data[peer]=...
        if (!empty($params)) {
            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $url .= '?' . $query;
        }

        while (true) {
            $this->echo('---', 'description');
            $this->echo('Запрос: ' . rawurldecode($url) . '.', 'description');
            $this->echo('---', 'description');

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'TelegramParser/1.0');

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                break;
            }

            $data = json_decode($response, true);

            if (isset($data['errors'][0]['message'])
                && strpos($data['errors'][0]['message'], 'FLOOD_WAIT') !== false
            ) {
                preg_match('/(\d+)/', $data['errors'][0]['message'], $matches);
                $waitTime = max(1, (int) ($matches[1] ?? 30));

                $this->echo(
                    "⚠️ Контроль переполнения: ждём {$waitTime} сек. перед следующим запросом.",
                    'error'
                );

                sleep($waitTime);
                continue;
            }

            throw new Exception("Ошибка АПИ: HTTP {$httpCode}.");
        }

        $data = json_decode($response, true);

        if (!is_array($data) || ($data['success'] ?? true) === false) {
            throw new Exception('TelegramApiServer вернул неуспешный или некорректный ответ.');
        }

        // TelegramApiServer возвращает данные в формате {"success":true, "response":...}
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
    protected function startSyncLog($chatId)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO sync_log (chat_id, started_at, status)
            VALUES (?, NOW(), 'running')
        ");

        $stmt->execute([$chatId]);

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
    protected function finishSyncLog($logId, $status, $added, $media, $error = null)
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
     * Подготовка к следующему шагу.
     *
     * @return void
     */
    protected function prepareForNextStep()
    {
        usleep(self::SLEEP_TIME * 1000000);
        gc_collect_cycles();
    }

    /**
     * Устанавливаем для выбранного чата флаг полной загрузки старых сообщений
     * (is_old_uploaded = true).
     *
     * @param int $chatId
     * @return void
     */
    protected function setChatIsOldUploaded(int $chatId)
    {
        $stmt = $this->pdo->prepare("
            UPDATE chats
            SET is_old_uploaded = 1
            WHERE id = ?
        ");

        $stmt->execute([$chatId]);

        $this->echo('Устанавливаем флаг полной загрузки старых сообщений.', 'description');
    }

    /**
     * Вывод цветного текста.
     *
     * @param string $message
     * @param string|null $type
     * @return void
     */
    protected function echo(string $message, string $type = null)
    {
        $whiteColor = "\033[0m";

        $color = match ($type) {
            'error' => "\033[31m",
            'success' => "\033[32m",
            'warning' => "\033[33m",
            'info' => "\033[36m",
            'description' => "\033[37m",
            default => $whiteColor,
        };

        echo "{$color}{$message}{$whiteColor}\n";
    }
}
