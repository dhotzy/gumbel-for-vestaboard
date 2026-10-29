<?php
declare(strict_types=1);

namespace Vesta;

use PDO;

/**
 * Messenger-Anbindung: Nachrichten aus Telegram (Bot, per Abfrage im Cron – klappt auch ohne öffentliche Adresse)
 * und Slack (Slash-Befehl, braucht eine öffentlich erreichbare Installation) landen als Quelle „Messenger“ auf dem Board.
 * Telegram-Chats müssen vom Admin freigegeben werden; unbekannte Chats erscheinen als Anfrage.
 */
final class Messenger
{
    public const SOURCE = 'messenger';
    public const MAX_PENDING = 10;
    public const MAX_ALLOWED = 30;
    public const SLACK_TOLERANCE = 300;
    public const DEFAULTS = [
        'cooldown_min' => 1,
        'expires_min' => 120,
        'telegram' => ['enabled' => false, 'token' => '', 'bot' => '', 'offset' => 0, 'allowed' => [], 'pending' => []],
        'slack' => ['enabled' => false, 'secret' => ''],
    ];
    public const COOLDOWNS = [0, 1, 5, 10, 30];

    /** @param null|callable(string $url, array $params): ?array $http für Tests ersetzbar (Telegram-API) */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Settings $settings,
        private readonly MessageRepo $messages,
        private readonly Clock $clock,
        private $http = null,
    ) {
        $this->http ??= [self::class, 'postJson'];
    }

    public function config(): array
    {
        $config = array_replace_recursive(self::DEFAULTS, (array) $this->settings->get('messenger'));
        // Listen nicht mit den Defaults vermischen
        foreach (['allowed', 'pending'] as $list) {
            $config['telegram'][$list] = (array) (((array) $this->settings->get('messenger'))['telegram'][$list] ?? []);
        }
        return $config;
    }

    public function save(array $config): void
    {
        $this->settings->set('messenger', $config);
    }

    /**
     * Eine Messenger-Nachricht aufs Board stellen.
     * @return array{ok: bool, reply: string, id?: int}
     */
    public function accept(string $senderKey, string $senderName, string $text): array
    {
        $config = $this->config();
        $now = $this->clock->now();
        $text = Charset::fit(trim($text));
        if ($text === '') {
            return ['ok' => false, 'reply' => t('Bitte einen Text schicken – er erscheint dann auf dem Board.')];
        }
        $cooldown = (int) $config['cooldown_min'];
        if ($cooldown > 0) {
            $statement = $this->pdo->prepare('SELECT MAX(created_at) FROM messages WHERE source = ? AND guest_key = ? AND created_at > ?');
            $statement->execute([self::SOURCE, $senderKey, Time::db($now->modify('-' . $cooldown . ' minutes'))]);
            $last = $statement->fetchColumn();
            if ($last) {
                $wait = $cooldown * 60 - Time::secondsBetween(Time::fromDb((string) $last), $now);
                return ['ok' => false, 'reply' => t('Bitte warte noch {duration}, bevor du die nächste Nachricht schickst.', ['duration' => Time::duration(max(1, $wait))])];
            }
        }
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM messages WHERE source = ? AND status = 'queued'");
        $statement->execute([self::SOURCE]);
        if ((int) $statement->fetchColumn() >= Inbound::MAX_QUEUED_PER_SOURCE) {
            return ['ok' => false, 'reply' => t('Es warten schon sehr viele Nachrichten – bitte später noch einmal versuchen.')];
        }
        $id = $this->messages->insert([
            'source' => self::SOURCE,
            'guest_name' => mb_substr($senderName, 0, 40),
            'guest_key' => $senderKey,
            'text_raw' => $text,
            'align' => 'center',
            'grid' => json_encode(Charset::textToGrid($text)['grid']),
            'status' => 'queued',
            'expires_at' => Time::db($now->modify('+' . (int) $config['expires_min'] . ' minutes')),
            'created_at' => Time::db($now),
        ]);
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM messages WHERE status = 'queued' AND id <= ?");
        $statement->execute([$id]);
        $position = max(1, (int) $statement->fetchColumn());
        return ['ok' => true, 'id' => $id, 'reply' => $position <= 1 ? t('✓ Kommt gleich aufs Board.') : t('✓ Ist in der Warteschlange (Platz {position}).', ['position' => $position])];
    }

    // ---------- Telegram

    /** Bot-Token prüfen (getMe), Webhook abschalten (wir fragen selbst ab) und verbinden */
    public function connectTelegram(string $token): array
    {
        if (!preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $token)) {
            return ['ok' => false, 'error' => t('Das sieht nicht wie ein Bot-Token aus (Format 123456:ABC…, von @BotFather).')];
        }
        $me = ($this->http)(self::telegramUrl($token, 'getMe'), []);
        if (!is_array($me) || empty($me['ok']) || empty($me['result']['username'])) {
            return ['ok' => false, 'error' => t('Telegram kennt diesen Bot-Token nicht.')];
        }
        ($this->http)(self::telegramUrl($token, 'deleteWebhook'), []);
        $config = $this->config();
        $config['telegram'] = ['enabled' => true, 'token' => $token, 'bot' => (string) $me['result']['username'], 'offset' => 0] + $config['telegram'];
        $this->save($config);
        return ['ok' => true, 'error' => null, 'bot' => (string) $me['result']['username']];
    }

    public function disconnectTelegram(): void
    {
        $config = $this->config();
        $config['telegram'] = self::DEFAULTS['telegram'];
        $this->save($config);
    }

    /** Cron: neue Telegram-Nachrichten abholen. @return array{received: int, error?: string} */
    public function pollTelegram(): array
    {
        $config = $this->config();
        $telegram = $config['telegram'];
        if (!$telegram['enabled'] || $telegram['token'] === '') {
            return ['received' => 0];
        }
        $response = ($this->http)(self::telegramUrl($telegram['token'], 'getUpdates'), ['offset' => (int) $telegram['offset'], 'timeout' => 0, 'limit' => 20, 'allowed_updates' => ['message']]);
        if (!is_array($response) || empty($response['ok'])) {
            return ['received' => 0, 'error' => t('Telegram nicht erreichbar.')];
        }
        $received = 0;
        $offset = (int) $telegram['offset'];
        $newPending = [];
        foreach ((array) $response['result'] as $update) {
            $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
            $message = $update['message'] ?? null;
            if (!is_array($message) || !isset($message['chat']['id']) || ($message['chat']['type'] ?? 'private') !== 'private') {
                continue; // nur Einzel-Chats, keine Gruppen
            }
            $chatId = (string) $message['chat']['id'];
            $name = trim((string) ($message['from']['first_name'] ?? '') . ' ' . (string) ($message['from']['last_name'] ?? '')) ?: (string) ($message['from']['username'] ?? $chatId);
            $text = (string) ($message['text'] ?? '');
            if (!isset($config['telegram']['allowed'][$chatId])) {
                $newPending[$chatId] = ['name' => mb_substr($name, 0, 40), 'at' => Time::db($this->clock->now())];
                $reply = t('Hallo {name}! Der Board-Besitzer muss dich erst freigeben – danach erscheinen deine Nachrichten auf dem Board.', ['name' => $name]);
            } elseif (str_starts_with($text, '/start')) {
                $reply = t('Hallo {name}! Schick mir einfach einen Text – er erscheint auf dem Board (3 Zeilen à 15 Zeichen).', ['name' => $name]);
            } else {
                $result = $this->accept('tg:' . $chatId, $name, $text);
                $received += $result['ok'] ? 1 : 0;
                $reply = $result['reply'];
            }
            ($this->http)(self::telegramUrl($telegram['token'], 'sendMessage'), ['chat_id' => $chatId, 'text' => $reply]);
        }
        // Frisch lesen und nur Offset + neue Anfragen einmischen – sonst überschreibt ein langer Lauf,
        // was der Admin in der Zwischenzeit geändert hat (Trennen, Freigeben, Entfernen, Slack)
        $this->settings->refresh();
        $fresh = $this->config();
        if ($fresh['telegram']['token'] !== $telegram['token']) {
            return ['received' => $received];
        }
        $fresh['telegram']['offset'] = max((int) $fresh['telegram']['offset'], $offset);
        $pending = $fresh['telegram']['pending'];
        foreach ($newPending as $chatId => $entry) {
            if (!isset($fresh['telegram']['allowed'][$chatId])) {
                $pending[$chatId] = $entry;
            }
        }
        // Anfragen verfallen nach 7 Tagen; bei Überlauf fliegen die ältesten raus (Fremde können die Liste nicht dauerhaft verstopfen)
        $cutoff = Time::db($this->clock->now()->modify('-7 days'));
        $pending = array_filter($pending, static fn (array $p): bool => ($p['at'] ?? '') >= $cutoff);
        uasort($pending, static fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));
        $fresh['telegram']['pending'] = array_slice($pending, 0, self::MAX_PENDING, true);
        $this->save($fresh);
        return ['received' => $received];
    }

    public function approveTelegram(string $chatId): void
    {
        $config = $this->config();
        $pending = $config['telegram']['pending'][$chatId] ?? null;
        if ($pending !== null && count($config['telegram']['allowed']) < self::MAX_ALLOWED) {
            $config['telegram']['allowed'][$chatId] = ['name' => $pending['name']];
            unset($config['telegram']['pending'][$chatId]);
            $this->save($config);
            if ($config['telegram']['token'] !== '') {
                ($this->http)(self::telegramUrl($config['telegram']['token'], 'sendMessage'), ['chat_id' => $chatId, 'text' => t('Du bist freigeschaltet – schick einfach einen Text, er erscheint auf dem Board.')]);
            }
        }
    }

    public function removeTelegram(string $chatId): void
    {
        $config = $this->config();
        unset($config['telegram']['allowed'][$chatId], $config['telegram']['pending'][$chatId]);
        $this->save($config);
    }

    private static function telegramUrl(string $token, string $method): string
    {
        return 'https://api.telegram.org/bot' . $token . '/' . $method;
    }

    /** @return ?array JSON-Antwort oder null */
    public static function postJson(string $url, array $params): ?array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_OPTIONS => PHP_OS_FAMILY === 'Windows' ? CURLSSLOPT_NATIVE_CA : 0,
        ]);
        $raw = curl_exec($handle);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    // ---------- Slack

    /**
     * Slash-Befehl annehmen (z. B. /board Hallo). Prüft die Slack-Signatur (Signing Secret).
     * @return array{status: int, body: array}
     */
    public function handleSlack(string $raw, string $timestamp, string $signature): array
    {
        $slack = $this->config()['slack'];
        if (!$slack['enabled'] || $slack['secret'] === '') {
            return ['status' => 404, 'body' => ['text' => 'Not found']];
        }
        $age = abs($this->clock->now()->getTimestamp() - (int) $timestamp);
        $expected = 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $raw, (string) $slack['secret']);
        if (!ctype_digit($timestamp) || $age > self::SLACK_TOLERANCE || !hash_equals($expected, $signature)) {
            return ['status' => 401, 'body' => ['text' => 'Invalid signature']];
        }
        parse_str($raw, $form);
        $text = (string) ($form['text'] ?? '');
        if (trim($text) === '') {
            return ['status' => 200, 'body' => ['response_type' => 'ephemeral', 'text' => t('Schreib den Text hinter den Befehl, z. B. /board Hallo zusammen')]];
        }
        $result = $this->accept('slack:' . (string) ($form['team_id'] ?? '') . ':' . (string) ($form['user_id'] ?? ''), (string) ($form['user_name'] ?? 'Slack'), $text);
        return ['status' => 200, 'body' => ['response_type' => 'ephemeral', 'text' => $result['reply']]];
    }
}
