<?php
declare(strict_types=1);

// Aufruf: php tests/inbound-test.php

require __DIR__ . '/support.php';

use Vesta\Inbound;
use Vesta\WebhookTemplate;
use Vesta\MessageRepo;

$pdo = memoryDb();
$clock = new FixedClock('2026-10-27 12:00:00');
$messages = new MessageRepo($pdo);
$inbound = new Inbound($pdo, $messages, $clock);

section('Schlüssel');
$nfc = $inbound->createKey('NFC Küche', 'nfc', 'ESSEN IST FERTIG', false);
$system = $inbound->createKey('Buchungssystem', 'webhook', null, true);
check('Schlüssel ist lang und zufällig', strlen($nfc['key']) === 48 && $nfc['key'] !== $system['key']);
check('Nur der Hash wird gespeichert', !str_contains(json_encode($inbound->all()), $nfc['key']));
check('Falscher Schlüssel → 401', $inbound->receive('falsch', [])['status'] === 401);
check('Leerer Schlüssel → 401', $inbound->receive('', [])['status'] === 401);

section('Empfangen');
$r = $inbound->receive($nfc['key'], []);
check('NFC ohne Text → fester Text', $r['ok'] && $messages->find($r['id'])['text_raw'] === 'ESSEN IST FERTIG');
check('Quelle des Schlüssels, ohne Autor', $messages->find($r['id'])['source'] === 'nfc' && $messages->find($r['id'])['user_id'] === null);
check('Läuft ab (Standard 60 Min)', $messages->find($r['id'])['expires_at'] === Vesta\Time::db($clock->now()->modify('+60 minutes')));
check('Gleich danach → 429', $inbound->receive($nfc['key'], [])['status'] === 429);
$clock->advance(61);
$again = $inbound->receive($nfc['key'], ['text' => 'ANDERER TEXT', 'expires_in_min' => '']);
check('Nach einer Minute wieder erlaubt', $again['ok']);
check('Leeres expires_in_min = Standard statt 1 Minute', $messages->find($again['id'])['expires_at'] === Vesta\Time::db($clock->now()->modify('+60 minutes')));
check('Buchungssystem mit Recht → Force', (int) $messages->find($inbound->receive($system['key'], ['text' => 'X', 'force' => true])['id'])['forced'] === 1);
$clock->advance(61);
$plain = $inbound->receive($nfc['key'], ['text' => 'Y', 'force' => true]);
check('Force nur mit Recht', (int) $messages->find($plain['id'])['forced'] === 0);
$clock->advance(61);
$long = $inbound->receive($system['key'], ['text' => str_repeat('WORT ', 20)]);
check('Zu langer Text wird gekürzt statt abgelehnt', $long['ok'] && Vesta\Charset::textToGrid((string) $messages->find((int) $long['id'])['text_raw'])['fits']);
$clock->advance(61);
$none = $inbound->receive($system['key'], []);
check('Ohne Text und ohne Vorlage → nicht angezeigt, Hinweis gemerkt', $none['ok'] && !empty($none['ignored']) && str_contains((string) $inbound->find($system['id'])['last_error'], 'Nicht angezeigt'));

section('Deaktivieren und Löschen');
$inbound->update($nfc['id'], ['active' => 0]);
$clock->advance(61);
check('Deaktiviert → 401', $inbound->receive($nfc['key'], [])['status'] === 401);
$inbound->update($nfc['id'], ['active' => 1]);
check('Wieder aktiv → ok', $inbound->receive($nfc['key'], [])['ok']);
$inbound->delete($nfc['id']);
$clock->advance(61);
check('Gelöscht → 401', $inbound->receive($nfc['key'], [])['status'] === 401);

