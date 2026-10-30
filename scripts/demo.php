<?php
declare(strict_types=1);

// Demo-Installation mit Beispieldaten (für Screenshots und zum Ausprobieren).
// Aufruf:  php scripts/demo.php [en|de]
// Danach:  VESTA_CONFIG=var/demo-config.php php -S 127.0.0.1:8777 -t public
// Anmeldung: Alex / demo-password (Admin), Jordan / demo-password (Erwachsene), Mia / PIN 1234 (Kind)

require __DIR__ . '/../src/bootstrap.php';

use Vesta\Auth;
use Vesta\Charset;
use Vesta\Db;
use Vesta\Inbound;
use Vesta\MessageRepo;
use Vesta\Settings;
use Vesta\SystemClock;
use Vesta\Time;
use Vesta\UserRepo;

$lang = ($argv[1] ?? 'en') === 'de' ? 'de' : 'en';
$en = $lang === 'en';
$root = APP_ROOT . '/var';
@unlink("$root/demo.sqlite");
file_put_contents("$root/demo-config.php", "<?php\nreturn [\n    'vestaboard_token' => '',\n    'cron_key' => 'demo-key',\n    'timezone' => 'Europe/Berlin',\n    'language' => '$lang',\n    'board_driver' => 'log',\n    'debug' => true,\n    'db' => ['driver' => 'sqlite', 'path' => APP_ROOT . '/var/demo.sqlite'],\n];\n");

$pdo = Db::connect(['driver' => 'sqlite', 'path' => "$root/demo.sqlite"]);
Db::install($pdo);
$settings = new Settings($pdo);
$settings->set('language', $lang);
$users = new UserRepo($pdo);
$messages = new MessageRepo($pdo);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$at = static fn (string $modify): string => Time::db($now->modify($modify));

$base = ['login_type' => 'password', 'can_force' => 0, 'can_jump' => 0, 'can_schedule' => 0, 'created_at' => $at('-30 days')];
$admin = $users->insert(['name' => 'Alex', 'role' => 'admin', 'source' => 'admin', 'secret_hash' => Auth::hashSecret('demo-password'), 'can_force' => 1, 'can_jump' => 1, 'can_schedule' => 1] + $base);
$adult = $users->insert(['name' => 'Jordan', 'role' => 'member', 'source' => 'adults', 'secret_hash' => Auth::hashSecret('demo-password'), 'can_force' => 1, 'can_jump' => 1, 'can_schedule' => 1] + $base);
$kid = $users->insert(['name' => 'Mia', 'role' => 'kid', 'source' => 'kids', 'login_type' => 'pin', 'secret_hash' => Auth::hashSecret('1234'), 'cooldown_min' => 15] + $base);
$kid2 = $users->insert(['name' => 'Leo', 'role' => 'kid', 'source' => 'kids', 'login_type' => 'pin', 'secret_hash' => Auth::hashSecret('5678'), 'cooldown_min' => 15] + $base);

$add = static function (string $source, ?int $user, string $text, string $status, array $extra = []) use ($messages, $at): int {
    $result = Charset::textToGrid($text, $extra['align'] ?? 'center');
    return $messages->insert($extra + [
        'source' => $source, 'user_id' => $user, 'text_raw' => $text, 'align' => 'center',
        'grid' => json_encode($result['grid']), 'status' => $status, 'created_at' => $at('-2 hours'),
    ]);
};

// Verlauf
$add('weather', null, $en ? "{white} SPRINGFIELD\nNOW 14 C\nSUNNY 9/18" : "{white} SPRINGFIELD\nJETZT 14 GRAD\nSONNIG 9/18", 'shown', ['shown_at' => $at('-3 hours'), 'ended_at' => $at('-150 minutes')]);
$add('kids', $kid2, $en ? "I SCORED\nA GOAL! {green}" : "ICH HAB EIN\nTOR GESCHOSSEN!\n{green}", 'shown', ['shown_at' => $at('-150 minutes'), 'ended_at' => $at('-110 minutes')]);
$add('alarm', null, $en ? "GOOD MORNING\n{yellow}{yellow}{yellow}" : "GUTEN MORGEN\n{yellow}{yellow}{yellow}", 'shown', ['shown_at' => $at('-110 minutes'), 'ended_at' => $at('-40 minutes')]);
$reply = $add('kids', $kid, $en ? "CAN WE HAVE\nPIZZA TONIGHT?\n{heart}" : "GIBT ES HEUTE\nPIZZA?\n{heart}", 'shown', ['shown_at' => $at('-40 minutes'), 'ended_at' => $at('-12 minutes')]);
$messages->update($reply, ['reply_text' => $en ? 'Yes – pizza night it is! 🍕' : 'Ja – heute ist Pizzaabend! 🍕', 'reply_by' => $admin, 'reply_at' => $at('-30 minutes'), 'reply_seen_at' => $at('-25 minutes')]);

