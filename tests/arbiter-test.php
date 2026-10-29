<?php
declare(strict_types=1);

// Aufruf: php tests/arbiter-test.php

require __DIR__ . '/support.php';

use Vesta\Arbiter;
use Vesta\Charset;
use Vesta\MessageRepo;
use Vesta\Settings;
use Vesta\Time;

/** Frische Umgebung pro Szenario */
function env(): array
{
    $pdo = memoryDb();
    $clock = new FixedClock('2026-10-26 12:00:00');
    $board = new FakeBoard();
    $messages = new MessageRepo($pdo);
    $settings = new Settings($pdo);
    $arbiter = new Arbiter($messages, $settings, $board, $clock);
    return [$messages, $settings, $board, $clock, $arbiter];
}

function queue(MessageRepo $messages, FixedClock $clock, string $source, string $text, array $extra = []): int
{
    return $messages->insert($extra + [
        'source' => $source,
        'text_raw' => $text,
        'grid' => json_encode(Charset::textToGrid($text)['grid']),
        'status' => 'queued',
        'created_at' => Time::db($clock->now()),
    ]);
}

function shownText(FakeBoard $board): string
{
    $last = end($board->sent);
    return $last ? trim(preg_replace('/[|+\-\s]+/', ' ', Charset::gridToText($last['grid']))) : '';
}

section('Grundablauf');
[$messages, $settings, $board, $clock, $arbiter] = env();
check('Leere Warteschlange → idle', $arbiter->run()['action'] === 'idle');
$a = queue($messages, $clock, 'kids', 'ERSTE');
$result = $arbiter->run();
check('Nachricht wird gesendet', $result['action'] === 'sent' && $result['message_id'] === $a);
check('Status showing', $messages->find($a)['status'] === 'showing');
$b = queue($messages, $clock, 'kids', 'ZWEITE');
$clock->advance(60);
check('Mindestdauer (Kinder 5 Min) blockiert', $arbiter->run()['action'] === 'waiting');
$clock->advance(4 * 60);
check('Nach 5 Min kommt die nächste', $arbiter->run()['message_id'] === $b);
check('Vorherige ist jetzt shown', $messages->find($a)['status'] === 'shown' && $messages->find($a)['ended_at'] !== null);
check('Genau 2 Sendungen', count($board->sent) === 2);