section('Module, Test, neuer Schlüssel');
check('Jede Eingangs-Quelle gehört zu genau einem Modul', count(Inbound::sources()) === count(array_unique(Inbound::sources())));
check('Jede Eingangs-Quelle ist eine bekannte Quelle', array_filter(Inbound::sources(), static fn (string $s): bool => !Vesta\Sources::exists($s)) === []);
check('NFC → Modul nfc, Webhook → webhooks', Inbound::moduleOf('nfc') === 'nfc' && Inbound::moduleOf('webhook') === 'webhooks');
$k = $inbound->createKey('Flur', 'nfc', 'HALLO FLUR', false);
$t = $inbound->test($k['id']);
check('Test stellt den festen Text an (ohne Schlüssel)', $t['ok'] && $messages->find((int) $t['id'])['text_raw'] === 'HALLO FLUR');
$e = $inbound->createKey('System ohne Text', 'social', null, false);
$t = $inbound->test($e['id']);
check('Test ohne festen Text sendet Testtext', $t['ok'] && str_starts_with((string) $messages->find((int) $t['id'])['text_raw'], 'TEST'));
$new = $inbound->rotateKey($k['id']);
check('Neuer Schlüssel: alter ungültig', $inbound->receive($k['key'], [])['status'] === 401);
check('Neuer Schlüssel: neuer gilt', $new !== null && $inbound->receive($new, [])['ok']);
check('Neuer Schlüssel für unbekannten Eintrag → null', $inbound->rotateKey(99999) === null);

section('Vorlage und Testmodus');
$payload = ['event' => 'booking.created', 'booking' => ['date' => '12.11.', 'customer' => ['name' => 'Anna'], 'paid' => true], 'items' => [['title' => 'Fotobox']]];
check('Platzhalter mit Pfad', WebhookTemplate::textFor("NEU {{booking.customer.name}}\n{{ booking.date }}", $payload) === "NEU Anna\n12.11.");
check('Listen per Index, Wahrheitswerte als JA/NEIN', WebhookTemplate::textFor('{{items.0.title}} {{booking.paid}}', $payload) === 'Fotobox JA');
check('Fehlende Felder → leer', WebhookTemplate::textFor('A{{gibt.es.nicht}}B', $payload) === 'AB');
check('Leere Vorlage → Feld text', WebhookTemplate::textFor('', ['text' => ' HALLO ']) === 'HALLO');
check('Farb-Token bleiben erhalten', WebhookTemplate::textFor('{heart} {{event}}', $payload) === '{heart} booking.created');
check('Felder flach mit Pfad', in_array(['booking.customer.name', 'Anna'], WebhookTemplate::fields($payload), true) && in_array(['items.0.title', 'Fotobox'], WebhookTemplate::fields($payload), true));
$w = $inbound->createKey('Buchungen', 'webhook', "NEU\n{{booking.customer.name}}", false);
$inbound->update($w['id'], ['test_mode' => 1]);
$before = count($messages->queued());
$r = $inbound->receive($w['key'], $payload);
check('Testmodus: nichts in der Warteschlange, Text zurück', $r['ok'] && !empty($r['test']) && $r['text'] === "NEU\nAnna" && count($messages->queued()) === $before);
check('Testmodus: Payload gespeichert', json_decode((string) $inbound->find($w['id'])['last_payload'], true) === $payload);
$r2 = $inbound->receive($w['key'], $payload);
check('Testmodus: kein Rate-Limit', $r2['ok'] && !empty($r2['test']));
$inbound->update($w['id'], ['test_mode' => 0]);
$r = $inbound->receive($w['key'], $payload);
check('Normalbetrieb: Vorlage wird gefüllt und angestellt', $r['ok'] && $messages->find((int) $r['id'])['text_raw'] === "NEU\nAnna");
$long = $inbound->createKey('Zu lang', 'webhook', '{{t}}', false);
$r = $inbound->receive($long['key'], ['t' => str_repeat('SEHR LANGER TEXT ', 6)]);
check('Zu langes Ergebnis wird gekürzt', $r['ok'] && Vesta\Charset::textToGrid((string) $messages->find((int) $r['id'])['text_raw'])['fits']);
$t = $inbound->test($w['id']);
check('„Jetzt testen“ füllt Vorlage mit letztem Payload', $t['ok'] && $messages->find((int) $t['id'])['text_raw'] === "NEU\nAnna");

