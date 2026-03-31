<?php

/**
 * Скрипт для отображения количества ссылок по всем чатам.
 */

$config = [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'mysql',
        'port' => getenv('DB_PORT') ?: 3306,
        'dbname' => getenv('DB_NAME') ?: 'telegram_archive',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
];

$pdo = new PDO(
    "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['dbname']};charset=utf8mb4",
    $config['db']['user'],
    $config['db']['password']
);

$stmt = $pdo->prepare("
    SELECT
        id, text
    FROM messages
    WHERE
        text IS NOT NULL
        AND text LIKE '%http%'
        -- AND chat_id = -1001432413295
    -- LIMIT 100
");

$stmt->execute();
$messages = $stmt->fetchAll();

// --- Функция извлечения домена ---
function extractDomainFromUrl($url)
{
    // Удаляем лишние символы на конце
    $url = rtrim($url, '/\\s<>"{}|\\^`[]');

    // Парсим URL
    $parsed = parse_url($url);

    if (!$parsed || !isset($parsed['host'])) {
        return false;
    }

    $domain = $parsed['host'];

    // Убираем www.
    $domain = preg_replace('/^www\./i', '', $domain);

    // Возвращаем только домен (или с протоколом — как вам удобнее)
    return $domain; // Например: 't.me'
}

// --- Сбор статистики по доменам ---
$domainCounts = [];

foreach ($messages as $message) {
    preg_match_all('/https?:\/\/[^\s<>"{}|\\\\^`\[\]]+/', $message['text'], $matches);

    if (!empty($matches[0])) {
        foreach ($matches[0] as $url) {
            $domain = extractDomainFromUrl($url);

            if ($domain) {
                $domainCounts[$domain] = ($domainCounts[$domain] ?? 0) + 1;
            }
        }
    }
}

// --- Преобразуем в нумерованный массив ---
$result = [];
$counter = 1;

foreach ($domainCounts as $domain => $count) {
    $result[] = [
        'id' => $counter++,
        'domain' => $domain,
        'count' => $count,
    ];
}

// --- Сортировка по убыванию количества встреч ---
usort($result, function($a, $b) {
    return $b['count'] <=> $a['count'];
});

// --- Вывод таблицы в консоль ---
if (empty($result)) {
    echo "❌ Нет найденных ссылок.\n";
    exit;
}

$headers = ['ID', 'URL', 'Count'];

// Определяем ширину колонок
$idWidth = max(strlen($headers[0]), ...array_map('mb_strlen', array_column($result, 'id')));
$domainWidth = max(strlen($headers[1]), ...array_map('mb_strlen', array_column($result, 'domain')));
$countWidth = max(strlen($headers[2]), ...array_map('mb_strlen', array_column($result, 'count')));

// Верхняя граница
echo "┌" . str_repeat("─", $idWidth + 2)
    . "┬" . str_repeat("─", $domainWidth + 2)
    . "┬" . str_repeat("─", $countWidth + 2) . "┐" . "\n";

// Заголовок
printf(
    "│ %{$idWidth}s │ %{$domainWidth}s │ %{$countWidth}s │\n",
    'ID', 'URL', 'Count'
);

// Разделитель
echo "├" . str_repeat("─", $idWidth + 2)
    . "┼" . str_repeat("─", $domainWidth + 2)
    . "┼" . str_repeat("─", $countWidth + 2) . "┤" . "\n";

// Данные
foreach ($result as $row) {
    printf(
        "│ %{$idWidth}d │ %{$domainWidth}s │ %{$countWidth}d │\n",
        $row['id'], $row['domain'], $row['count']
    );
}

// Нижняя граница
echo "└" . str_repeat("─", $idWidth + 2)
    . "┴" . str_repeat("─", $domainWidth + 2)
    . "┴" . str_repeat("─", $countWidth + 2) . "┘" . "\n";