// Aktuell + Warteschlange
$add('adults', $adult, $en ? "DINNER IS\nREADY {heart}" : "ESSEN IST\nFERTIG {heart}", 'showing', ['shown_at' => $at('-2 minutes'), 'created_at' => $at('-3 minutes')]);
$add('kids', $kid, $en ? "HAPPY BIRTHDAY\nGRANDMA!\n{red}{orange}{yellow}{green}{blue}" : "ALLES GUTE\nOMA!\n{red}{orange}{yellow}{green}{blue}", 'queued', ['created_at' => $at('-1 minute')]);
$add('webhook', null, $en ? "PACKAGE\nDELIVERED\nFRONT DOOR" : "PAKET\nZUGESTELLT\nHAUSTUER", 'queued', ['created_at' => $at('-30 seconds')]);
$add('adults', $adult, $en ? "PIANO LESSON\n4 PM {violet}" : "KLAVIERSTUNDE\n16 UHR {violet}", 'queued', ['not_before' => $at('+3 hours'), 'created_at' => $at('-5 minutes')]);

// Automatik
$settings->set('automation_alarms', [['id' => 'a1b2c3d4', 'enabled' => true, 'text' => $en ? "GOOD MORNING\n{yellow}{yellow}{yellow}" : "GUTEN MORGEN\n{yellow}{yellow}{yellow}", 'align' => 'center', 'forced' => true,
    'slots' => [['days' => [1, 2, 3, 4, 5], 'time' => '06:45'], ['days' => [6, 7], 'time' => '08:30']]]]);
$settings->set('automation_weather', ['enabled' => true, 'city' => ['name' => 'Springfield', 'lat' => 39.8, 'lon' => -89.6], 'mode' => 'times',
    'times' => [['days' => [1, 2, 3, 4, 5], 'time' => '07:00'], ['days' => [6, 7], 'time' => '09:00']], 'interval' => 120, 'windows' => [['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '06:30', 'to' => '20:00']]]);
$settings->set('automation_clock', ['enabled' => true, 'interval' => 60, 'style' => 'words', 'windows' => [['days' => [1, 2, 3, 4, 5], 'from' => '16:00', 'to' => '21:00'], ['days' => [6, 7], 'from' => '09:00', 'to' => '21:00']]]);
$settings->set('automation_pool', ['enabled' => true, 'items' => \Vesta\Sayings::ALL_EN, 'inactive' => [], 'order' => [], 'art' => ['rainbow', 'love'], 'interval' => 90,
    'windows' => [['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '08:00', 'to' => '20:00']]]);
$settings->set('custom_sources', [['id' => 'c_bookings', 'label' => $en ? 'Bookings' : 'Buchungen', 'duration' => 10]]);
// Briefkasten offen (fester Demo-Link) und Nachrichten zu festen Zeiten
$settings->set('guest_post', ['enabled' => true, 'token' => 'demo-mailbox-link'] + \Vesta\GuestPost::DEFAULTS);
$settings->set('automation_news', ['enabled' => true, 'feed' => $en ? 'bbc' : 'tagesschau', 'url' => '', 'count' => 2,
    'times' => [['days' => [1, 2, 3, 4, 5], 'time' => '07:00'], ['days' => [6, 7], 'time' => '09:00']]]);

// Integration mit Testmodus-Payload
$inbound = new Inbound($pdo, $messages, new SystemClock());
$hook = $inbound->createKey($en ? 'Bookings' : 'Buchungen', 'c_bookings', "{{title}}\n{{booking.customer.short|15}}\n{{booking.date}} {{booking.time|or:}}", false);
$inbound->update($hook['id'], [
    'test_mode' => 1,
    'rules' => json_encode([['field' => 'event', 'op' => 'equals', 'value' => 'booking.confirmed', 'template' => ($en ? 'BOOKED' : 'GEBUCHT') . " {heart}\n{{booking.customer.short|15}}\n{{booking.date}} {{booking.time|or:}}", 'force' => true, 'duration' => 600, 'expires' => 0]]),
    'dedupe_key' => '{{event}}-{{booking.number}}', 'dedupe_minutes' => 60,
]);
$inbound->receive($hook['key'], ['event' => 'booking.confirmed', 'title' => $en ? 'BOOKED' : 'GEBUCHT', 'booking' => ['number' => 'BK-2026-0031', 'date' => 'Nov 12', 'time' => '14:00', 'product' => 'Photo booth', 'guests' => 80, 'total_gross' => 289, 'customer' => ['name' => 'Anna Miller', 'short' => 'Anna']]]);
$nfc = $inbound->createKey($en ? 'Kitchen' : 'Küche', 'nfc', $en ? 'DINNER IS READY {heart}' : 'ESSEN IST FERTIG {heart}', true);

// Betrieb sieht gesund aus (kein Cron-Warnhinweis auf Screenshots)
$settings->set('last_cron_at', $at('-20 seconds'));
$settings->set('board_status', ['last_ok_at' => $at('-2 minutes'), 'last_error' => null, 'last_error_at' => null, 'external_mute' => false]);
$settings->set('board_live', ['grid' => null, 'read_at' => $at('-1 minute')]);

echo "Demo ready ($lang). Start: VESTA_CONFIG=var/demo-config.php php -S 127.0.0.1:8777 -t public\n";
