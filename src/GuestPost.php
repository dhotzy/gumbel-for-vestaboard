<?php
declare(strict_types=1);

namespace Vesta;

use PDO;

/**
 * Briefkasten: Gäste schicken ohne Konto eine Nachricht – über einen geheimen Link bzw. QR-Code.
 * Nur Text mit Farben/Herz, keine weiteren Funktionen. Schutz: geheimer Link (erneuerbar), Pause pro Gerät,
 * Höchstzahl pro Stunde, Höchstzahl wartender Gast-Nachrichten, Ablauf, Honigtopf-Feld.
 */
final class GuestPost
{
    public const SOURCE = 'guest';
    public const MAX_NAME = 20;
    public const DEFAULTS = [
        'enabled' => false,
        'token' => '',
        'can_force' => false,
        'jump' => false,
        'cooldown_min' => 10,
        'max_per_hour' => 10,
        'max_waiting' => 5,
        'expires_min' => 120,
    ];
    public const COOLDOWNS = [1, 5, 10, 30, 60];
    public const PER_HOUR = [3, 5, 10, 20, 50];
    public const WAITING = [1, 3, 5, 10];
    public const EXPIRES = [30, 60, 120, 240, 1440];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Settings $settings,
        private readonly MessageRepo $messages,
        private readonly Clock $clock,
    ) {
    }

    public function config(): array
    {
        return array_replace(self::DEFAULTS, (array) $this->settings->get('guest_post'));
    }

    public function save(array $values): void
    {
        $this->settings->set('guest_post', array_replace($this->config(), array_intersect_key($values, self::DEFAULTS)));
    }

    /** Neuer geheimer Link – alte Links und gedruckte QR-Codes funktionieren danach nicht mehr */
    public function regenerateToken(): string
    {
        $token = bin2hex(random_bytes(12));
        $this->save(['token' => $token]);
        return $token;
    }

    public function isOpen(string $token): bool
    {
        $config = $this->config();
        return $config['enabled'] && $config['token'] !== '' && hash_equals((string) $config['token'], $token);
    }

    /** Absolute Adresse der Gast-Seite (für Link und QR-Code) */
    public static function url(string $token): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) ? $host : 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return (Auth::isHttps() ? 'https' : 'http') . '://' . $host . $dir . '/post.php?t=' . rawurlencode($token);
    }

    /** Gerät wiedererkennen ohne die IP zu speichern */
    public static function clientKey(): string
    {
        $ip = Auth::clientIp();
        // IPv6: ein Anschluss hat ein ganzes /64 – sonst wechselt man einfach die Adresse und umgeht die Pause
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $ip = substr(bin2hex((string) inet_pton($ip)), 0, 16) . '::/64';
        }
        return hash_hmac('sha256', $ip, 'gumbel-guest|' . (string) (app_config()['cron_key'] ?? ''));
    }

    /**
     * @param array{text?: string, name?: string, forced?: mixed, website?: string} $input
     * @return array{ok: bool, error: ?string, id: ?int, position?: int}
     */
    public function submit(array $input, string $clientKey): array
    {
        $config = $this->config();
        $now = $this->clock->now();
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return ['ok' => true, 'error' => null, 'id' => null, 'position' => 1]; // Honigtopf: Bots bekommen „ok“, nichts wird angelegt
        }
        $text = trim((string) ($input['text'] ?? ''));
        if (!mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 200) {
            return self::fail(t('Der Text enthält ungültige Zeichen.'));
        }
        if ($text === '') {
            return self::fail(t('Bitte einen Text eingeben.'));
        }
        $preview = Charset::textToGrid($text, 'center');
        if (!$preview['fits']) {
            return self::fail(t('Der Text passt nicht auf das Board (max. 3 Zeilen à 15 Zeichen).'));
        }
        $name = trim((string) preg_replace('/[\p{C}<>]/u', '', (string) ($input['name'] ?? '')));
        $name = mb_substr($name, 0, self::MAX_NAME);

        // Prüfen und Einfügen unter einer Sperre – sonst kommen parallele Anfragen alle durch die Limits
        $lock = new Lock(APP_ROOT . '/var/guest.lock');
        if (!$lock->acquire(5)) {
            return self::fail(t('Der Briefkasten ist gerade voll – bitte später noch einmal versuchen.'));
        }
        try {
            return $this->checkAndInsert($config, $text, $preview['grid'], $name, $input, $clientKey);
        } finally {
            $lock->release();
        }
    }

    private function checkAndInsert(array $config, string $text, array $grid, string $name, array $input, string $clientKey): array
    {
        $now = $this->clock->now();
        $since = static fn (int $minutes): string => Time::db($now->modify('-' . $minutes . ' minutes'));
        $statement = $this->pdo->prepare('SELECT MAX(created_at) FROM messages WHERE source = ? AND guest_key = ? AND created_at > ?');
        $statement->execute([self::SOURCE, $clientKey, $since((int) $config['cooldown_min'])]);
        $last = $statement->fetchColumn();
        if ($last) {
            $wait = (int) $config['cooldown_min'] * 60 - Time::secondsBetween(Time::fromDb((string) $last), $now);
            return self::fail(t('Bitte warte noch {duration}, bevor du die nächste Nachricht schickst.', ['duration' => Time::duration(max(1, $wait))]));
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM messages WHERE source = ? AND created_at > ?');
        $statement->execute([self::SOURCE, $since(60)]);
        if ((int) $statement->fetchColumn() >= (int) $config['max_per_hour']) {
            return self::fail(t('Der Briefkasten ist gerade voll – bitte später noch einmal versuchen.'));
        }
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM messages WHERE source = ? AND status = 'queued'");
        $statement->execute([self::SOURCE]);
        if ((int) $statement->fetchColumn() >= (int) $config['max_waiting']) {
            return self::fail(t('Es warten schon einige Gast-Nachrichten – bitte später noch einmal versuchen.'));
        }

        $id = $this->messages->insert([
            'source' => self::SOURCE,
            'user_id' => null,
            'guest_name' => $name === '' ? null : $name,
            'guest_key' => $clientKey,
            'text_raw' => $text,
            'align' => 'center',
            'grid' => json_encode($grid),
            'forced' => $config['can_force'] && !empty($input['forced']) ? 1 : 0,
            'jump' => $config['jump'] ? 1 : 0,
            'status' => 'queued',
            'expires_at' => Time::db($now->modify('+' . (int) $config['expires_min'] . ' minutes')),
            'created_at' => Time::db($now),
        ]);
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM messages WHERE status = 'queued' AND id <= ?");
        $statement->execute([$id]);
        return ['ok' => true, 'error' => null, 'id' => $id, 'position' => max(1, (int) $statement->fetchColumn())];
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'id' => null];
    }
}
