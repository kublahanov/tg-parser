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
$perPage = 50;
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

$stmt = $pdo->prepare("
    SELECT *
    FROM messages
    WHERE chat_id = $chatId
    ORDER BY date DESC
    LIMIT $perPage OFFSET $offset
");

$stmt->execute();
$messages = $stmt->fetchAll();
$errors = $stmt->errorInfo();

// Общее количество сообщений
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM messages WHERE chat_id = ?
");

$stmt->execute([$chatId]);
$totalMessages = $stmt->fetchColumn();
$totalPages = ceil($totalMessages / $perPage);

// Медиа для быстрого доступа
// $stmt = $pdo->prepare("
//     SELECT * FROM media
//     WHERE message_chat_id = ? AND downloaded = 1
//     ORDER BY id DESC
//     LIMIT 20
// ");

// $stmt->execute([$chatId]);
// $recentMedia = $stmt->fetchAll();
$recentMedia = null;

function formatMessage($text)
{
    $text = htmlspecialchars($text);

    // Простая обработка ссылок
    $text = preg_replace(
        '/(https?:\/\/[^\s]+)/',
        '<a href="$1" target="_blank">$1</a>',
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

    <?php if (!empty($recentMedia)): ?>
        <div class="recent-media">
            <h3>🖼️ Последние медиа</h3>
            <div class="media-grid">
                <?php foreach ($recentMedia as $media): ?>
                    <div class="media-thumb">
                        <a href="media.php?id=<?= $media['id'] ?>">
                            <?php if ($media['media_type'] === 'photo'): ?>
                                <img src="../media/<?= $media['file_path'] ?>" alt="Photo">
                            <?php else: ?>
                                <div class="media-icon">
                                    <?= $media['media_type'] === 'video'
                                        ? '🎬'
                                        : ($media['media_type'] === 'audio' ? '🎵' : '📄') ?>
                                </div>
                            <?php endif; ?>
                            <div class="media-name">
                                <?= basename($media['file_name'] ?? $media['file_path']) ?>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="messages">
        <?php foreach ($messages as $msg): ?>
            <div class="message" id="message-<?= $msg['id'] ?>">
                <div class="message-header">
                    <span class="message-id">#<?= $msg['id'] ?></span>
                    <span class="message-date"><?= date('d.m.Y H:i:s', strtotime($msg['date'])) ?></span>

                    <?php if ($msg['edit_date']): ?>
                        <span class="edited" title="Отредактировано">📝 <?= date('d.m.Y H:i', strtotime($msg['edit_date'])) ?></span>
                    <?php endif; ?>

                    <?php if ($msg['views']): ?>
                        <span class="views" title="Просмотрено">👁️ <?= number_format($msg['views']) ?></span>
                    <?php endif; ?>

                    <?php if ($msg['forwards']): ?>
                        <span class="forwards" title="Перенаправлено">🔄 <?= number_format($msg['forwards']) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($msg['text']): ?>
                    <div class="message-text">
                        <?= formatMessage($msg['text']) ?>
                    </div>
                <?php endif; ?>

                <?php //if ($msg['media_count'] > 0): ?>
                <?php
                    /*
                    <div class="message-media">
                        <a href="message.php?chat_id=<?= $msg['chat_id'] ?>&msg_id=<?= $msg['id'] ?>"
                           class="media-link">
                            🖼️ Медиа (<?= $msg['media_count'] ?>)
                        </a>
                    </div>
                    */
                ?>
                <?php //endif; ?>

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
