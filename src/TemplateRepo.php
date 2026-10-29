<?php
declare(strict_types=1);

namespace Vesta;

use PDO;

/**
 * Gespeicherte Vorlagen („Als Vorlage speichern“) – pro Nutzer, der Admin sieht alle.
 */
final class TemplateRepo
{
    public const MAX_PER_USER = 100;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array> */
    public function forUser(array $user): array
    {
        if (MessageService::isAdmin($user)) {
            return $this->pdo->query('SELECT t.*, u.name AS user_name FROM templates t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.name')->fetchAll();
        }
        $statement = $this->pdo->prepare('SELECT t.*, u.name AS user_name FROM templates t LEFT JOIN users u ON u.id = t.user_id WHERE t.user_id = ? ORDER BY t.name');
        $statement->execute([(int) $user['id']]);
        return $statement->fetchAll();
    }

    public function find(array $user, int $id): ?array
    {
        foreach ($this->forUser($user) as $template) {
            if ((int) $template['id'] === $id) {
                return $template;
            }
        }
        return null;
    }

    /** @return array{ok: bool, error: ?string, id: ?int} */
    public function save(array $user, string $name, string $text, string $align, string $now): array
    {
        $name = trim($name);
        $align = Charset::align($align);
        $text = $align === 'grid' ? rtrim($text) : trim($text);
        if (trim($text) === '' || !Charset::textToGrid($text, $align)['fits']) {
            return ['ok' => false, 'error' => t('Die Vorlage braucht einen Text, der aufs Board passt.'), 'id' => null];
        }
        if ($name === '') {
            $name = mb_strimwidth(trim((string) preg_replace('/\{\w+\}|\s+/', ' ', $text)), 0, 30, '…') ?: t('Vorlage');
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM templates WHERE user_id = ?');
        $count->execute([(int) $user['id']]);
        if ((int) $count->fetchColumn() >= self::MAX_PER_USER) {
            return ['ok' => false, 'error' => t('Maximal {max} Vorlagen – bitte zuerst alte löschen.', ['max' => self::MAX_PER_USER]), 'id' => null];
        }
        $this->pdo->prepare('INSERT INTO templates (user_id, name, text_raw, align, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([(int) $user['id'], mb_substr($name, 0, 60), $text, $align, $now]);
        return ['ok' => true, 'error' => null, 'id' => (int) $this->pdo->lastInsertId()];
    }

    public function delete(array $user, int $id): bool
    {
        if ($this->find($user, $id) === null) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM templates WHERE id = ?')->execute([$id]);
        return true;
    }
}
