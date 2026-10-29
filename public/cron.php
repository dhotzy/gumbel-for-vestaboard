<?php
declare(strict_types=1);

/**
 * Wird minütlich vom Cronjob aufgerufen: cron.php?key=<cron_key>
 */

require __DIR__ . '/../src/bootstrap.php';
send_security_headers();

use Vesta\App;
use Vesta\Time;

$config = app_config();
// Aufruf per Kommandozeile (Docker, Server-Cron mit „php public/cron.php“) braucht keinen Schlüssel.
// Per HTTP: Schlüssel bevorzugt im Header X-Cron-Key (landet nicht im Access-Log), sonst ?key=.
if (PHP_SAPI !== 'cli') {
    $key = (string) ($_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? '');
    if (($config['cron_key'] ?? '') === '' || !hash_equals((string) $config['cron_key'], $key)) {
        http_response_code(404);
        exit('Not found');
    }
}
// Nie zwei Läufe gleichzeitig (sonst doppelte Wecker/Uhr/Abrufe, wenn ein Lauf länger dauert)
$cronLock = new Vesta\Lock(APP_ROOT . '/var/cron.lock');
if (!$cronLock->acquire(0)) {
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['action' => 'skipped', 'detail' => 'Vorheriger Lauf läuft noch.']));
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    App::settings(); // Sprache für Board-Texte (Uhr, Wetter)
    $now = App::clock()->now();
    App::settings()->set('last_cron_at', Time::db($now));
    try {
        $sync = App::boardSync()->sync();
    } catch (Throwable $e) {
        error_log('Board-Abgleich fehlgeschlagen: ' . $e->getMessage());
        $sync = ['ok' => false, 'changed' => false, 'detail' => 'Interner Fehler beim Abgleich.'];
    }
    try {
        $automation = App::automation()->run();
    } catch (Throwable $e) {
        error_log('Automatik fehlgeschlagen: ' . $e->getMessage());
        $automation = ['error'];
    }
    try {
        $feeds = App::inbound()->pollDue(); // Integrationen „Abrufen“ (JSON/RSS)
    } catch (Throwable $e) {
        error_log('Abrufen fehlgeschlagen: ' . $e->getMessage());
        $feeds = ['error'];
    }
    try {
        $messenger = App::messenger()->pollTelegram(); // Telegram-Bot: neue Nachrichten abholen
    } catch (Throwable $e) {
        error_log('Telegram fehlgeschlagen: ' . $e->getMessage());
        $messenger = ['error'];
    }
    $result = App::triggerArbiter() + ['sync' => $sync, 'automation' => $automation, 'feeds' => $feeds, 'messenger' => $messenger];
    if ((int) $now->format('i') === 0) {
        App::apiLog()->cleanup($now);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Cron-Fehler: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['action' => 'error', 'detail' => 'Interner Fehler']);
}
