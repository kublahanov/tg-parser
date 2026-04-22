<?php

/**
 * Класс для сбора данных из Telegram.
 */
class Collector
{
    public const MESSAGE_LIMIT = 100; // Лимит сообщений в рамках одного цикла загрузки
    public const SLEEP_TIME = 2; // Время ожидания между циклами загрузки (сек.)

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
                INSERT INTO chats (id, peer_type, username, title, about)
                VALUES (?, ?, ?, ?, ?)
            "
            );

            $stmt->execute([
                $chatTgData['id'],
                $peerType,
                $chatTgData['username'] ?? null,
                $chatTgData['title'] ?? 'Unknown',
                $chatTgData['about'] ?? null,
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
                about = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $chatTgData['title'] ?? 'Unknown',
            $chatTgData['username'] ?? null,
            $chatTgData['about'] ?? null,
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
        // Получаем информацию о чате
        $stmt = $this->pdo->prepare("
            SELECT id, title, is_old_uploaded
            FROM chats
            WHERE id = ?
        ");

        $stmt->execute([$chatId]);
        $chat = $stmt->fetch();

        if (!$chat) {
            throw new Exception("Чат с ID $chatId не найден!");
        }

        $this->echo("Обработка чата: \"{$chat['title']}\" (id: {$chat['id']}).");

        $messagesAdded = 0; // Счетчик добавленных сообщений
        $mediaDownloaded = 0; // Счетчик загруженных медиа

        /**
         * Пункт 1.
         * Проверяем, общее число сообщений у выбранного чата.
         */
        $params = [
            'peer' => $chatId,
            'limit' => 1,
            'add_offset' => 0,
        ];

        $response = $this->apiRequest('messages.getHistory', $params);

        $messagesLimit = $response['count'] ?? 0;

        /**
         * Если АПИ вернуло нулевое число сообщений (общее)
         * - значит чат ещё пуст, загрузка окончена.
         */
        if ($messagesLimit < 1) {
            $this->echo('Число найденных в чате сообщений равно 0, прерываем.', 'warning');

            $this->setChatIsOldUploaded($chatId);

            return [
                'messages_added' => $messagesAdded,
                'media_downloaded' => $mediaDownloaded,
            ];
        }

        $hasMoreOld = true; // Флаг необходимости загрузки старых сообщений
        $hasMoreNew = true; // Флаг необходимости загрузки новых сообщений

        try {
            // Логируем начало синхронизации
            $logId = $this->startSyncLog($chatId);

            if (!$chat['is_old_uploaded']) {
                /**
                 * Пункт 2.
                 * Если у чата не истинен флаг полной загрузки старых сообщений
                 * (is_old_uploaded == false) - начинаем загрузку старых сообщений.
                 */
                $this->echo('Загрузка старых сообщений.', 'success');

                /**
                 * Проверяем, есть ли сообщения у выбранного чата.
                 */
                $stmt = $this->pdo->prepare("
                    SELECT count(*) cnt
                    FROM messages
                    WHERE chat_id = ?
                ");

                $stmt->execute([$chatId]);
                $stmtResult = $stmt->fetch();
                $messagesCount = $stmtResult['cnt'] ?? 0;

                if ($messagesCount < 1) {
                    /**
                     * Пункт 2.1.
                     * Если сообщений нет - загружаем все, начиная с самого нового.
                     */
                    $this->echo('Сообщения не найдены, начинаем с самого нового.', 'info');

                    $addOffset = 0; // Смещение для каждого из циклов запроса

                    /**
                     * Цикл загрузки.
                     */
                    while ($hasMoreOld) {
                        /**
                         * Если достигнут лимит сообщений, указанный для чата
                         * - значит загрузка окончена.
                         */
                        if ($addOffset + self::MESSAGE_LIMIT > $messagesLimit) {
                            $hasMoreOld = false;

                            $this->echo('Достигнут лимит сообщений, указанный для чата, прерываем.', 'warning');

                            $this->setChatIsOldUploaded($chatId);

                            break;
                        }

                        $params = [
                            'peer' => $chatId,
                            'limit' => self::MESSAGE_LIMIT,
                            'add_offset' => $addOffset,
                        ];

                        $response = $this->apiRequest('messages.getHistory', $params);

                        $messages = $response['messages'] ?? [];

                        /**
                         * Если АПИ вернуло пустой массив сообщений
                         * - значит загрузка окончена.
                         */
                        if (empty($messages)) {
                            $hasMoreOld = false;

                            $this->echo('Список сообщений пуст, прерываем.', 'warning');

                            $this->setChatIsOldUploaded($chatId);

                            break;
                        }

                        foreach ($messages as $msg) {
                            // Вывод прогресса каждые 10 сообщений
                            if ($messagesAdded > 0 && ($messagesAdded % 10 == 0)) {
                                $this->echo("  Обработка: $messagesAdded сообщений.");
                            }

                            $this->saveMessage($chatId, $msg);

                            $messagesAdded++;

                            if (isset($msg['media'])) {
                                if ($this->processMedia($chatId, $msg['id'], $msg['media'])) {
                                    $mediaDownloaded++;
                                }
                            }
                        }

                        $addOffset += self::MESSAGE_LIMIT;

                        $this->echo("Загружено " . count($messages) . " сообщений.", 'info');

                        // Задержка между запросами
                        $this->echo("Пауза " . self::SLEEP_TIME . " сек. перед следующим циклом.", 'description');

                        $this->prepareForNextStep();
                    }
                } else {
                    /**
                     * Пункт 2.2.
                     * Если сообщения есть (т. е. чат "недогрузился" - возможно из-за ошибки)
                     * - продолжаем загрузку от самого старого сообщения.
                     */
                    $this->echo(
                        "Найдено сообщений: $messagesCount. Продолжаем загрузку от самого старого сообщения.",
                        'info'
                    );

                    /**
                     * Ищем минимальный ID сообщения среди загруженных.
                     */
                    $stmt = $this->pdo->prepare("
                        SELECT min(id) min
                        FROM messages
                        WHERE chat_id = ?
                    ");

                    $stmt->execute([$chatId]);
                    $stmtResult = $stmt->fetch();
                    $minMessageId = $stmtResult['min'] ?? null;

                    if (!$minMessageId) {
                        throw new Exception("Ошибка получения минимального ID!");
                    }

                    $this->echo("Загрузка старых сообщений (от ID $minMessageId).");

                    $addOffset = 0; // Смещение для каждого из циклов запроса

                    /**
                     * Цикл загрузки.
                     */
                    while ($hasMoreOld) {
                        /**
                         * Если достигнут лимит сообщений, указанный для чата
                         * - значит загрузка окончена.
                         */
                        if ($addOffset + self::MESSAGE_LIMIT > $messagesLimit) {
                            $hasMoreOld = false;

                            $this->echo('Достигнут лимит сообщений, указанный для чата, прерываем.', 'warning');

                            $this->setChatIsOldUploaded($chatId);

                            break;
                        }

                        $params = [
                            'peer' => $chatId,
                            'limit' => self::MESSAGE_LIMIT,
                            'add_offset' => $addOffset,
                            'max_id' => $minMessageId,
                        ];

                        $response = $this->apiRequest('messages.getHistory', $params);

                        $messages = $response['messages'] ?? [];

                        /**
                         * Если АПИ вернуло пустой массив сообщений
                         * - продолжаем загрузку, так как вероятно не дошли до сообщений
                         * с ID меньше минимального.
                         */
                        if (empty($messages)) {
                            $this->echo(
                                'Список сообщений пуст, так как, вероятно, не дошли до сообщений'
                                . ' с ID меньше минимального. Продолжаем загрузку.',
                                'warning'
                            );

                            $addOffset += self::MESSAGE_LIMIT;

                            // Задержка между запросами
                            $this->echo("Пауза " . self::SLEEP_TIME . " сек. перед следующим циклом.", 'description');

                            $this->prepareForNextStep();

                            continue;
                        }

                        foreach ($messages as $msg) {
                            // Вывод прогресса каждые 10 сообщений
                            if ($messagesAdded > 0 && ($messagesAdded % 10 == 0)) {
                                $this->echo("  Обработка: $messagesAdded сообщений.");
                            }

                            $this->saveMessage($chatId, $msg);

                            $messagesAdded++;

                            if (isset($msg['media'])) {
                                if ($this->processMedia($chatId, $msg['id'], $msg['media'])) {
                                    $mediaDownloaded++;
                                }
                            }
                        }

                        $addOffset += self::MESSAGE_LIMIT;

                        $this->echo("Загружено " . count($messages) . " сообщений.", 'info');

                        // Задержка между запросами
                        $this->echo("Пауза " . self::SLEEP_TIME . " сек. перед следующим циклом.", 'description');

                        $this->prepareForNextStep();
                    }
                }
            } else {
                /**
                 * Пункт 3.
                 * Если у чата истинен флаг полной загрузки старых сообщений
                 * (is_old_uploaded == true) - начинаем загрузку новых сообщений.
                 */
                $this->echo('Загрузка новых сообщений.', 'success');

                /**
                 * Ищем максимальный ID сообщения среди загруженных.
                 */
                $stmt = $this->pdo->prepare("
                        SELECT max(id) max
                        FROM messages
                        WHERE chat_id = ?
                    ");

                $stmt->execute([$chatId]);
                $stmtResult = $stmt->fetch();
                $maxMessageId = $stmtResult['max'] ?? null;

                if (!$maxMessageId) {
                    throw new Exception("Ошибка получения минимального ID!");
                }

                $this->echo("Загрузка новых сообщений (от ID $maxMessageId).");

                $addOffset = 0; // Смещение для каждого из циклов запроса

                /**
                 * Цикл загрузки.
                 */
                while ($hasMoreNew) {
                    /**
                     * Если достигнут лимит сообщений, указанный для чата
                     * - значит загрузка окончена.
                     */
                    if ($addOffset + self::MESSAGE_LIMIT > $messagesLimit) {
                        $hasMoreOld = false;

                        $this->echo('Достигнут лимит сообщений, указанный для чата, прерываем.', 'warning');

                        $this->setChatIsOldUploaded($chatId);

                        break;
                    }

                    $params = [
                        'peer' => $chatId,
                        'limit' => self::MESSAGE_LIMIT,
                        'add_offset' => $addOffset,
                        'min_id' => $maxMessageId,
                    ];

                    $response = $this->apiRequest('messages.getHistory', $params);

                    $messages = $response['messages'] ?? [];

                    /**
                     * Если АПИ вернуло пустой массив сообщений
                     * - значит загрузка окончена.
                     */
                    if (empty($messages)) {
                        $hasMoreOld = false;

                        $this->echo('Список сообщений пуст, прерываем.', 'warning');

                        $this->setChatIsOldUploaded($chatId);

                        break;
                    }

                    foreach ($messages as $msg) {
                        // Вывод прогресса каждые 10 сообщений
                        if ($messagesAdded > 0 && ($messagesAdded % 10 == 0)) {
                            $this->echo("  Обработка: $messagesAdded сообщений.");
                        }

                        $this->saveMessage($chatId, $msg);

                        $messagesAdded++;

                        if (isset($msg['media'])) {
                            if ($this->processMedia($chatId, $msg['id'], $msg['media'])) {
                                $mediaDownloaded++;
                            }
                        }
                    }

                    $addOffset += self::MESSAGE_LIMIT;

                    $this->echo("Загружено " . count($messages) . " сообщений.", 'info');

                    // Задержка между запросами
                    $this->echo("Пауза " . self::SLEEP_TIME . " сек. перед следующим циклом.", 'description');

                    $this->prepareForNextStep();
                }
            }

            $this->echo(
                "Завершено! Добавлено сообщений: $messagesAdded, файлов: $mediaDownloaded.",
                'success'
            );

            $this->finishSyncLog($logId, 'completed', $messagesAdded, $mediaDownloaded);
        } catch (Exception $e) {
            $this->finishSyncLog($logId, 'failed', $messagesAdded, $mediaDownloaded, $e->getMessage());

            throw $e;
        }

        return [
            'messages_added' => $messagesAdded,
            'media_downloaded' => $mediaDownloaded,
        ];
    }

    /**
     * @deprecated
     * Получить максимальный ID сообщения в чате.
     *
     * @param $chatId
     * @return int|mixed
     */
    private function getMaxMessageId($chatId)
    {
        $stmt = $this->pdo->prepare("
            SELECT MAX(id)
            FROM messages
            WHERE chat_id = ?
        ");

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
            $this->echo('Внимание: Сообщение без ID, пропускаем.', 'warning');
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

                $this->echo("Скачано: $relativePath.", 'description');
            }
        } catch (Exception $e) {
            error_log("Скачивание не удалось: " . $e->getMessage() . ".\n");
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

        $this->echo('---', 'description');
        $this->echo("Запрос: $url.", 'description');
        $this->echo('---', 'description');

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

                $this->echo(
                    "⚠️ Контроль переполнения: ждём {$waitTime} сек. перед следующим запросом.",
                    'error'
                );

                sleep($waitTime);

                // Повторяем запрос
                return $this->apiRequest($method, $params, $retryCount + 1);
            }

            throw new Exception("Ошибка АПИ: HTTP {$httpCode}.");
        }

        $data = json_decode($response, true);

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
    private function startSyncLog($chatId)
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
            SELECT id, title
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
            SELECT id, title
            FROM chats
            WHERE id = ?
        ");

        $stmt->execute([$chatId]);

        return $stmt->fetch();
    }

    /**
     * Получить ID самого нового сообщения в чате.
     *
     * @param $chatId
     * @return int|mixed
     */
    private function getFirstMessageId($chatId)
    {
        $params = [
            'peer' => $chatId,
            'limit' => 1,
        ];

        $response = $this->apiRequest('messages.getHistory', $params);
        $messages = $response['messages'] ?? [];

        return $messages[0]['id'] ?? 0;
    }

    /**
     * Подготовка к следующему шагу.
     *
     * @return void
     */
    private function prepareForNextStep()
    {
        usleep(self::SLEEP_TIME * 1000000);
        gc_collect_cycles();
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
            default => "\033[0m",
        };

        echo "{$color}{$message}{$whiteColor}\n";
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
}
