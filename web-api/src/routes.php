<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {
    // Получение PDO соединения
    $getPdo = function () {
        static $pdo = null;

        if ($pdo === null) {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $_ENV['DB_HOST'] ?? 'mysql',
                    $_ENV['DB_PORT'] ?? '3306',
                    $_ENV['DB_NAME'] ?? 'parser'
                ),
                $_ENV['DB_USER'] ?? 'root',
                $_ENV['DB_PASSWORD'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }

        return $pdo;
    };

    // Вспомогательная функция для JSON-ответов
    $jsonResponse = function (Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status)
        ;
    };

    /**
     * Корневой путь (список доступных методов).
     */
    $app->get('/', function (Request $request, Response $response) use ($jsonResponse) {
        return $jsonResponse($response, [
            'service' => 'Tg-parser API',
            'version' => '1.0.0',
            'endpoints' => [
                '/api/v1/chats',
                '/api/v1/chats/{id}',
                '/api/v1/chats/{id}/stats',
                '/api/v1/chats/{id}/messages?page=1&topic=0',
                '/api/v1/chats/{id}/search?q=query&page=1',
                '/api/v1/messages/{chatId}/{msgId}',
                '/api/v1/media/{chatId}?limit=20',
                '/api/v1/topics/{chatId}',
                '/api/v1/topics/{chatId}/{topicId}/messages?page=1',
                '/api/v1/stats',
            ],
        ]);
    });

    /**
     * GET /api/v1/chats - Список всех чатов.
     */
    $app->get('/api/v1/chats', function (Request $request, Response $response) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();

        $sql = "
            SELECT
                c.id,
                c.title,
                c.username,
                c.peer_type,
                c.is_forum,
                COUNT(m.id) as messages_count,
                MIN(m.date) as first_message,
                MAX(m.date) as last_message,
                SUM(m.has_media) as media_count
            FROM chats c
            LEFT JOIN messages m ON c.id = m.chat_id
            GROUP BY c.id
            ORDER BY last_message DESC
        ";

        $chats = $pdo->query($sql)->fetchAll();

        return $jsonResponse(
            $response,
            [
                'success' => true,
                'data' => $chats,
            ]
        );
    });

    /**
     * GET /api/v1/chats/{id} - Информация о чате.
     */
    $app->get('/api/v1/chats/{id}', function (Request $request, Response $response, array $args) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();

        $sql = "
            SELECT *
            FROM chats
            WHERE id = ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$args['id']]);
        $chat = $stmt->fetch();

        if (!$chat) {
            return $jsonResponse(
                $response,
                [
                    'success' => false,
                    'error' => 'Chat not found',
                ],
                404
            );
        }

        return $jsonResponse(
            $response,
            [
                'success' => true,
                'data' => $chat,
            ]
        );
    });

    /**
     * GET /api/v1/chats/{id}/stats - Статистика чата.
     */
    $app->get('/api/v1/chats/{id}/stats', function (Request $request, Response $response, array $args) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();

        $sql = "
            SELECT
                COUNT(*) as total_messages,
                COUNT(CASE WHEN has_media = 1 THEN 1 END) as media_messages,
                MIN(date) as first_message,
                MAX(date) as last_message,
                DATEDIFF(MAX(date), MIN(date)) as days_span
            FROM messages
            WHERE chat_id = ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$args['id']]);
        $stats = $stmt->fetch();

        return $jsonResponse(
            $response,
            [
                'success' => true,
                'data' => $stats,
            ]
        );
    });

    /**
     * GET /api/v1/chats/{id}/messages - Сообщения чата.
     */
    $app->get('/api/v1/chats/{id}/messages', function (Request $request, Response $response, array $args) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();
        $id = $args['id'];
        $queryParams = $request->getQueryParams();
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $topic = (int) ($queryParams['topic'] ?? 0);
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Простой подсчёт общего количества
        $countSql = "
            SELECT COUNT(*)
            FROM messages m
            WHERE m.chat_id = ?
        ";

        $countParams = [$id];

        if ($topic > 0) {
            $countSql .= " AND COALESCE(m.topic_id, 0) = ?";
            $countParams[] = $topic;
        }

        $stmt = $pdo->prepare($countSql);
        $stmt->execute($countParams);
        $total = (int) $stmt->fetchColumn();

        // Оптимизированный запрос на получение сообщений
        $sql = "
            SELECT 
                m.id,
                m.chat_id,
                m.topic_id,
                m.from_id,
                m.date,
                m.text,
                m.has_media,
                m.views,
                m.forwards,
                m.reply_to_msg_id,
                m.post_author,
                m.edit_date,
                m.raw_data,
                (
                    SELECT COUNT(*)
                    FROM media
                    WHERE
                        message_chat_id = m.chat_id
                        AND message_id = m.id
                ) as media_count
            FROM messages m
            WHERE m.chat_id = ?
        ";

        $params = [$id];

        if ($topic > 0) {
            $sql .= " AND COALESCE(m.topic_id, 0) = ?";
            $params[] = $topic;
        }

        // Явно указываем индексы и лимит
        $sql .= " ORDER BY m.id DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        return $jsonResponse($response, [
            'success' => true,
            'data' => [
                'messages' => $messages,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    });

    /**
     * GET /api/v1/chats/{id}/search - Поиск по сообщениям.
     */
    $app->get('/api/v1/chats/{id}/search', function (Request $request, Response $response, array $args) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();
        $q = trim($request->getQueryParams()['q'] ?? '');

        if (strlen($q) < 2) {
            return $jsonResponse(
                $response,
                [
                    'success' => false,
                    'error' => 'Search query too short',
                ],
                400
            );
        }

        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;
        $searchTerm = '%' . $q . '%';

        $sql = "
            SELECT
                m.*,
                (
                    SELECT COUNT(*)
                    FROM media
                    WHERE
                        message_chat_id = m.chat_id
                        AND message_id = m.id
                ) as media_count
            FROM messages m
            WHERE
                m.chat_id = :chatId
                AND m.text LIKE :search
        ";

        $countSql = str_replace(
            "
                SELECT
                    m.*,
                    (
                        SELECT COUNT(*)
                        FROM media
                        WHERE
                            message_chat_id = m.chat_id
                            AND message_id = m.id
                    ) as media_count
            ",
            "SELECT COUNT(*)",
            $sql
        );

        $stmt = $pdo->prepare($countSql);
        $stmt->bindValue(':chatId', $args['id']);
        $stmt->bindValue(':search', $searchTerm);
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        $sql .= " ORDER BY m.date DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':chatId', $args['id']);
        $stmt->bindValue(':search', $searchTerm);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll();

        return $jsonResponse($response, [
            'success' => true,
            'data' => [
                'messages' => $messages,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    });

    /**
     * GET /api/v1/messages/{chatId}/{msgId} - Одно сообщение.
     */
    $app->get('/api/v1/messages/{chatId}/{msgId}', function (Request $request, Response $response, array $args) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();

        $sql = "
            SELECT
                m.*,
                (
                    SELECT COUNT(*)
                    FROM media
                    WHERE
                        message_chat_id = m.chat_id
                        AND message_id = m.id
                ) as media_count
            FROM messages m
            WHERE m.chat_id = ? AND m.id = ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$args['chatId'], $args['msgId']]);
        $message = $stmt->fetch();

        if (!$message) {
            return $jsonResponse(
                $response,
                [
                    'success' => false,
                    'error' => 'Message not found',
                ],
                404
            );
        }

        return $jsonResponse(
            $response,
            [
                'success' => true,
                'data' => $message,
            ]
        );
    });

    /**
     * GET /api/v1/media/{chatId} - Последние медиа чата.
     */
    $app->get('/api/v1/media/{chatId}', function (Request $request, Response $response, array $args) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();
        $limit = (int) ($request->getQueryParams()['limit'] ?? 20);

        $sql = "
            SELECT *
            FROM media
            WHERE
                message_chat_id = ?
                AND downloaded = 1
            ORDER BY id DESC
            LIMIT ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $args['chatId']);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $media = $stmt->fetchAll();

        return $jsonResponse(
            $response,
            [
                'success' => true,
                'data' => $media,
            ]
        );
    });

    /**
     * GET /api/v1/topics/{chatId} - Темы форума.
     */
    $app->get('/api/v1/topics/{chatId}', function (Request $request, Response $response, array $args) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();

        try {
            $sql = "
                SELECT
                    id,
                    title,
                    is_closed,
                    is_pinned,
                    messages_count,
                    last_message_date as last_date
                FROM forum_topics 
                WHERE chat_id = ?
                ORDER BY is_pinned DESC, last_message_date DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$args['chatId']]);
            $topics = $stmt->fetchAll();
        } catch (PDOException $e) {
            // Таблица forum_topics может не существовать
            $topics = [];
        }

        return $jsonResponse(
            $response,
            [
                'success' => true,
                'data' => $topics,
            ]
        );
    });

    /**
     * GET /api/v1/topics/{chatId}/{topicId}/messages - Сообщения темы.
     */
    $app->get('/api/v1/topics/{chatId}/{topicId}/messages', function (Request $request, Response $response, array $args) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Используем позиционные параметры для WHERE
        $sql = "
            SELECT
                m.*,
                (
                    SELECT COUNT(*)
                    FROM media
                    WHERE
                        message_chat_id = m.chat_id
                        AND message_id = m.id
                ) as media_count
            FROM messages m
            WHERE
                m.chat_id = ?
                AND COALESCE(m.topic_id, 0) = ?
        ";

        $countSql = str_replace(
            "
                SELECT
                    m.*,
                    (
                        SELECT COUNT(*)
                        FROM media
                        WHERE
                            message_chat_id = m.chat_id
                            AND message_id = m.id
                    ) as media_count
            ",
            "SELECT COUNT(*)",
            $sql
        );

        $stmt = $pdo->prepare($countSql);
        $stmt->execute([$args['chatId'], $args['topicId']]);
        $total = (int) $stmt->fetchColumn();

        // LIMIT и OFFSET вставляем напрямую (безопасно, так как числа)
        $sql .= " ORDER BY m.date DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$args['chatId'], $args['topicId']]); // Только два параметра
        $messages = $stmt->fetchAll();

        return $jsonResponse($response, [
            'success' => true,
            'data' => [
                'messages' => $messages,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    });

    /**
     * GET /api/v1/stats - Общая статистика.
     */
    $app->get('/api/v1/stats', function (Request $request, Response $response) use ($getPdo, $jsonResponse) {
        $pdo = $getPdo();

        $sql = "
            SELECT
                (SELECT COUNT(*) FROM chats) as chats,
                (SELECT COUNT(*) FROM messages) as messages,
                (SELECT COUNT(*) FROM media WHERE downloaded = 1) as media,
                (SELECT COUNT(*) FROM forum_topics) as topics
        ";

        $stats = $pdo->query($sql)->fetch();

        return $jsonResponse(
            $response,
            [
                'success' => true,
                'data' => $stats
            ]
        );
    });
};
