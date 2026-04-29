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

// Статистика по чатам
$stmt = $pdo->query("
    SELECT
        c.id,
        c.title,
        c.username,
        c.peer_type,
        COUNT(m.id) as message_count,
        MIN(m.date) as first_message,
        MAX(m.date) as last_message,
        SUM(m.has_media) as media_count
    FROM chats c
    LEFT JOIN messages m ON c.id = m.chat_id
    GROUP BY c.id
    ORDER BY last_message DESC
");

$chats = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Архив "Мезенреализм"</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>"></head>
<body>
<div class="container">
    <h1>📚 Архив "Мезенреализм"</h1>

    <div class="stats">
        Всего чатов: <?= count($chats) ?>
    </div>

    <div class="chats-grid">
        <?php foreach ($chats as $chat): ?>
            <div class="chat-card">
                <div class="chat-header">
                    <h2>
                        <a href="chat.php?id=<?= $chat['id'] ?>">
                            <?= htmlspecialchars($chat['title']) ?>
                        </a>
                    </h2>
                    <?php if ($chat['username']): ?>
                        <div class="username">@<?= htmlspecialchars($chat['username']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="chat-stats">
                    <div>📊 Сообщений: <?= number_format($chat['message_count']) ?></div>
                    <div>🖼️ Медиа: <?= number_format($chat['media_count']) ?></div>
                    <div>📅
                        Первое: <?= $chat['first_message'] ? date('d.m.Y', strtotime($chat['first_message'])) : '—' ?>
                    </div>
                    <div>
                        🕐 Последнее: <?= $chat['last_message'] ? date('d.m.Y H:i', strtotime($chat['last_message'])) : '—' ?>
                    </div>
                </div>

                <div class="chat-type">
                    Тип: <?= $chat['peer_type'] ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