section('Regeln, Kürzen, Mindestabstand');
$rk = $inbound->createKey('Regeln', 'webhook', 'STANDARD {{event}}', false);
check('Webhooks: Standard ohne Mindestabstand', (int) $inbound->find($rk['id'])['min_interval'] === 0);
check('NFC: Standard 60 Sekunden', (int) $inbound->find($inbound->createKey('Bad', 'nfc', 'BAD', false)['id'])['min_interval'] === 60);
$rules = WebhookTemplate::parseRules([
    ['field' => 'event', 'op' => 'equals', 'value' => 'booking.created', 'template' => "NEUE BUCHUNG\n{{booking.customer.name|10}}"],
    ['field' => 'event', 'op' => 'contains', 'value' => 'cancel', 'template' => 'STORNO {{booking.date}}'],
    ['field' => 'event', 'op' => 'equals', 'value' => 'ping', 'template' => ''],
    ['field' => '', 'op' => 'equals', 'value' => 'x', 'template' => 'OHNE FELD'],
]);
check('Regeln: ohne Feld fallen weg', count($rules) === 3);
$inbound->update($rk['id'], ['rules' => json_encode($rules)]);
$booking = ['event' => 'booking.created', 'booking' => ['date' => '12.11.', 'customer' => ['name' => 'Anna-Maria Müller']]];
$r1 = $inbound->receive($rk['key'], $booking);
check('Regel 1 + {{feld|10}} kürzt den Namen', $r1['ok'] && $messages->find((int) $r1['id'])['text_raw'] === "NEUE BUCHUNG\nANNA-MARIA" || $messages->find((int) $r1['id'])['text_raw'] === "NEUE BUCHUNG\nAnna-Maria", (string) $messages->find((int) $r1['id'])['text_raw']);
$r2 = $inbound->receive($rk['key'], ['event' => 'booking.cancelled', 'booking' => ['date' => '13.11.']]);
check('Regel 2 (enthält) – ohne Mindestabstand sofort angenommen', $r2['ok'] && $messages->find((int) $r2['id'])['text_raw'] === 'STORNO 13.11.');
$r3 = $inbound->receive($rk['key'], ['event' => 'ping']);
check('Regel mit leerer Vorlage = ignorieren', $r3['ok'] && !empty($r3['ignored']) && $r3['id'] === null);
$r4 = $inbound->receive($rk['key'], ['event' => 'payment.done']);
check('Keine Regel passt → Standard-Vorlage', $r4['ok'] && $messages->find((int) $r4['id'])['text_raw'] === 'STANDARD payment.done');
check('render nennt die Regel', WebhookTemplate::render($inbound->find($rk['id']), $booking)['rule'] === 0 && WebhookTemplate::render($inbound->find($rk['id']), ['event' => 'x'])['rule'] === null);
check('exists-Regel', WebhookTemplate::matches(['field' => 'a.b', 'op' => 'exists', 'value' => ''], ['a' => ['b' => 1]]) && !WebhookTemplate::matches(['field' => 'a.c', 'op' => 'exists', 'value' => ''], ['a' => ['b' => 1]]));
$inbound->update($rk['id'], ['min_interval' => 60]);
check('Eingestellter Mindestabstand greift', $inbound->receive($rk['key'], ['event' => 'payment.done'])['status'] === 429);
$inbound->update($rk['id'], ['min_interval' => 0]);
$flood = $inbound->createKey('Flut', 'social', '{{n}}', false);
for ($n = 0; $n < Inbound::MAX_QUEUED_PER_SOURCE + 2; $n++) {
    $last = $inbound->receive($flood['key'], ['n' => 'NR ' . $n]);
}
check('Flut-Schutz: ab ' . Inbound::MAX_QUEUED_PER_SOURCE . ' wartenden je Quelle → 429', $last['status'] === 429);

section('Filter');
$p = ['total' => 289, 'big' => 1234.5, 'iso' => '2026-11-12T13:00:00Z', 'empty' => '', 'name' => 'Anna Maria Müller'];
check('|eur', WebhookTemplate::textFor('{{total|eur}}', $p) === '289 EUR');
check('|zahl mit Tausenderpunkt', WebhookTemplate::textFor('{{big|zahl}}', $p) === '1.234,50');
check('|datum und |zeit in Ortszeit', WebhookTemplate::textFor('{{iso|datum}} {{iso|zeit}}', $p) === '12.11. 14:00');
check('|oder:Ersatz', WebhookTemplate::textFor('{{empty|oder:GANZTAGS}} {{fehlt|oder:-}}', $p) === 'GANZTAGS -');
check('Filter kombinierbar', WebhookTemplate::textFor('{{name|oder:X|10}}', $p) === 'Anna Maria');

Vesta\I18n::set('en');
check('Englische Filternamen und -formate', WebhookTemplate::textFor('{{big|number}} {{total|eur}} {{iso|date}} {{iso|time}} {{empty|or:ALL DAY}}', $p) === '1,234.50 289 EUR Nov 12 14:00 ALL DAY');
check('Deutsche Namen gehen auch auf Englisch', WebhookTemplate::textFor('{{big|zahl}}', $p) === '1,234.50');
check('true → YES', WebhookTemplate::textFor('{{x}}', ['x' => true]) === 'YES');
Vesta\I18n::set('de');

