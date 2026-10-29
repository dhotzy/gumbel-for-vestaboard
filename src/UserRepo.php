<?php
declare(strict_types=1);

namespace Vesta;

use PDO;

final class UserRepo
{
    private const COLUMNS = [
        'name', 'role', 'login_type', 'secret_hash', 'source', 'can_force', 'default_force', 'can_jump',
        'default_jump', 'can_schedule', 'cooldown_min', 'failed_logins', 'locked_until', 'active', 'created_at',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function findByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE LOWER(name) = LOWER(?)');
        $statement->execute([trim($name)]);
        return $statement->fetch() ?: null;
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM users ORDER BY active DESC, name')->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function countActiveAdmins(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
    }

    /** Zählt einen Login-Versuch atomar hoch und liefert den neuen Stand (sicher gegen parallele Requests). */
    public function countLoginAttempt(int $id): int
    {
        $this->pdo->prepare('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?')->execute([$id]);
        $statement = $this->pdo->prepare('SELECT failed_logins FROM users WHERE id = ?');
        $statement->execute([$id]);
        return (int) $statement->fetchColumn();
    }

    public function insert(array $data): int
    {
        $data = array_intersect_key($data, array_flip(self::COLUMNS));
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO users (%s) VALUES (%s)',
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
        $this->pdo->prepare("UPDATE users SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
    }
}
