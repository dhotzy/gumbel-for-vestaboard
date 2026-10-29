<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;
use PDO;

final class MessageRepo
{
    private const COLUMNS = [
        'source', 'user_id', 'text_raw', 'align', 'grid', 'forced', 'jump', 'sent_forced', 'status',
        'not_before', 'expires_at', 'created_at', 'updated_at', 'shown_at', 'ended_at',
        'attempts', 'last_attempt_at', 'last_error', 'deleted_by', 'deleted_at',
        'reply_text', 'reply_by', 'reply_at', 'reply_seen_at', 'min_duration', 'guest_name', 'guest_key',
    ];

    private const SELECT = 'SELECT m.*, COALESCE(u.name, m.guest_name) AS user_name, r.name AS reply_name FROM messages m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN users r ON r.id = m.reply_by';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(array $data): int
    {
        $data = array_intersect_key($data, array_flip(self::COLUMNS));
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO messages (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->pdo->prepare($sql)->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::COLUMNS));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($fields)));
        $this->pdo->prepare("UPDATE messages SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE m.id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function current(): ?array
    {
        $statement = $this->pdo->query(self::SELECT . " WHERE m.status = 'showing' ORDER BY m.shown_at DESC, m.id DESC LIMIT 1");
        return $statement->fetch() ?: null;
    }

    /** Alle wartenden Nachrichten (auch geplante), älteste zuerst. */
    public function queued(): array
    {
        return $this->pdo->query(self::SELECT . " WHERE m.status = 'queued' ORDER BY m.created_at, m.id")->fetchAll();
    }

    public function failed(): array
    {
        return $this->pdo->query(self::SELECT . " WHERE m.status = 'failed' ORDER BY m.created_at DESC, m.id DESC")->fetchAll();
    }

    /** Beendet alle laufenden Anzeigen (vor dem Senden einer neuen). */
    public function endShowing(DateTimeImmutable $now): void
    {
        $this->pdo->prepare("UPDATE messages SET status = 'shown', ended_at = COALESCE(ended_at, ?) WHERE status = 'showing'")
            ->execute([Time::db($now)]);
    }

    public function expireDue(DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            "UPDATE messages SET status = 'expired', ended_at = ? WHERE status = 'queued' AND expires_at IS NOT NULL AND expires_at <= ?"
        );
        $nowDb = Time::db($now);
        $statement->execute([$nowDb, $nowDb]);
        return $statement->rowCount();
    }

    public function lastCreatedAtByUser(int $userId): ?string
    {
        $statement = $this->pdo->prepare('SELECT MAX(created_at) FROM messages WHERE user_id = ?');
        $statement->execute([$userId]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Archiv: alles außer wartenden Nachrichten, neueste zuerst (nach Anzeigezeit).
     * @param array{since?: ?string, user_id?: ?int, status?: ?string, source?: ?string} $filters
     */
    public function history(array $filters, int $limit, int $offset = 0): array
    {
        $where = ["m.status IN ('showing', 'shown', 'expired', 'deleted', 'failed')"];
        $params = [];
        if (!empty($filters['since'])) {
            $where[] = 'COALESCE(m.shown_at, m.created_at) >= ?';
            $params[] = $filters['since'];
        }
        if (!empty($filters['source'])) {
            $where[] = 'm.source = ?';
            $params[] = $filters['source'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'm.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'm.status = ?';
            $params[] = $filters['status'];
        }
        $sql = self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . sprintf(' ORDER BY COALESCE(m.shown_at, m.created_at) DESC, m.id DESC LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset));
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /** Wartende Nachrichten eines Nutzers verwerfen (z. B. beim Deaktivieren). @return int Anzahl */
    public function dropQueuedOfUser(int $userId, int $byUserId, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare("UPDATE messages SET status = 'deleted', deleted_by = ?, deleted_at = ? WHERE user_id = ? AND status = 'queued'");
        $statement->execute([$byUserId, Time::db($now), $userId]);
        return $statement->rowCount();
    }

    /** Antworten des Admins, die der Nutzer noch nicht gesehen hat */
    public function unseenReplies(int $userId): array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE m.user_id = ? AND m.reply_text IS NOT NULL AND m.reply_seen_at IS NULL ORDER BY m.reply_at DESC LIMIT 5');
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }

    /** Endgültig entfernen (nur Admin, nur aus dem Verlauf) */
    public function remove(int $id): void
    {
        $this->pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([$id]);
    }

    public function countQueued(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM messages WHERE status = 'queued'")->fetchColumn();
    }
}
