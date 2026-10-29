<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;
use PDO;

/**
 * Bremse für Anmeldeversuche, je Schlüssel (z. B. „ip:1.2.3.4“ oder „u:7|1.2.3.4“).
 * Nach $max Versuchen wird gesperrt; jede weitere Sperre dauert doppelt so lang (bis 24 Std).
 */
final class LoginThrottle
{
    private const MAX_LOCK_MINUTES = 1440;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return int Sekunden bis zum Ende der Sperre (0 = nicht gesperrt) */
    public function lockedFor(string $key, DateTimeImmutable $now): int
    {
        $row = $this->row($key);
        if ($row === null || $row['locked_until'] === null) {
            return 0;
        }
        return max(0, Time::secondsBetween($now, Time::fromDb((string) $row['locked_until'])));
    }

    /**
     * Versuch zählen (atomar, auch bei parallelen Anfragen). Wird $max überschritten, folgt eine Sperre.
     * @return bool true = noch erlaubt, false = jetzt gesperrt
     */
    public function hit(string $key, int $max, int $lockMinutes, DateTimeImmutable $now): bool
    {
        $update = $this->pdo->prepare('UPDATE login_throttle SET failures = failures + 1, updated_at = ? WHERE throttle_key = ?');
        $update->execute([Time::db($now), $key]);
        if ($update->rowCount() === 0) {
            try {
                $this->pdo->prepare('INSERT INTO login_throttle (throttle_key, failures, locks, locked_until, updated_at) VALUES (?, 1, 0, NULL, ?)')->execute([$key, Time::db($now)]);
            } catch (\PDOException) {
                $update->execute([Time::db($now), $key]); // paralleler Request hat die Zeile gerade angelegt
            }
        }
        $row = $this->row($key);
        if ($row === null || (int) $row['failures'] <= $max) {
            return true;
        }
        $minutes = min(self::MAX_LOCK_MINUTES, $lockMinutes * (2 ** min(10, (int) $row['locks'])));
        $this->pdo->prepare('UPDATE login_throttle SET failures = 0, locks = locks + 1, locked_until = ? WHERE throttle_key = ?')
            ->execute([Time::db($now->modify("+$minutes minutes")), $key]);
        return false;
    }

    public function reset(string $key): void
    {
        $this->pdo->prepare('DELETE FROM login_throttle WHERE throttle_key = ?')->execute([$key]);
    }

    /** Alte Einträge entfernen (ohne Sperre, seit 2 Tagen unberührt) */
    public function cleanup(DateTimeImmutable $now): void
    {
        $this->pdo->prepare('DELETE FROM login_throttle WHERE updated_at < ? AND (locked_until IS NULL OR locked_until < ?)')
            ->execute([Time::db($now->modify('-2 days')), Time::db($now)]);
    }

    private function row(string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM login_throttle WHERE throttle_key = ?');
        $statement->execute([$key]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}
