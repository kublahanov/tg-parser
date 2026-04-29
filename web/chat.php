<?php

// echo '<pre>';

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

// echo 'Config: ' . PHP_EOL;
// var_dump($config);

$pdo = new PDO(
    "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['dbname']};charset=utf8mb4",
    $config['db']['user'],
    $config['db']['password']
);

$chatId = $_GET['id'] ?? 0;
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Информация о чате
$stmt = $pdo->prepare("
    SELECT * FROM chats WHERE id = ?
");

$stmt->execute([$chatId]);
$chat = $stmt->fetch();

// echo PHP_EOL;
// echo 'Chat: ' . $chat['title'] . PHP_EOL;
// var_dump($chat);

if (!$chat) {
    die("Chat not found");
}

// echo PHP_EOL;
// echo 'Limit & offset: ' . PHP_EOL;
// var_dump([$perPage, $offset]);

// Сообщения с пагинацией
// $stmt = $pdo->prepare("
//     SELECT
//         m.* --,
//         -- (
//         --     SELECT COUNT(*)
//         --     FROM media
//         --     WHERE
//         --         message_chat_id = m.chat_id
//         --         AND message_id = m.id
//         -- ) as media_count
//     FROM messages m
//     WHERE m.chat_id = $chatId
//     ORDER BY m.date DESC
//     LIMIT $perPage OFFSET $offset
// ");

$stmt = $pdo->prepare("
    SELECT *
    FROM messages
    WHERE chat_id = $chatId
    ORDER BY date DESC
    LIMIT $perPage OFFSET $offset
");

// $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_INT);
// $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
// $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$messages = $stmt->fetchAll();
$errors = $stmt->errorInfo();

// echo PHP_EOL;
// echo 'Messages: ' . PHP_EOL;
// var_dump($messages);
// var_dump($errors);

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

// echo PHP_EOL;
// echo 'Recent media: ' . PHP_EOL;
// var_dump($recentMedia);
// exit;

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
                        <span class="edited">(ред. <?= date('d.m.Y H:i', strtotime($msg['edit_date'])) ?>)</span>
                    <?php endif; ?>
                    <?php if ($msg['views']): ?>
                        <span class="views">👁️ <?= number_format($msg['views']) ?></span>
                    <?php endif; ?>
                    <?php if ($msg['forwards']): ?>
                        <span class="forwards">🔄 <?= number_format($msg['forwards']) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($msg['text']): ?>
                    <div class="message-text">
                        <?= formatMessage($msg['text']) ?>
                    </div>
                <?php endif; ?>

                <?php //if ($msg['media_count'] > 0): ?>
                <!--    <div class="message-media">-->
                <!--        <a href="message.php?chat_id=--><?php //= $msg['chat_id'] ?><!--&msg_id=--><?php //= $msg['id'] ?><!--"-->
                <!--           class="media-link">-->
                <!--            🖼️ Медиа (--><?php //= $msg['media_count'] ?><!--)-->
                <!--        </a>-->
                <!--    </div>-->
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
