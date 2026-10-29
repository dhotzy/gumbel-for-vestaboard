<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;
use PDO;

/** Protokoll aller Board-Aufrufe für die Betreiber-Sicht. */
final class ApiLog
{
    public const KEEP_DAYS = 30;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(?int $messageId, string $action, array $result, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO api_log (message_id, action, http_status, ok, error, duration_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $messageId,
            $action,
            (int) ($result['status'] ?? 0),
            !empty($result['ok']) ? 1 : 0,
            isset($result['error']) ? mb_substr((string) $result['error'], 0, 255) : null,
            (int) ($result['duration_ms'] ?? 0),
            Time::db($now),
        ]);
    }

    public function cleanup(DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare('DELETE FROM api_log WHERE created_at < ?');
        $statement->execute([Time::db($now->modify('-' . self::KEEP_DAYS . ' days'))]);
        return $statement->rowCount();
    }

    public function recent(int $limit = 20): array
    {
        return $this->pdo->query(sprintf('SELECT * FROM api_log ORDER BY id DESC LIMIT %d', $limit))->fetchAll();
    }
}
