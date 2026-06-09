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
];

$pdo = new PDO(
    "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['dbname']};charset=utf8mb4",
    $config['db']['user'],
    $config['db']['password']
);

$chatId = $_GET['id'] ?? 0;
$topicId = intval($_GET['topic'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Информация о чате
$stmt = $pdo->prepare("
    SELECT * FROM chats WHERE id = ?
");

$stmt->execute([$chatId]);
$chat = $stmt->fetch();

if (!$chat) {
    die("Чат с таким ID не найден!");
}

// Определяем, является ли чат форумом
$isForum = ($chat['peer_type'] === 'supergroup') && ($chat['is_forum'] ?? false);

var_dump($isForum);

// Получаем список тем (только для форумов)
$topics = [];
$currentTopicTitle = 'Все сообщения';

if ($isForum) {
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.title,
            t.is_closed,
            t.is_pinned,
            COUNT(m.id) as messages_count,
            MAX(m.date) as last_date
        FROM forum_topics t
        LEFT JOIN messages m ON m.chat_id = t.chat_id AND COALESCE(m.topic_id, 0) = t.id
        WHERE t.chat_id = ?
        GROUP BY t.id, t.title, t.is_closed, t.is_pinned
        ORDER BY t.is_pinned DESC, last_date DESC
    ");

    $stmt->execute([$chatId]);
    $topics = $stmt->fetchAll();

    // Добавляем "общие сообщения" (topic_id = 0)
    $stmt = $pdo->prepare("
        SELECT 
            0 as id,
            '📢 Общие сообщения' as title,
            FALSE as is_closed,
            FALSE as is_pinned,
            COUNT(*) as messages_count,
            MAX(date) as last_date
        FROM messages
        WHERE chat_id = ? AND (topic_id IS NULL OR topic_id = 0)
    ");

    $stmt->execute([$chatId]);
    $generalTopic = $stmt->fetch();

    if ($generalTopic && $generalTopic['messages_count'] > 0) {
        $topics = array_merge([$generalTopic], $topics);
    }

    // Находим название текущей темы
    if ($topicId > 0) {
        foreach ($topics as $t) {
            if ($t['id'] == $topicId) {
                $currentTopicTitle = $t['title'];
                break;
            }
        }
    }
}

// Запрос сообщений
$sql = "
    SELECT
        m.*,
        (
            SELECT COUNT(*)
            FROM media
            WHERE message_chat_id = m.chat_id AND message_id = m.id
        ) as media_count
    FROM messages m
    WHERE m.chat_id = ?
";

$params = [$chatId];

if ($topicId > 0) {
    $sql .= " AND COALESCE(m.topic_id, 0) = ?";
    $params[] = $topicId;
} elseif ($isForum) {
    // По умолчанию показываем все сообщения, но можно ограничить общими
    // $sql .= " AND COALESCE(m.topic_id, 0) = 0";
}

$sql .= " ORDER BY m.date DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// Общее количество сообщений (для пагинации)
if ($topicId > 0) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM messages
        WHERE chat_id = ? AND COALESCE(topic_id, 0) = ?
    ");

    $stmt->execute([$chatId, $topicId]);
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM messages
        WHERE chat_id = ?
    ");

    $stmt->execute([$chatId]);
}

$totalMessages = $stmt->fetchColumn();
$totalPages = ceil($totalMessages / $perPage);

function formatMessage($text)
{
    $text = htmlspecialchars($text);

    // Обработка ссылок
    $text = preg_replace(
        '/(https?:\/\/[^\s]+)/',
        '<a href="$1" target="_blank">$1</a>',
        $text
    );

    // Обработка @username (опционально)
    $text = preg_replace(
        '/@([a-zA-Z0-9_]+)/',
        '<a href="https://t.me/$1" target="_blank">@$1</a>',
        $text
    );

    return nl2br($text);
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Чат "<?= htmlspecialchars($chat['title']) ?>" :: Архив "Мезенреализм"</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>"></head>
</head>
<body>
<div class="container">
    <div class="header">
        <a href="index.php" class="back-link">← Назад к чатам</a>
        <h1><?= htmlspecialchars($chat['title']) ?></h1>
        <?php if ($chat['username']): ?>
            <div class="username">@<?= htmlspecialchars($chat['username']) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($isForum && !empty($topics)): ?>
        <div class="forum-topics">
            <div class="topics-header">
                <span>📚 Темы форума (<?= count($topics) ?>)</span>
                <?php if ($topicId > 0): ?>
                    <a href="?id=<?= $chatId ?>" class="show-all">Показать все сообщения →</a>
                <?php endif; ?>
            </div>
            <div class="topics-grid">
                <?php foreach ($topics as $topic): ?>
                    <?php
                    $isActive = ($topicId > 0 && $topic['id'] == $topicId);

                    $topicUrl = $topic['id'] > 0
                        ? "?id={$chatId}&topic={$topic['id']}"
                        : "?id={$chatId}";
                    ?>
                    <a href="<?= $topicUrl ?>"
                       class="topic-card <?= $isActive ? 'active' : '' ?>">
                        <div class="topic-name">
                            <?= htmlspecialchars($topic['title']) ?>
                            <?php if ($topic['is_pinned']): ?>
                                <span class="pinned-badge" title="Закреплено">📌</span>
                            <?php endif; ?>
                            <?php if ($topic['is_closed']): ?>
                                <span class="closed-badge" title="Закрыто">🔒</span>
                            <?php endif; ?>
                        </div>
                        <div class="topic-stats">
                            <span>📊 <?= number_format($topic['messages_count']) ?> сообщ.</span>
                            <?php if ($topic['last_date']): ?>
                                <span>📅 <?= date('d.m.Y', strtotime($topic['last_date'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="messages">
        <?php if (empty($messages)): ?>
            <div class="empty-state">
                <div class="empty-icon">💬</div>
                <div class="empty-text">В этой теме пока нет сообщений</div>
            </div>
        <?php else: ?>
            <?php foreach ($messages as $msg): ?>
                <div class="message" id="message-<?= $msg['id'] ?>">
                    <div class="message-header">
                        <span class="message-id">#<?= $msg['id'] ?></span>
                        <span class="message-date"><?= date('d.m.Y H:i:s', strtotime($msg['date'])) ?></span>

                        <?php if ($msg['edit_date']): ?>
                            <span class="edited" title="Отредактировано">📝 <?= date('d.m.Y H:i', strtotime($msg['edit_date'])) ?></span>
                        <?php endif; ?>

                        <?php if ($msg['views']): ?>
                            <span class="views" title="Просмотров">👁️ <?= number_format($msg['views']) ?></span>
                        <?php endif; ?>

                        <?php if ($msg['forwards']): ?>
                            <span class="forwards" title="Репостов">🔄 <?= number_format($msg['forwards']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($isForum && ($msg['topic_id'] ?? 0) > 0): ?>
                        <?php
                        // Находим название темы для этого сообщения
                        $topicTitle = '';
                        foreach ($topics as $t) {
                            if ($t['id'] == $msg['topic_id']) {
                                $topicTitle = $t['title'];
                                break;
                            }
                        }
                        ?>
                        <div class="message-topic">
                            💬 Тема:
                            <a href="?id=<?= $chatId ?>&topic=<?= $msg['topic_id'] ?>">
                                <?= htmlspecialchars($topicTitle) ?>
                            </a>
                            <span class="topic-id">(#<?= $msg['topic_id'] ?>)</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($msg['text']): ?>
                        <div class="message-text">
                            <?= formatMessage($msg['text']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($msg['media_count'] > 0): ?>
                        <div class="message-media">
                            <a href="message.php?chat_id=<?= $msg['chat_id'] ?>&msg_id=<?= $msg['id'] ?>"
                               class="media-link">
                                🖼️ Медиа (<?= $msg['media_count'] ?>)
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ($msg['reply_to_msg_id']): ?>
                        <div class="reply-info">
                            ↪️ В ответ на
                            <a href="#message-<?= $msg['reply_to_msg_id'] ?>">
                                сообщение #<?= $msg['reply_to_msg_id'] ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?id=<?= $chatId ?>&page=<?= $page - 1 ?>">← Предыдущая</a>
            <?php endif; ?>

            <span class="page-info">
                Страница <?= $page ?> из <?= $totalPages ?>
            </span>

            <?php if ($page < $totalPages): ?>
                <a href="?id=<?= $chatId ?>&page=<?= $page + 1 ?>">Следующая →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
