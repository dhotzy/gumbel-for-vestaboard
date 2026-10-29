<?php
declare(strict_types=1);

// Aufruf: php tests/guest-test.php – Briefkasten (Gast-Nachrichten ohne Konto) und QR-Code

require __DIR__ . '/support.php';

use Vesta\GuestPost;
use Vesta\MessageRepo;
use Vesta\QrCode;
use Vesta\Settings;

$pdo = memoryDb();
$clock = new FixedClock('2026-10-29 12:00:00');
$settings = new Settings($pdo);
$messages = new MessageRepo($pdo);
$guest = new GuestPost($pdo, $settings, $messages, $clock);

section('Link');
check('Standard: geschlossen', !$guest->isOpen('') && !$guest->config()['enabled']);
$guest->save(['enabled' => true]);
$token = $guest->regenerateToken();
check('Offen mit richtigem Token', $guest->isOpen($token));
check('Falscher Token → zu', !$guest->isOpen('falsch') && !$guest->isOpen(''));
$old = $token;
$token = $guest->regenerateToken();
check('Neuer Link macht alten ungültig', !$guest->isOpen($old) && $guest->isOpen($token));
$guest->save(['enabled' => false]);
check('Ausgeschaltet → zu', !$guest->isOpen($token));
$guest->save(['enabled' => true, 'unknown' => 1]);
check('Unbekannte Felder werden nicht gespeichert', !array_key_exists('unknown', $guest->config()));

section('Abschicken');
$r = $guest->submit(['text' => 'HALLO {heart}', 'name' => "Mia<script>\x07"], 'gerät-a');
$m = $messages->find((int) $r['id']);
check('Nachricht landet als Gast in der Warteschlange', $r['ok'] && $m['source'] === 'guest' && $m['status'] === 'queued' && $m['user_id'] === null);
check('Name ohne Steuerzeichen und spitze Klammern', $m['user_name'] === 'Miascript');
check('Läuft ab (Standard 120 Min)', $m['expires_at'] === Vesta\Time::db($clock->now()->modify('+120 minutes')));
check('Ohne Recht kein Force', (int) $guest->submit(['text' => 'X', 'forced' => 1], 'gerät-f')['id'] > 0 && (int) $messages->find((int) $guest->submit(['text' => 'Y', 'forced' => 1], 'gerät-g')['id'])['forced'] === 0);
check('Leerer Text → Fehler', !$guest->submit(['text' => '  '], 'gerät-b')['ok']);
check('Zu langer Text → Fehler', !$guest->submit(['text' => str_repeat('WORT ', 30)], 'gerät-b')['ok']);
$bot = $guest->submit(['text' => 'SPAM', 'website' => 'http://spam'], 'gerät-bot');
check('Honigtopf: „ok“, aber nichts angelegt', $bot['ok'] && $bot['id'] === null);

section('Grenzen');
$pause = $guest->submit(['text' => 'NOCHMAL'], 'gerät-a');
check('Pause pro Gerät', !$pause['ok'] && str_contains((string) $pause['error'], '10'));
$clock->advance(11 * 60);
check('Nach der Pause wieder erlaubt', $guest->submit(['text' => 'NOCHMAL'], 'gerät-a')['ok']);
$guest->save(['max_waiting' => 4]); // es warten 4
check('Höchstzahl wartender Gast-Nachrichten', !$guest->submit(['text' => 'ZU VIEL'], 'gerät-c')['ok']);
foreach ($messages->queued() as $queued) {
    $messages->update((int) $queued['id'], ['status' => 'shown']);
}
$guest->save(['max_per_hour' => 3]);
$clock->advance(5 * 60);
check('Höchstzahl pro Stunde (gezählt: letzte 60 Min)', !$guest->submit(['text' => 'ZU VIEL'], 'gerät-d')['ok']);
$clock->advance(61 * 60);
check('Eine Stunde später wieder offen', $guest->submit(['text' => 'WIEDER DA'], 'gerät-d')['ok']);
$guest->save(['can_force' => true, 'jump' => true, 'max_per_hour' => 50]);
$forced = $messages->find((int) $guest->submit(['text' => 'WICHTIG', 'forced' => 1], 'gerät-e')['id']);
check('Mit Recht: Force und vorne anstellen', (int) $forced['forced'] === 1 && (int) $forced['jump'] === 1);

section('QR-Code');
$qr = QrCode::encode('https://gumbel.example.com/post.php?t=' . $token);
check('Größe passt zur Version (17 + 4·v)', ($qr->size() - 17) % 4 === 0 && $qr->size() >= 21);
check('Suchmuster oben links (dunkle Ecke, heller Ring, dunkler Kern)', $qr->isDark(0, 0) && !$qr->isDark(1, 1) && $qr->isDark(3, 3));
check('Suchmuster oben rechts und unten links', $qr->isDark($qr->size() - 1, 0) && $qr->isDark(0, $qr->size() - 1));
check('Gleicher Text → gleicher Code', QrCode::encode('ABC')->toSvg() === QrCode::encode('ABC')->toSvg());
check('SVG mit weißem Hintergrund', str_starts_with($qr->toSvg(), '<svg') && str_contains($qr->toSvg(), 'fill="#fff"'));
check('Version 10 für 213 Bytes', QrCode::encode(str_repeat('z', 213))->size() === 57);
$tooLong = false;
try {
    QrCode::encode(str_repeat('z', 214));
} catch (InvalidArgumentException) {
    $tooLong = true;
}
check('Zu lang → Fehler statt kaputtem Code', $tooLong);

finish();
