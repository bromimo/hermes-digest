<?php
declare(strict_types=1);

namespace App\Dedup;

use PDO;

/**
 * Хранилище состояния дедупа на стороне MCP: SQLite-таблица «ключ → список показанных ID».
 */
final class DedupStore
{
    private PDO $pdo;

    /**
     * Открывает (создавая при необходимости) БД дедупа и гарантирует схему.
     *
     * @param string $dbPath путь к файлу SQLite или ":memory:"
     */
    public function __construct(string $dbPath)
    {
        if ($dbPath !== ':memory:') {
            $dir = \dirname($dbPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($dbPath !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode=WAL');
        }
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS dedup (key TEXT PRIMARY KEY, ids TEXT NOT NULL DEFAULT "")');
    }

    /**
     * Возвращает показанные ранее ID для ключа (пустой список, если ключа нет).
     *
     * @param string $key ключ дедупа `digest:<chat_id>:<тема-slug>`
     * @return list<int> ID в порядке хранения (старейшие слева)
     */
    public function seenIds(string $key): array
    {
        $stmt = $this->pdo->prepare('SELECT ids FROM dedup WHERE key = ?');
        $stmt->execute([$key]);
        $ids = $stmt->fetchColumn();
        if ($ids === false || $ids === '') {
            return [];
        }
        return array_values(array_map(
            'intval',
            array_filter(explode(',', (string) $ids), static fn (string $s): bool => $s !== '')
        ));
    }

    /**
     * Сохраняет (upsert) список ID для ключа.
     *
     * @param string $key ключ дедупа
     * @param list<int> $ids список ID
     */
    public function save(string $key, array $ids): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dedup (key, ids) VALUES (:key, :ids)
             ON CONFLICT(key) DO UPDATE SET ids = excluded.ids'
        );
        $stmt->execute([':key' => $key, ':ids' => implode(',', $ids)]);
    }

    /**
     * Выполняет $fn в транзакции (атомарный read-modify-write).
     *
     * @param callable():mixed $fn операция над хранилищем
     * @return mixed результат $fn
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}