section('Optionen je Vorlage, Zeitfenster, Duplikate');
$o = $inbound->createKey('Optionen', 'webhook', 'STANDARD', false);
$inbound->update($o['id'], [
    'rules' => json_encode(WebhookTemplate::parseRules([['field' => 'event', 'op' => 'equals', 'value' => 'booking', 'template' => 'BUCHUNG', 'force' => '1', 'duration' => '600', 'expires' => '240']])),
    'options' => json_encode(['duration' => 120]),
]);
$m = $messages->find((int) $inbound->receive($o['key'], ['event' => 'booking'])['id']);
check('Regel: Force, 10 Min Anzeige, 4 Std Ablauf', (int) $m['forced'] === 1 && (int) $m['min_duration'] === 600 && $m['expires_at'] === Vesta\Time::db($clock->now()->modify('+240 minutes')));
$m = $messages->find((int) $inbound->receive($o['key'], ['event' => 'x'])['id']);
check('Standard-Vorlage: eigene Optionen (2 Min)', (int) $m['forced'] === 0 && (int) $m['min_duration'] === 120);

$w = $inbound->createKey('Fenster', 'webhook', 'NACHT', false);
$inbound->update($w['id'], ['windows' => json_encode([['days' => Vesta\Schedule::ALL_DAYS, 'from' => '08:00', 'to' => '20:00']])]);
$clockBefore = $clock->now();
$clock->set('2026-10-27 23:00:00');
$r = $inbound->receive($w['key'], []);
$m = $messages->find((int) $r['id']);
check('Außerhalb des Zeitfensters: zurückgehalten bis 08:00', !empty($r['held_until']) && Vesta\Time::format($m['not_before'], 'd.m. H:i') === '28.10. 08:00', (string) $m['not_before']);
check('Ablauf zählt ab Fensterbeginn', $m['expires_at'] === Vesta\Time::db(Vesta\Time::fromDb((string) $m['not_before'])->modify('+60 minutes')));
check('Verlauf: Status zurückgehalten', $inbound->deliveries($w['id'])[0]['status'] === 'held');

$d = $inbound->createKey('Duplikate', 'webhook', '{{event}} {{nr}}', false);
$inbound->update($d['id'], ['dedupe_key' => '{{event}}-{{nr}}', 'dedupe_minutes' => 60]);
check('Duplikat: erstes Mal eingereiht', $inbound->receive($d['key'], ['event' => 'booking', 'nr' => '31'])['id'] !== null);
$dup = $inbound->receive($d['key'], ['event' => 'booking', 'nr' => '31']);
check('Duplikat: zweites Mal ignoriert', !empty($dup['duplicate']) && $inbound->deliveries($d['id'])[0]['status'] === 'duplicate');
check('Duplikat: andere Nummer geht durch', $inbound->receive($d['key'], ['event' => 'booking', 'nr' => '32'])['id'] !== null);
$clock->advance(61 * 60);
check('Duplikat: nach dem Zeitraum wieder erlaubt', $inbound->receive($d['key'], ['event' => 'booking', 'nr' => '31'])['id'] !== null);

