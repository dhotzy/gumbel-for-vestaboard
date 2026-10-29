<?php
declare(strict_types=1);

// Aufruf: php tests/messenger-test.php – Telegram (mit nachgebauter API) und Slack-Signatur

require __DIR__ . '/support.php';

use Vesta\MessageRepo;
use Vesta\Messenger;
use Vesta\Settings;

$pdo = memoryDb();
$clock = new FixedClock('2026-10-29 12:00:00');
$settings = new Settings($pdo);
$messages = new MessageRepo($pdo);
$calls = [];
$updates = [];
$http = static function (string $url, array $params) use (&$calls, &$updates): ?array {
    $method = substr($url, strrpos($url, '/') + 1);
    $calls[] = [$method, $params];
    return match ($method) {
        'getMe' => str_contains($url, 'bot123456:') ? ['ok' => true, 'result' => ['username' => 'gumbel_test_bot']] : ['ok' => false],
        'getUpdates' => ['ok' => true, 'result' => $updates],
        default => ['ok' => true, 'result' => true],
    };
};
$messenger = new Messenger($pdo, $settings, $messages, $clock, $http);
$token = '123456:' . str_repeat('A', 35);
$update = static fn (int $id, int $chat, string $text, string $type = 'private'): array => ['update_id' => $id, 'message' => ['chat' => ['id' => $chat, 'type' => $type], 'from' => ['first_name' => 'Anna'], 'text' => $text]];
$lastReply = static function () use (&$calls): string {
    foreach (array_reverse($calls) as [$method, $params]) {
        if ($method === 'sendMessage') {
            return (string) $params['text'];
        }
    }
    return '';
};

section('Telegram verbinden');
check('Kaputter Token → Fehler', !$messenger->connectTelegram('quatsch')['ok']);
check('Unbekannter Token → Fehler', !$messenger->connectTelegram('999999:' . str_repeat('B', 35))['ok']);
$connected = $messenger->connectTelegram($token);
check('Richtiger Token → verbunden mit Bot-Name', $connected['ok'] && $messenger->config()['telegram']['bot'] === 'gumbel_test_bot');

section('Unbekannte Chats');
$updates = [$update(10, 555, 'HALLO')];
$messenger->pollTelegram();
check('Unbekannter Chat wird Anfrage, nichts aufs Board', isset($messenger->config()['telegram']['pending']['555']) && $messages->queued() === []);
check('Antwort: muss freigegeben werden', str_contains($lastReply(), 'freigeben'));
check('Offset rückt weiter (kein Doppel-Abruf)', $messenger->config()['telegram']['offset'] === 11);
$updates = [$update(11, 777, 'GRUPPE', 'group')];
$messenger->pollTelegram();
check('Gruppen werden ignoriert', !isset($messenger->config()['telegram']['pending']['777']));

section('Freigegeben');
$messenger->approveTelegram('555');
check('Freigabe verschiebt aus Anfragen in Freigegeben', isset($messenger->config()['telegram']['allowed']['555']) && !isset($messenger->config()['telegram']['pending']['555']));
$updates = [$update(12, 555, 'HALLO BOARD')];
$messenger->pollTelegram();
$queued = $messages->queued();
check('Nachricht landet als Messenger auf dem Board, mit Namen', count($queued) === 1 && $queued[0]['source'] === 'messenger' && $queued[0]['user_name'] === 'Anna' && $queued[0]['text_raw'] === 'HALLO BOARD');
check('Bestätigung zurück', str_starts_with($lastReply(), '✓'));
$updates = [$update(13, 555, 'GLEICH NOCHMAL')];
$messenger->pollTelegram();
check('Pause pro Person', count($messages->queued()) === 1 && str_contains($lastReply(), 'warte'));
$clock->advance(61);
$updates = [$update(14, 555, str_repeat('SEHR LANGER TEXT ', 10))];
$messenger->pollTelegram();
check('Zu langer Text wird gekürzt statt abgelehnt', count($messages->queued()) === 2 && Vesta\Charset::textToGrid((string) $messages->queued()[1]['text_raw'])['fits']);
$messenger->removeTelegram('555');
$clock->advance(61);
$updates = [$update(15, 555, 'NACH ENTFERNEN')];
$messenger->pollTelegram();
check('Entfernt → wieder nur Anfrage', count($messages->queued()) === 2 && isset($messenger->config()['telegram']['pending']['555']));
$messenger->disconnectTelegram();
$before = count($calls);
check('Getrennt → keine Abfrage mehr', $messenger->pollTelegram() === ['received' => 0] && count($calls) === $before && $messenger->config()['telegram']['token'] === '');

section('Gleichzeitige Admin-Änderung');
$messenger->connectTelegram($token);
$messenger->save(['telegram' => ['allowed' => ['555' => ['name' => 'Anna']]] + $messenger->config()['telegram']] + $messenger->config());
$racing = new Messenger($pdo, $settings, $messages, $clock, static function (string $url, array $params) use ($http, $pdo, $messages, $clock): ?array {
    if (str_ends_with($url, '/sendMessage')) {
        // Admin entfernt Anna, während der Cron noch antwortet (eigene Instanz = anderer Request)
        (new Messenger($pdo, new Settings($pdo), $messages, $clock))->removeTelegram('555');
    }
    return $http($url, $params);
});
$clock->advance(120);
$updates = [$update(20, 555, 'WAEHREND'), $update(21, 888, 'FREMD')];
$racing->pollTelegram();
$settings->refresh();
$after = $messenger->config()['telegram'];
check('Entfernen während der Abfrage bleibt bestehen', !isset($after['allowed']['555']));
check('Offset und neue Anfrage werden trotzdem gemerkt', $after['offset'] === 22 && isset($after['pending']['888']));
$clock->advance(8 * 86400);
$updates = [];
$messenger->pollTelegram();
check('Anfragen verfallen nach 7 Tagen', !isset($messenger->config()['telegram']['pending']['888']));
$messenger->disconnectTelegram();

section('Slack');
$config = $messenger->config();
$config['slack'] = ['enabled' => true, 'secret' => 'slack-geheim'];
$messenger->save($config);
$body = http_build_query(['team_id' => 'T1', 'user_id' => 'U1', 'user_name' => 'ben', 'command' => '/board', 'text' => 'HALLO SLACK']);
$ts = (string) $clock->now()->getTimestamp();
$sign = static fn (string $ts, string $body): string => 'v0=' . hash_hmac('sha256', "v0:$ts:$body", 'slack-geheim');
$ok = $messenger->handleSlack($body, $ts, $sign($ts, $body));
check('Gültige Signatur → eingereiht', $ok['status'] === 200 && str_starts_with($ok['body']['text'], '✓'));
check('Falsche Signatur → 401', $messenger->handleSlack($body, $ts, 'v0=falsch')['status'] === 401);
$oldTs = (string) ($clock->now()->getTimestamp() - 600);
check('Alter Zeitstempel (Replay) → 401', $messenger->handleSlack($body, $oldTs, $sign($oldTs, $body))['status'] === 401);
$config['slack']['enabled'] = false;
$messenger->save($config);
check('Slack aus → 404', $messenger->handleSlack($body, $ts, $sign($ts, $body))['status'] === 404);

finish();