section('Rangfolge, vorne anstellen, FIFO');
[$messages, $settings, $board, $clock, $arbiter] = env();
$kid = queue($messages, $clock, 'kids', 'KIND');
$clock->advance(1);
$adult = queue($messages, $clock, 'adults', 'ERW');
check('Erwachsene (Rang höher) vor Kind, obwohl später', $arbiter->run()['message_id'] === $adult);
[$messages, $settings, $board, $clock, $arbiter] = env();
$k1 = queue($messages, $clock, 'kids', 'K1');
$clock->advance(1);
$k2 = queue($messages, $clock, 'kids', 'K2', ['jump' => 1]);
check('Vorne angestellt schlägt FIFO bei gleichem Rang', $arbiter->run()['message_id'] === $k2);
[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('source_order', ['kids', 'adults', 'admin']);
queue($messages, $clock, 'adults', 'ERW');
$kidFirst = queue($messages, $clock, 'kids', 'KIND');
check('Geänderte Rangfolge wird beachtet', $arbiter->run()['message_id'] === $kidFirst);
check('Neue Quellen werden hinten angehängt', in_array('alarm', $settings->sourceOrder(), true));

section('Planung und Ablauf');
[$messages, $settings, $board, $clock, $arbiter] = env();
$planned = queue($messages, $clock, 'adults', 'SPAETER', ['not_before' => Time::db($clock->now()->modify('+30 minutes'))]);
check('Geplante Nachricht wartet', $arbiter->run()['action'] === 'idle');
$clock->advance(30 * 60);
check('Zum Zeitpunkt wird sie gesendet', $arbiter->run()['message_id'] === $planned);
[$messages, $settings, $board, $clock, $arbiter] = env();
$old = queue($messages, $clock, 'weather', 'WETTER', ['expires_at' => Time::db($clock->now()->modify('+10 minutes'))]);
$settings->set('mute', ['mode' => 'on', 'until' => null, 'sources' => []]);
$arbiter->run();
$clock->advance(11 * 60);
$arbiter->run();
check('Abgelaufene Nachricht → expired', $messages->find($old)['status'] === 'expired');

section('Mute und Force');
[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('mute', ['mode' => 'on', 'until' => null, 'sources' => []]);
$normal = queue($messages, $clock, 'kids', 'NORMAL');
check('Mute hält normale Nachricht zurück', $arbiter->run()['action'] === 'muted');
check('Nachricht bleibt queued (nicht gelöscht)', $messages->find($normal)['status'] === 'queued');
$force = queue($messages, $clock, 'kids', 'WICHTIG', ['forced' => 1]);
$result = $arbiter->run();
check('Force schlägt Mute', $result['message_id'] === $force);
check('An die API mit forced = true', end($board->sent)['forced'] === true);
$settings->set('mute', ['mode' => 'off', 'until' => null, 'sources' => []]);
$clock->advance(5 * 60);
check('Nach Mute-Ende kommt die normale Nachricht', $arbiter->run()['message_id'] === $normal);

[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('mute', ['mode' => 'until', 'until' => Time::db($clock->now()->modify('+20 minutes')), 'sources' => []]);
queue($messages, $clock, 'kids', 'X');
check('Mute "bis" aktiv', $arbiter->run()['action'] === 'muted');
$clock->advance(21 * 60);
check('Mute "bis" endet automatisch', $arbiter->run()['action'] === 'sent');

section('Schnell-Mute, komplett still');
[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('mute', ['mode' => 'off', 'until' => null, 'sources' => ['kids']]);
$settings->startMute($clock->now()->modify('+30 minutes'), true);
check('Schnell-Mute behält einzeln stumme Quellen', $settings->get('mute')['sources'] === ['kids']);
check('Aktiv mit Ende und komplett still', $settings->manualMute($clock->now())['active'] && $settings->isStrictMute($clock->now()));
$strictForce = queue($messages, $clock, 'adults', 'WICHTIG', ['forced' => 1]);
check('Komplett still: auch Force wartet', $arbiter->run()['action'] === 'muted' && $board->sent === []);
check('Plan zeigt Force als stumm', $arbiter->plan()['due'][0]['muted'] === true);
$clock->advance(31 * 60);
check('Nach Ablauf kommt die Force-Nachricht', $arbiter->run()['message_id'] === $strictForce);
$settings->startMute(null, false);
check('Nicht komplett still: Force kommt durch', !$settings->isStrictMute($clock->now()) && $settings->manualMute($clock->now())['active']);
$settings->stopMute();
check('Beenden schaltet alles aus', !$settings->manualMute($clock->now())['active'] && $settings->get('mute')['strict'] === false && $settings->get('mute')['sources'] === ['kids']);

[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('mute', ['mode' => 'off', 'until' => null, 'sources' => ['kids']]);
queue($messages, $clock, 'kids', 'KIND');
$adultMsg = queue($messages, $clock, 'adults', 'ERW');
check('Quellen-Mute: nur Kinder stumm, Erwachsene gehen', $arbiter->run()['message_id'] === $adultMsg);
$clock->advance(10 * 60);
check('Kinder bleiben stumm', $arbiter->run()['action'] !== 'sent' && count($board->sent) === 1);
$settings->set('idle', ['mode' => 'clear', 'after_min' => 1, 'text' => '', 'align' => 'center']);
check('Quellen-Stumm blockiert den Ruhezustand nicht', $arbiter->run()['action'] === 'sent' && end($board->sent)['grid'] === Charset::emptyGrid());

section('Force gegen Force (Rang entscheidet)');
[$messages, $settings, $board, $clock, $arbiter] = env();
$kidForce = queue($messages, $clock, 'kids', 'KIND FORCE', ['forced' => 1]);
$arbiter->run();
$clock->advance(20);
$otherKidForce = queue($messages, $clock, 'kids', 'KIND FORCE 2', ['forced' => 1]);
check('Gleicher Rang unterbricht laufende Force nicht', $arbiter->run()['action'] === 'waiting');
$adultForce = queue($messages, $clock, 'adults', 'ERW FORCE', ['forced' => 1]);
check('Höherer Rang unterbricht laufende Force', $arbiter->run()['message_id'] === $adultForce);
$clock->advance(20);
$normalAdmin = queue($messages, $clock, 'admin', 'ADMIN NORMAL');
check('Normale Nachricht unterbricht nichts (auch höherer Rang)', $arbiter->run()['action'] === 'waiting');

[$messages, $settings, $board, $clock, $arbiter] = env();
queue($messages, $clock, 'adults', 'NORMAL ERW');
$arbiter->run();
$clock->advance(20);
$kidForce2 = queue($messages, $clock, 'kids', 'KIND FORCE', ['forced' => 1]);
check('Force (niedriger Rang) unterbricht normale Nachricht', $arbiter->run()['message_id'] === $kidForce2);

[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('force_sources', ['kids']);
$settings->set('mute', ['mode' => 'on', 'until' => null, 'sources' => []]);
$byConfig = queue($messages, $clock, 'kids', 'IMMER FORCE');
check('Force per Einstellung "Quelle immer forced"', $arbiter->run()['message_id'] === $byConfig);
[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('force_all', true);
$settings->set('mute', ['mode' => 'on', 'until' => null, 'sources' => []]);
queue($messages, $clock, 'kids', 'ALLES FORCE');
check('Force per Einstellung "alle forced"', $arbiter->run()['action'] === 'sent');

section('Rate-Limit 15 s');
[$messages, $settings, $board, $clock, $arbiter] = env();
queue($messages, $clock, 'kids', 'A');
$arbiter->run();
$clock->advance(5);
queue($messages, $clock, 'kids', 'B', ['forced' => 1]);
$result = $arbiter->run();
check('Force innerhalb 15 s → rate_limited', $result['action'] === 'rate_limited' && $result['retry_in'] === 10);
$clock->advance(10);
check('Nach 15 s geht sie raus', $arbiter->run()['action'] === 'sent');

section('Fehlerbehandlung');
[$messages, $settings, $board, $clock, $arbiter] = env();
$err = queue($messages, $clock, 'kids', 'FEHLER');
$board->fail(500, 'Server-Fehler');
check('API-Fehler → error', $arbiter->run()['action'] === 'error');
check('Versuch gezählt, bleibt queued', $messages->find($err)['attempts'] === 1 && $messages->find($err)['status'] === 'queued');
$board->fail(500, 'x');
$board->fail(500, 'x');
$clock->advance(60);
$arbiter->run();
$clock->advance(60);
$arbiter->run();
check('Nach 3 Versuchen → failed', $messages->find($err)['status'] === 'failed');
check('Fehler im Board-Status', $settings->get('board_status')['last_error'] === 'x');

[$messages, $settings, $board, $clock, $arbiter] = env();
$muted = queue($messages, $clock, 'kids', 'APP STUMM');
$board->fail(423, 'Board stumm');
$arbiter->run();
check('423 (Vestaboard-App stumm): kein Versuch verbraucht', $messages->find($muted)['attempts'] === 0);
check('423: externe Stummschaltung gemerkt', $settings->get('board_status')['external_mute'] === true);
$clock->advance(60);
$arbiter->run();
check('Erfolg setzt externe Stummschaltung zurück', $settings->get('board_status')['external_mute'] === false);

section('Überspringen');
[$messages, $settings, $board, $clock, $arbiter] = env();
$first = queue($messages, $clock, 'adults', 'LANG');
$arbiter->run();
$next = queue($messages, $clock, 'kids', 'DANACH');
$clock->advance(20);
check('Erwachsenen-Nachricht blockiert (10 Min)', $arbiter->run()['action'] === 'waiting');
$messages->update($first, ['ended_at' => Time::db($clock->now())]);
check('Nach Überspringen kommt die nächste', $arbiter->run()['message_id'] === $next);

section('Ruhezustand');
[$messages, $settings, $board, $clock, $arbiter] = env();
queue($messages, $clock, 'kids', 'HALLO');
$arbiter->run();
$clock->advance(60 * 60);
check('Modus "letzte Nachricht": nichts passiert', $arbiter->run()['action'] === 'idle' && count($board->sent) === 1);
$settings->set('idle', ['mode' => 'clear', 'after_min' => 30, 'text' => '', 'align' => 'center']);
check('Modus "leeren": sendet leeres Board', $arbiter->run()['action'] === 'sent' && end($board->sent)['grid'] === Charset::emptyGrid());
$clock->advance(60 * 60);
check('Ruhezustand wird nur einmal gesendet', $arbiter->run()['action'] === 'idle' && count($board->sent) === 2);
$after = queue($messages, $clock, 'kids', 'NEU');
check('Ruhezustand blockiert nichts', $arbiter->run()['message_id'] === $after);

[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('idle', ['mode' => 'text', 'after_min' => 10, 'text' => 'GUTE NACHT', 'align' => 'center']);
queue($messages, $clock, 'kids', 'HALLO');
$arbiter->run();
$clock->advance(5 * 60);
check('Ruhezustand erst nach X Min', $arbiter->run()['action'] === 'idle');
$clock->advance(5 * 60);
$arbiter->run();
check('Modus "Text": zeigt festen Text', shownText($board) === 'GUTE NACHT');
[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('idle', ['mode' => 'clear', 'after_min' => 1, 'text' => '', 'align' => 'center']);
queue($messages, $clock, 'kids', 'HALLO');
$arbiter->run();
$settings->set('mute', ['mode' => 'on', 'until' => null, 'sources' => []]);
$clock->advance(30 * 60);
check('Ruhezustand wird bei Mute nicht gesendet', $arbiter->run()['action'] === 'idle' && count($board->sent) === 1);

section('Review-Fixes');
[$messages, $settings, $board, $clock, $arbiter] = env();
$retry = queue($messages, $clock, 'kids', 'RETRY');
$board->fail(500, 'weg');
$arbiter->run();
$clock->advance(5);
check('Nach Fehler kein sofortiger Neuversuch (Seiten-Reload)', $arbiter->run()['action'] === 'waiting' && $messages->find($retry)['attempts'] === 1);
$clock->advance(60);
check('Nach 60 s neuer Versuch', $arbiter->run()['action'] === 'sent');

[$messages, $settings, $board, $clock, $arbiter] = env();
$settings->set('idle', ['mode' => 'clear', 'after_min' => 1, 'text' => '', 'align' => 'center']);
queue($messages, $clock, 'kids', 'HALLO');
$arbiter->run();
$clock->advance(120);
$board->fail(423, 'stumm');
$arbiter->run();
check('Fehlgeschlagener Ruhezustand hinterlässt keine wartende Nachricht', $messages->countQueued() === 0);

[$messages, $settings, $board, $clock, $arbiter] = env();
queue($messages, $clock, 'admin', '', ['forced' => 1, 'grid' => json_encode(Charset::emptyGrid())]);
$arbiter->run();
$clock->advance(20);
$adminForce = queue($messages, $clock, 'admin', 'NEU', ['forced' => 1]);
check('Leeres Board blockiert nachfolgende Force nicht', $arbiter->run()['message_id'] === $adminForce);

[$messages, $settings, $board, $clock, $arbiter] = env();
queue($messages, $clock, 'adults', 'A');
$arbiter->run();
$clock->advance(20);
$settings->set('last_sent_at', Time::db($clock->now()->modify('-1 second'))); // anderer Prozess hat gerade gesendet
check('Arbiter liest last_sent_at frisch (kein veralteter Cache)', queue($messages, $clock, 'admin', 'B', ['forced' => 1]) > 0 && $arbiter->run()['action'] === 'rate_limited');

section('Ruhezeiten');
$window = ['enabled' => true, 'from' => '22:00', 'to' => '07:00', 'days' => [1, 2, 3, 4, 5, 6, 7]];
$clock = new FixedClock('2026-10-26 23:30:00');
check('23:30 in Ruhezeit 22–7', Settings::inQuietWindow($window, $clock->now()));
$clock->set('2026-10-27 06:59:00');
check('06:59 am Folgetag in Ruhezeit', Settings::inQuietWindow($window, $clock->now()));
$clock->set('2026-10-27 07:00:00');
check('07:00 nicht mehr', !Settings::inQuietWindow($window, $clock->now()));
$clock->set('2026-10-26 12:00:00');
check('12:00 nicht', !Settings::inQuietWindow($window, $clock->now()));
$weekend = ['enabled' => true, 'from' => '22:00', 'to' => '09:00', 'days' => [5, 6]]; // Fr + Sa Abend
$clock->set('2026-10-31 08:00:00'); // Samstag früh → Starttag Freitag
check('Über-Mitternacht zählt der Starttag (Fr → Sa früh)', Settings::inQuietWindow($weekend, $clock->now()));
$clock->set('2026-10-26 08:00:00'); // Montag früh → Starttag Sonntag, nicht aktiv
check('Mo früh nicht (So nicht ausgewählt)', !Settings::inQuietWindow($weekend, $clock->now()));
$clock->set('2026-10-25 02:30:00'); // Zeitumstellung (Sommer → Winter)
check('Zeitumstellungsnacht korrekt in Ruhezeit', Settings::inQuietWindow($window, $clock->now()));
check('Deaktiviertes Fenster greift nicht', !Settings::inQuietWindow(['enabled' => false] + $window, (new FixedClock('2026-10-26 23:00:00'))->now()));

section('Admin und Wecker beenden die Mindestdauer anderer Quellen');
[$messages, $settings, $board, $clock, $arbiter] = env();
queue($messages, $clock, 'kids', 'KIND');
$arbiter->run();
$clock->advance(60);
$adult = queue($messages, $clock, 'adults', 'ERW');
check('Erwachsene warten auf die Mindestdauer des Kindes', $arbiter->run()['action'] === 'waiting');
$admin = queue($messages, $clock, 'admin', 'ADMIN');
$result = $arbiter->run();
check('Admin löst das Kind sofort ab', $result['action'] === 'sent' && $result['message_id'] === $admin);
$clock->advance(60);
queue($messages, $clock, 'admin', 'ADMIN 2');
check('Admin unterbricht nicht die eigene Quelle', $arbiter->run()['action'] === 'waiting');
$alarm = queue($messages, $clock, 'alarm', 'WECKER');
$result = $arbiter->run();
check('Wecker löst den Admin ab', $result['action'] === 'sent' && $result['message_id'] === $alarm);
$settings->set('interrupt_sources', []);
$clock->advance(60);
queue($messages, $clock, 'admin', 'ADMIN 3');
check('Abgeschaltet: Admin wartet wieder', $arbiter->run()['action'] === 'waiting');

finish();
