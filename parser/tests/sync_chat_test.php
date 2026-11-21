<?php

require_once __DIR__ . '/../src/Collector.php';

class TestCollector extends Collector
{
    private PDO $db;
    private array $history;

    public function __construct(PDO $db, array $history)
    {
        $this->db = $db;
        $this->history = $history;
        (new ReflectionProperty(Collector::class, 'pdo'))->setValue($this, $db);
    }

    protected function apiRequest($method, $params = [])
    {
        if ($method !== 'messages.getHistory') {
            throw new RuntimeException("Unexpected method: $method");
        }

        $older = array_values(array_filter(
            $this->history,
            static fn ($message) => !$params['offset_id'] || $message['id'] < $params['offset_id']
        ));

        return ['messages' => array_slice($older, 0, $params['limit'])];
    }

    protected function saveSenders(array $response): void {}
    protected function processMedia($chatId, $messageId, $media) { return false; }
    protected function prepareForNextStep() {}
    protected function echo(string $message, string $type = null) {}
    protected function startSyncLog($chatId) { return 1; }
    protected function finishSyncLog($logId, $status, $added, $media, $error = null) {}

    protected function saveMessage($chatId, $msg)
    {
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO messages (id, chat_id) VALUES (?, ?)');
        $stmt->execute([$msg['id'], $chatId]);

        return $stmt->rowCount() === 1;
    }
}

function runCase(string $name, int $oldest, int $newest, bool $complete, int $expectedAdded, int $expectedTotal): void
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE chats (id INTEGER PRIMARY KEY, title TEXT, is_old_uploaded INTEGER)');
    $db->exec('CREATE TABLE messages (id INTEGER, chat_id INTEGER, PRIMARY KEY (id, chat_id))');
    $db->exec("INSERT INTO chats VALUES (1, 'test', " . (int) $complete . ')');

    for ($id = $oldest; $id <= $newest; $id++) {
        $db->exec("INSERT INTO messages VALUES ($id, 1)");
    }

    $history = [];

    for ($id = 250; $id >= 1; $id--) {
        $history[] = ['id' => $id, 'date' => $id];
    }

    $result = (new TestCollector($db, $history))->syncChat(1);
    $stored = (int) $db->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    $flag = (int) $db->query('SELECT is_old_uploaded FROM chats')->fetchColumn();

    if ($result['messages_added'] !== $expectedAdded || $stored !== $expectedTotal || $flag !== 1) {
        throw new RuntimeException("$name: added={$result['messages_added']}, stored=$stored, old_uploaded=$flag");
    }

    echo "$name: OK\n";
}

runCase('initial load', 1, 0, false, 250, 250);
runCase('resume old history', 151, 250, false, 150, 250);
runCase('fetch new messages', 1, 150, true, 100, 250);
runCase('already current', 1, 250, true, 0, 250);

$db = new PDO('sqlite::memory:');
$history = [];

for ($id = 250; $id >= 1; $id--) {
    $history[] = ['id' => $id, 'date' => $id];
}

$collector = new TestCollector($db, $history);
$cursor = 251;
$counts = [];

do {
    $page = $collector->syncSendersPage(1, $cursor);
    $cursor = $page['last_id'];
    $counts[] = $page['count'];
} while ($page['count'] > 0);

if ($counts !== [100, 100, 50, 0]) {
    throw new RuntimeException('Sender cursor pagination failed: ' . json_encode($counts));
}

echo "sender cursor: OK\n";