section('HMAC-Signatur');
$h = $inbound->createKey('Signiert', 'webhook', '{{text}}', false);
$secret = $inbound->rotateSecret($h['id']);
$inbound->update($h['id'], ['require_signature' => 1]);
$raw = '{"text":"SIGNIERT"}';
$ts = (string) $clock->now()->getTimestamp();
$good = ['raw' => $raw, 'timestamp' => $ts, 'signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $raw, (string) $secret)];
check('Gültige Signatur → eingereiht', $inbound->receive($h['key'], ['text' => 'SIGNIERT'], $good)['ok']);
check('Ohne Signatur → 401', $inbound->receive($h['key'], ['text' => 'X'], ['raw' => '{}'])['status'] === 401);
check('Falsche Signatur → 401', $inbound->receive($h['key'], ['text' => 'X'], ['signature' => 'sha256=abc'] + $good)['status'] === 401);
$old = (string) ($clock->now()->getTimestamp() - 600);
check('Zu alter Zeitstempel → 401', $inbound->receive($h['key'], ['text' => 'X'], ['timestamp' => $old, 'signature' => 'sha256=' . hash_hmac('sha256', $old . '.' . $raw, (string) $secret)] + $good)['status'] === 401);
check('Fehlversuch steht im Verlauf', $inbound->deliveries($h['id'])[0]['status'] === 'error');

section('Rezepte');
$recipe = Inbound::export($inbound->find($o['id']));
check('Export enthält keine Geheimnisse', !str_contains(json_encode($recipe), 'key_hash') && !isset($recipe['signing_secret']) && $recipe['format'] === 'gumbel-integration');
$imported = $inbound->import($recipe);
$copy = $inbound->find((int) $imported['id']);
check('Import: neue Integration mit eigenem Schlüssel, Regeln übernommen', $imported['ok'] && $imported['key'] !== $o['key'] && count(WebhookTemplate::rules($copy)) === 1);
check('Import startet im Testmodus', (int) $copy['test_mode'] === 1);
check('Kein Rezept → Fehler', !$inbound->import(['name' => 'x'])['ok']);

section('Verlauf');
$v = $inbound->createKey('Viel', 'webhook', '{{n}}', false);
for ($n = 0; $n < Inbound::LOG_KEEP + 5; $n++) {
    $inbound->update($v['id'], ['test_mode' => 1]);
    $inbound->receive($v['key'], ['n' => 'NR ' . $n]);
}
check('Verlauf behält die letzten ' . Inbound::LOG_KEEP, count($inbound->deliveries($v['id'], 200)) === Inbound::LOG_KEEP);
check('Neueste zuerst', $inbound->deliveries($v['id'])[0]['text'] === 'NR ' . (Inbound::LOG_KEEP + 4));

section('Abrufen (JSON/RSS)');
$rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>Nachrichten</title><item><title>Erste &amp; beste Meldung</title><link>https://example.com/1</link><pubDate>Tue, 27 Oct 2026 10:00:00 GMT</pubDate></item><item><title>Zweite</title></item></channel></rss>';
$parsed = Vesta\Feed::parse($rss);
check('RSS: erster Eintrag oben, Liste darunter', $parsed['title'] === 'Erste & beste Meldung' && $parsed['feed'] === 'Nachrichten' && count($parsed['items']) === 2);
$atom = '<feed xmlns="http://www.w3.org/2005/Atom"><title>A</title><entry><title>Atom-Eintrag</title><link href="https://x.y/1"/><updated>2026-10-27T10:00:00Z</updated></entry></feed>';
check('Atom wird gelesen', Vesta\Feed::parse($atom)['title'] === 'Atom-Eintrag');
check('JSON bleibt JSON', Vesta\Feed::parse('{"a":{"b":1}}') === ['a' => ['b' => 1]]);
check('Kein Feed → null', Vesta\Feed::parse('Hallo') === null);
check('Interne Adressen gesperrt', Vesta\Feed::checkUrl('http://127.0.0.1/x') !== null && Vesta\Feed::checkUrl('file:///etc/passwd') !== null && Vesta\Feed::checkUrl('http://10.0.0.5/') !== null);

$body = '{"headline": "ERSTE"}';
$feed = new Vesta\Feed(static function () use (&$body): array { return ['body' => $body, 'error' => null]; });
$pullInbound = new Inbound($pdo, $messages, $clock, $feed);
$f = $pullInbound->createKey('Feed', 'feed', '{{headline}}', false);
$pullInbound->update($f['id'], ['pull_url' => 'https://93.184.216.34/feed.json', 'pull_interval' => 15]);
check('Abruf: erster Lauf reiht ein', $pullInbound->pollDue() === ['ok'] && $pullInbound->deliveries($f['id'])[0]['text'] === 'ERSTE');
$clock->advance(5 * 60);
check('Abruf: vor dem Intervall nichts', $pullInbound->pollDue() === []);
$clock->advance(15 * 60);
$pullInbound->pollDue();
check('Abruf: gleicher Inhalt → nicht nochmal', count($pullInbound->deliveries($f['id'])) === 1);
$body = '{"headline": "ZWEITE"}';
$clock->advance(15 * 60);
$pullInbound->pollDue();
check('Abruf: neuer Inhalt → eingereiht', $pullInbound->deliveries($f['id'])[0]['text'] === 'ZWEITE');
$body = 'kaputt';
$clock->advance(15 * 60);
$pullInbound->pollDue();
check('Abruf: Fehler steht im Verlauf und an der Integration', $pullInbound->deliveries($f['id'])[0]['status'] === 'error' && $pullInbound->find($f['id'])['last_error'] !== null);

section('Eigene Header beim Abrufen');
$parsed = Vesta\Feed::parseHeaders("Authorization: Bearer abc\nX-Api-Key:  k1 ");
check('Header werden gelesen', $parsed['error'] === null && $parsed['headers'] === [['Authorization', 'Bearer abc'], ['X-Api-Key', 'k1']]);
check('Kaputte Zeile → Fehler', Vesta\Feed::parseHeaders('kein header')['error'] !== null);
check('Host darf nicht gesetzt werden', Vesta\Feed::parseHeaders('Host: evil.example')['error'] !== null);
check('Zeilenumbruch im Wert unmöglich', Vesta\Feed::parseHeaders("X-A: a\rb")['error'] !== null || Vesta\Feed::parseHeaders("X-A: a\rb")['headers'][0][1] === 'a');
check('Höchstens 5 Header', Vesta\Feed::parseHeaders(implode("\n", array_map(static fn ($i) => "X-$i: v", range(1, 6))))['error'] !== null);
$kept = Vesta\Feed::parseHeaders('Authorization: ' . Vesta\Feed::MASK, [['Authorization', 'Bearer geheim']]);
check('Maskierter Wert behält den gespeicherten', $kept['headers'] === [['Authorization', 'Bearer geheim']]);
check('Maskiert ohne gespeicherten Wert → Fehler', Vesta\Feed::parseHeaders('X-New: ' . Vesta\Feed::MASK)['error'] !== null);
$sent = null;
$hFeed = new Vesta\Feed(static function (string $url, array $headers) use (&$sent): array { $sent = $headers; return ['body' => '{"a":"HALLO"}', 'error' => null]; });
$hInbound = new Inbound($pdo, $messages, $clock, $hFeed);
$h = $hInbound->createKey('Mit Header', 'feed', '{{a}}', false);
$hInbound->update($h['id'], ['pull_url' => 'https://93.184.216.34/x.json?city=Berlin&apikey=GEHEIM123', 'pull_headers' => json_encode([['X-Api-Key', 'GEHEIM456']])]);
$hInbound->poll($hInbound->find($h['id']));
check('Header gehen mit dem Abruf raus', $sent === [['X-Api-Key', 'GEHEIM456']]);
check('Formular zeigt Wert nicht', !str_contains(Vesta\Feed::headersForForm($hInbound->find($h['id'])), 'GEHEIM'));
$exported = Inbound::export($hInbound->find($h['id']));
check('Rezept enthält keine Schlüssel', !str_contains(json_encode($exported), 'GEHEIM') && $exported['pull_headers'] === ['X-Api-Key']);
check('Schlüssel in der URL wird maskiert, Rest bleibt', str_contains($exported['pull_url'], 'city=Berlin') && str_contains($exported['pull_url'], 'apikey=YOUR-KEY'));
$imported = $hInbound->import($exported);
$imp = $hInbound->find($imported['id']);
check('Import: Header-Namen mit Platzhalter, inaktiv', Vesta\Feed::storedHeaders($imp) === [['X-Api-Key', 'YOUR-KEY']] && (int) $imp['active'] === 0);


section('Großer Payload bleibt gültiges JSON');
$big = ['order' => ['name' => '#1001', 'note' => str_repeat('x', 5000), 'line_items' => array_fill(0, 200, ['title' => str_repeat('Artikel ', 20), 'price' => '9.99'])]];
$compact = Inbound::compactJson($big, 6000);
$decoded = json_decode($compact, true);
check('Gekürzt, aber gültiges JSON mit Feldern', strlen($compact) <= 6000 && is_array($decoded) && $decoded['order']['name'] === '#1001');
check('Kleiner Payload bleibt unverändert', Inbound::compactJson(['a' => 'b'], 6000) === '{"a":"b"}');
$bigHook = $inbound->createKey('Groß', 'webhook', '{{order.name}}', false);
$inbound->update($bigHook['id'], ['test_mode' => 1]);
$inbound->receive($bigHook['key'], $big);
check('Letzter Aufruf eines großen Payloads hat Felder', is_array(json_decode((string) $inbound->find($bigHook['id'])['last_payload'], true)));

finish();
