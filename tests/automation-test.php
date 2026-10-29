<?php
declare(strict_types=1);

// Aufruf: php tests/automation-test.php

require __DIR__ . '/support.php';

use Vesta\Automation;
use Vesta\Charset;
use Vesta\MessageRepo;
use Vesta\Schedule;
use Vesta\Settings;
use Vesta\Time;

function automationEnv(string $local, ?callable $fetch = null): array
{
    $pdo = memoryDb();
    $clock = new FixedClock($local);
    $messages = new MessageRepo($pdo);
    $settings = new Settings($pdo);
    $automation = new Automation($messages, $settings, $clock, $fetch ?? static fn (): ?array => null);
    return [$messages, $settings, $clock, $automation];
}

function queuedSources(MessageRepo $messages): array
{
    return array_map(static fn (array $m): string => $m['source'], $messages->queued());
}

section('Wecker');
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 06:59:00'); // Dienstag
$automation->save('alarms', [['id' => 'a1', 'enabled' => true, 'time' => '07:00', 'days' => [1, 2, 3, 4, 5], 'text' => 'AUFSTEHEN!', 'forced' => true]]);
check('Vor der Weckzeit nichts', $automation->run() === []);
$clock->set('2026-10-27 07:01:00');
check('In der Weckzeit → Wecker', $automation->run() === ['alarm']);
$alarm = $messages->queued()[0];
check('Wecker ist forced und läuft ab', (int) $alarm['forced'] === 1 && $alarm['expires_at'] !== null);
$clock->set('2026-10-27 07:02:00');
check('Gleicher Tag kein zweites Mal', $automation->run() === []);
$clock->set('2026-10-27 07:10:00');
$messages->update((int) $alarm['id'], ['status' => 'shown']);
check('Nach dem Fenster nichts', $automation->run() === []);
$clock->set('2026-11-01 07:00:00'); // Sonntag
check('Falscher Wochentag nichts', $automation->run() === []);
$clock->set('2026-11-02 07:03:00'); // Montag, Cron hatte Aussetzer
check('Nächster Werktag, verspäteter Cron → Wecker', $automation->run() === ['alarm']);

$automation->save('alarms', [['id' => 'a1', 'enabled' => true, 'time' => '07:30', 'days' => [1, 2, 3, 4, 5], 'text' => 'AUFSTEHEN!', 'forced' => true]]);
$clock->set('2026-11-02 07:31:00');
check('Am selben Tag umgestellter Wecker klingelt erneut', $automation->run() === ['alarm']);

section('Stummschaltung');
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 14:00:00');
$settings->set('mute', ['mode' => 'on', 'until' => null, 'sources' => []]);
$automation->save('clock', ['enabled' => true, 'interval' => 15, 'style' => 'digital', 'from' => '07:00', 'to' => '21:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]);
$automation->save('alarms', [
    ['id' => 'soft', 'enabled' => true, 'time' => '14:00', 'days' => [2], 'text' => 'LEISE', 'forced' => false],
    ['id' => 'hard', 'enabled' => true, 'time' => '14:00', 'days' => [2], 'text' => 'LAUT', 'forced' => true],
]);
check('Bei Mute: keine Uhr, nur der Force-Wecker', $automation->run() === ['alarm'] && array_column($messages->queued(), 'text_raw') === ['LAUT']);

section('Uhr');
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 13:59:00');
$automation->save('clock', ['enabled' => true, 'interval' => 15, 'style' => 'digital', 'from' => '07:00', 'to' => '21:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]);
check('Zwischen den Slots nichts', $automation->run() === []);
$clock->set('2026-10-27 14:00:00');
check('Volle Viertelstunde → Uhr', $automation->run() === ['clock']);
check('Uhrzeit im Text', str_contains($messages->queued()[0]['text_raw'], '14:00'));
$clock->set('2026-10-27 14:01:00');
check('Gleicher Slot nicht doppelt', $automation->run() === []);
$messages->update((int) $messages->queued()[0]['id'], ['status' => 'expired']);
$clock->set('2026-10-27 21:15:00');
check('Außerhalb des Zeitfensters nichts', $automation->run() === []);
check('Uhr in Worten: halb drei', Automation::timeInWords(new DateTimeImmutable('2026-10-27 14:31', Time::zone())) === "ES IST\nHALB\nDREI");
check('Uhr in Worten: viertel vor zwölf', Automation::timeInWords(new DateTimeImmutable('2026-10-27 23:44', Time::zone())) === "ES IST\nVIERTEL VOR\nZWOELF");
check('Uhr in Worten: ein Uhr', Automation::timeInWords(new DateTimeImmutable('2026-10-27 13:01', Time::zone())) === "ES IST\nEIN UHR");
foreach (['digital', 'words', 'date'] as $style) {
    $fits = true;
    foreach (['00:00', '09:23', '12:58', '17:35', '23:59'] as $time) {
        $fits = $fits && Charset::textToGrid(Automation::clockText($style, new DateTimeImmutable("2026-10-29 $time", Time::zone())))['fits'];
    }
    check("Uhr-Darstellung $style passt immer aufs Board", $fits);
}

section('Wetter');
$calls = 0;
$fetch = static function (string $url) use (&$calls): ?array {
    $calls++;
    return str_contains($url, 'geocoding')
        ? ['results' => [['name' => 'Gumbelstadt', 'latitude' => 50.1, 'longitude' => 8.6]]]
        : ['current' => ['temperature_2m' => -3.4, 'weather_code' => 71], 'daily' => ['temperature_2m_max' => [1.2], 'temperature_2m_min' => [-5.6]]];
};
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 08:00:00', $fetch);
$city = $automation->geocode('Gumbelstadt');
check('Geocoding liefert Koordinaten', $city !== null && $city['lat'] === 50.1);
$automation->save('weather', ['enabled' => true, 'city' => $city, 'interval' => 60, 'from' => '06:00', 'to' => '22:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]);
check('Wetter wird erzeugt', $automation->run() === ['weather']);
$text = $messages->queued()[0]['text_raw'];
check('Wettertext: Stadt, Temperatur, Schnee', str_contains($text, 'GUMBELSTADT') && str_contains($text, '-3 GRAD') && str_contains($text, 'SCHNEE'), $text);
check('Wettertext passt aufs Board', Charset::textToGrid($text)['fits']);
$clock->set('2026-10-27 08:30:00');
check('Vor Ablauf des Intervalls nichts', $automation->run() === []);
$messages->update((int) $messages->queued()[0]['id'], ['status' => 'shown']);
$clock->set('2026-10-27 09:00:00');
check('Nach dem Intervall erneut', $automation->run() === ['weather']);
$clock->set('2026-10-27 10:00:00');
check('Solange eine Wetter-Nachricht wartet, keine zweite', $automation->run() === []);

[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 08:00:00', static fn (): ?array => null);
$automation->save('weather', ['enabled' => true, 'city' => ['name' => 'X', 'lat' => 1, 'lon' => 2], 'interval' => 60, 'from' => '06:00', 'to' => '22:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]);
check('API down → nichts erzeugt, kein Absturz', $automation->run() === [] && queuedSources($messages) === []);
check('API down → Fehler gemerkt', str_contains((string) $automation->state('weather_error'), 'nicht erreichbar'));
$clock->set('2026-10-27 08:01:00');
check('API down → nicht jede Minute neu fragen', $automation->run() === []);

section('Wetter einmal am Tag');
$daily = ['enabled' => true, 'city' => ['name' => 'X', 'lat' => 1, 'lon' => 2], 'mode' => 'daily', 'weekday_time' => '06:45', 'weekend_time' => '09:00', 'interval' => 60, 'from' => '06:00', 'to' => '22:00', 'days' => [1, 2, 3, 4, 5, 6, 7]];
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 06:44:00', $fetch); // Dienstag
$automation->save('weather', $daily);
check('Werktag: vor der Uhrzeit nichts', $automation->run() === []);
$clock->set('2026-10-27 06:45:00');
check('Werktag: zur Uhrzeit Wetter', $automation->run() === ['weather']);
$messages->update((int) $messages->queued()[0]['id'], ['status' => 'shown']);
$clock->set('2026-10-27 07:00:00');
check('Werktag: nur einmal am Tag', $automation->run() === []);
$clock->set('2026-10-27 12:00:00');
check('Werktag: auch später am Tag nicht erneut', $automation->run() === []);
$clock->set('2026-10-28 07:20:00');
check('Nächster Tag, 35 Min zu spät (Cron war aus) → verfällt', $automation->run() === []);
$clock->set('2026-10-31 06:45:00'); // Samstag
check('Wochenende: Werktags-Uhrzeit gilt nicht', $automation->run() === []);
$clock->set('2026-10-31 09:00:00');
check('Wochenende: eigene Uhrzeit', $automation->run() === ['weather']);
[$messages, $settings, $clock, $automation] = automationEnv('2026-11-01 09:00:00', $fetch); // Sonntag
$automation->save('weather', ['weekend_time' => ''] + $daily);
check('Leeres Wochenend-Feld → kein Wetter', $automation->run() === []);
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 06:45:00', static fn (): ?array => null);
$automation->save('weather', $daily);
check('Dienst down → nichts, Tag nicht verbraucht', $automation->run() === [] && $automation->state('weather@06:45') === null);
$clock->set('2026-10-27 06:47:00');
check('Dienst down → nicht jede Minute', $automation->run() === []);

section('Zyklen');
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 06:30:00'); // Dienstag
$automation->save('alarms', [['id' => 'abcd1234', 'enabled' => true, 'text' => 'AUFSTEHEN', 'align' => 'center', 'forced' => true,
    'slots' => [['days' => [1, 2, 3, 4, 5], 'time' => '06:30'], ['days' => [6, 7], 'time' => '08:30']]]]);
check('Wecker Zyklus 1 (Mo–Fr 06:30)', $automation->run() === ['alarm']);
$clock->set('2026-10-27 06:32:00');
check('Wecker nicht doppelt', $automation->run() === []);
$next = $automation->upcomingAlarms()[0]['at'] ?? null;
check('Nächster Termin: Mittwoch 06:30', $next !== null && $next->format('Y-m-d H:i') === '2026-10-28 06:30', $next?->format('Y-m-d H:i') ?? '–');
$clock->set('2026-10-31 06:30:00'); // Samstag
check('Wochenende: 06:30 gilt nicht', $automation->run() === []);
$clock->set('2026-10-31 08:30:00');
check('Wochenende: Zyklus 2 (08:30)', $automation->run() === ['alarm']);
$automation->forget('alarm_abcd1234');
check('Löschen entfernt Zyklus-Merker', $automation->state('alarm_abcd1234@08:30') === null);

[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 07:00:00', $fetch);
$automation->save('weather', ['enabled' => true, 'city' => ['name' => 'X', 'lat' => 1, 'lon' => 2], 'mode' => 'times', 'times' => [['days' => Schedule::ALL_DAYS, 'time' => '07:00'], ['days' => [2], 'time' => '12:00']], 'interval' => 60, 'windows' => []]);
check('Wetter 07:00', $automation->run() === ['weather']);
$messages->update((int) $messages->queued()[0]['id'], ['status' => 'shown']);
$clock->set('2026-10-27 12:00:00');
check('Wetter zweiter Zyklus am selben Tag (12:00)', $automation->run() === ['weather']);

[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 07:30:00');
$automation->save('clock', ['enabled' => true, 'interval' => 15, 'style' => 'digital', 'windows' => [['days' => [1, 2, 3, 4, 5], 'from' => '07:00', 'to' => '08:00'], ['days' => [1, 2, 3, 4, 5], 'from' => '16:00', 'to' => '21:00']]]);
check('Uhr im ersten Zeitfenster', $automation->run() === ['clock']);
$messages->update((int) $messages->queued()[0]['id'], ['status' => 'shown']);
$clock->set('2026-10-27 12:00:00');
check('Uhr zwischen den Fenstern still', $automation->run() === []);
$clock->set('2026-10-27 16:15:00');
check('Uhr im zweiten Zeitfenster', $automation->run() === ['clock']);

check('Zyklen parsen: unvollständige fallen weg, sortiert', Schedule::parse([['days' => ['6', '7'], 'time' => '09:00'], ['days' => [], 'time' => '07:00'], ['days' => [1], 'time' => 'x'], ['days' => [1, 2, 3, 4, 5], 'time' => '06:30']], 'times') === [['days' => [1, 2, 3, 4, 5], 'time' => '06:30'], ['days' => [6, 7], 'time' => '09:00']]);
check('Kurzbeschreibung', Schedule::summary([['days' => [1, 2, 3, 4, 5], 'time' => '06:30'], ['days' => [6, 7], 'time' => '08:30'], ['days' => [1, 3, 4, 5], 'from' => '07:00', 'to' => '08:00']]) === 'Mo–Fr 06:30 · Sa + So 08:30 · Mo, Mi–Fr 07:00–08:00');

section('Zufallspool');
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 10:00:00');
$automation->save('pool', ['enabled' => true, 'items' => ['ERSTER SPRUCH', 'ZWEITER SPRUCH', str_repeat('ZU LANG ', 10)], 'interval' => 60, 'from' => '08:00', 'to' => '20:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]);
check('Pool erzeugt Spruch', $automation->run() === ['pool']);
$first = $messages->queued()[0]['text_raw'];
check('Zu lange Sprüche werden nie gewählt', $first !== str_repeat('ZU LANG ', 10));
$messages->update((int) $messages->queued()[0]['id'], ['status' => 'shown']);
$automation->sendPool();
check('Nie zweimal derselbe hintereinander', $messages->queued()[0]['text_raw'] !== $first);

section('Vestaboard-Kunst');
$allFit = true;
foreach (array_keys(Vesta\Art::ALL) as $id) {
    foreach (Vesta\Art::ALL[$id][1] as $row) {
        $allFit = $allFit && mb_strlen($row) === 15;
    }
    $allFit = $allFit && Charset::textToGrid((string) Vesta\Art::text($id), 'grid')['fits'];
}
check('Alle Motive genau 15 breit und passend', $allFit);
check('Regenbogen beginnt mit roter Kachel', Charset::textToGrid((string) Vesta\Art::text('rainbow'), 'grid')['grid'][0][0] === 63);
check('Herz behält führendes Leerfeld', Charset::textToGrid((string) Vesta\Art::text('love'), 'grid')['grid'][0][0] === 0);
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 10:00:00');
$automation->save('pool', ['enabled' => true, 'items' => [], 'art' => ['rainbow'], 'interval' => 60, 'from' => '08:00', 'to' => '20:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]);
check('Pool nur mit Kunst erzeugt Nachricht', $automation->run() === ['pool'] && $messages->queued()[0]['align'] === 'grid');

section('Board-Texte auf Englisch');
Vesta\I18n::set('en');
$at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable("2026-10-29 $time", Time::zone());
check('Words: half past two', Automation::timeInWords($at('14:31')) === "IT IS\nHALF PAST\nTWO");
check('Words: quarter to twelve', Automation::timeInWords($at('23:44')) === "IT IS\nQUARTER TO\nTWELVE");
check("Words: one o'clock", Automation::timeInWords($at('13:01')) === "IT IS\nONE O'CLOCK");
check('Words: twenty-five past three', Automation::timeInWords($at('15:24')) === "TWENTY-FIVE\nPAST\nTHREE");
check('Words: twenty-five to four', Automation::timeInWords($at('15:36')) === "TWENTY-FIVE\nTO\nFOUR");
check('Date: weekday, OCT 29, 2026, 14:00', Automation::clockText('date', $at('14:00')) === "THURSDAY\nOCT 29, 2026\n14:00");
$fits = true;
for ($minute = 0; $minute < 1440; $minute += 5) {
    $local = $at('00:00')->modify("+$minute minutes");
    foreach (['digital', 'words', 'date'] as $style) {
        $text = Automation::clockText($style, $local);
        $fits = $fits && Charset::textToGrid($text)['fits'] && ($style === 'digital' || max(array_map('mb_strlen', explode("\n", $text))) <= 15);
    }
}
$fits = $fits && Charset::textToGrid(Automation::clockText('date', new DateTimeImmutable('2026-09-30 09:00', Time::zone())))['fits']; // WEDNESDAY, SEP 30
check('Englische Uhr passt immer aufs Board (alle 5 Minuten, alle Stile)', $fits);
$weather = Automation::weatherText('Gumbelstadt', -3.4, 71, 1.2, -5.6);
check('Weather: city, NOW -3 C, SNOW -6/1', $weather === "{white} GUMBELSTADT\nNOW -3 C\nSNOW -6/1", $weather);
$allFit = true;
foreach ([0, 1, 3, 45, 51, 61, 71, 80, 85, 95] as $code) {
    $allFit = $allFit && Charset::textToGrid(Automation::weatherText('Frankfurt am Main', -12.6, $code, -10.0, -15.0))['fits'];
}
check('Englische Wettertexte passen aufs Board', $allFit);
$sayingsFit = Vesta\Sayings::all() === Vesta\Sayings::ALL_EN && count(Vesta\Sayings::ALL_EN) >= 40;
foreach (Vesta\Sayings::ALL_EN as $saying) {
    $sayingsFit = $sayingsFit && Charset::textToGrid($saying)['fits'] && substr_count($saying, "\n") <= 2;
}
check('Englische Sprüche: mind. 40, alle passen', $sayingsFit);
$artFit = true;
foreach (array_keys(Vesta\Art::ALL) as $id) {
    foreach (Vesta\Art::rows($id) as $row) {
        $artFit = $artFit && mb_strlen($row) === 15;
    }
    $artFit = $artFit && Charset::textToGrid((string) Vesta\Art::text($id), 'grid')['fits'];
}
check('Englische Motive genau 15 breit und passend', $artFit);
check('Motiv „Gute Nacht“ → GOOD NIGHT', str_contains((string) Vesta\Art::text('night'), 'GOOD NIGHT') && Vesta\Art::label('night') === 'Good night');
check('Wochentage englisch', Schedule::summary([['days' => [1, 2, 3, 4, 5], 'time' => '06:30'], ['days' => [6, 7], 'time' => '08:30'], ['days' => Schedule::ALL_DAYS, 'time' => '12:00'], ['days' => [1, 3, 4, 5], 'from' => '07:00', 'to' => '08:00']]) === 'Mon–Fri 06:30 · Sat + Sun 08:30 · daily 12:00 · Mon, Wed–Fri 07:00–08:00');
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 10:00:00');
check('Fehlertext englisch', $automation->sendPool() === 'The pool has no sayings or designs that fit.');
Vesta\I18n::set('de');
check('Zurück auf Deutsch', Automation::timeInWords($at('14:31')) === "ES IST\nHALB\nDREI" && Vesta\Sayings::all() === Vesta\Sayings::ALL);


section('Nachrichten');
$rss = '<?xml version="1.0"?><rss><channel><title>Testfeed</title>'
    . '<item><title>Erste Schlagzeile des Tages</title></item><item><title>Zweite Meldung</title></item>'
    . '<item><title>Dritte Meldung</title></item><item><title>Vierte Meldung</title></item></channel></rss>';
$feedBody = $rss;
$feedUrls = [];
$feed = new Vesta\Feed(static function (string $url) use (&$feedBody, &$feedUrls): array { $feedUrls[] = $url; return ['body' => $feedBody, 'error' => null]; });
$pdo = memoryDb();
$clock = new FixedClock('2026-10-27 06:59:00'); // Dienstag
$messages = new MessageRepo($pdo);
$settings = new Settings($pdo);
$automation = new Automation($messages, $settings, $clock, static fn (): ?array => null, $feed);
check('Standard: aus, Tagesschau, Mo–Fr 07:00 und Sa + So 09:00', $automation->config('news')['enabled'] === false && $automation->config('news')['feed'] === 'tagesschau' && count($automation->config('news')['times']) === 2);
$automation->save('news', ['enabled' => true, 'count' => 2] + $automation->config('news'));
check('Vor der Uhrzeit nichts', $automation->run() === []);
$clock->set('2026-10-27 07:01:00');
check('Zur Uhrzeit → Nachrichten', $automation->run() === ['news']);
$news = $messages->queued();
check('Zwei Schlagzeilen, je eine Nachricht', count($news) === 2 && $news[0]['source'] === 'news');
check('Oben die Quelle, darunter die Schlagzeile, passend gekürzt', str_starts_with((string) $news[0]['text_raw'], "TAGESSCHAU\n") && str_contains((string) $news[0]['text_raw'], 'ERSTE') === false && Charset::textToGrid((string) $news[0]['text_raw'])['fits']);
check('Reihenfolge wie im Feed', str_contains((string) $news[1]['text_raw'], 'Zweite'));
check('Holt die Tagesschau', $feedUrls === [Automation::NEWS_FEEDS['tagesschau']['url']]);
foreach ($messages->queued() as $m) {
    $messages->update((int) $m['id'], ['status' => 'shown']);
}
$clock->set('2026-10-27 07:20:00');
check('Nur einmal am Tag', $automation->run() === []);
$clock->set('2026-10-31 09:05:00'); // Samstag
check('Wochenende 09:00', $automation->run() === ['news']);
foreach ($messages->queued() as $m) {
    $messages->update((int) $m['id'], ['status' => 'shown']);
}
$automation->save('news', ['feed' => 'custom', 'url' => 'https://example.org/feed.xml', 'count' => 9] + $automation->config('news'));
$feedUrls = [];
check('Jetzt senden mit eigener Adresse', $automation->sendNews(null, true) === null && $feedUrls === ['https://example.org/feed.xml']);
$custom = $messages->queued();
check('Höchstens 3, Kopfzeile = Feed-Titel, sofort (Force)', count($custom) === 3 && str_starts_with((string) $custom[0]['text_raw'], 'TESTFEED') && (int) $custom[0]['forced'] === 1);
$feedBody = '{"kein": "rss"}';
$error = $automation->sendNews();
check('Kein RSS → verständlicher Fehler, gemerkt', $error !== null && str_contains((string) $automation->state('news_error'), 'RSS'));
$settings->set('mute', ['mode' => 'on', 'until' => null, 'sources' => [], 'strict' => false]);
$clock->set('2026-11-02 07:01:00');
check('Stumm → keine Nachrichten', $automation->run() === []);


section('Takt je Zyklus');
$parsed = Schedule::parse([['days' => [1, 2, 3, 4, 5], 'from' => '08:00', 'to' => '12:00', 'every_h' => '2', 'every_m' => '0'], ['days' => [1, 2, 3, 4, 5], 'from' => '12:00', 'to' => '20:00', 'every_h' => '0', 'every_m' => '45'], ['days' => [6], 'from' => '09:00', 'to' => '10:00', 'every_h' => '0', 'every_m' => '5']], 'intervals');
check('Jeder Zyklus hat seinen Takt (2 Std, 45 Min)', $parsed[0]['interval'] === 120 && $parsed[1]['interval'] === 45);
check('Takt mindestens 15 Min', $parsed[2]['interval'] === 15);
check('Normale Zeitfenster ohne Takt', !isset(Schedule::parse([['days' => [1], 'from' => '08:00', 'to' => '09:00', 'every_h' => '1']], 'windows')[0]['interval']));
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 08:00:00'); // Dienstag
$automation->save('pool', ['enabled' => true, 'items' => ['HALLO'], 'windows' => $parsed] + $automation->config('pool'));
check('Vormittag: erster Spruch', $automation->run() === ['pool']);
foreach ($messages->queued() as $m) { $messages->update((int) $m['id'], ['status' => 'shown']); }
$clock->set('2026-10-27 09:00:00');
check('Vormittag: nach 1 Std noch nicht (Takt 2 Std)', $automation->run() === []);
$clock->set('2026-10-27 10:01:00');
check('Vormittag: nach 2 Std wieder', $automation->run() === ['pool']);
foreach ($messages->queued() as $m) { $messages->update((int) $m['id'], ['status' => 'shown']); }
$clock->set('2026-10-27 12:50:00');
check('Nachmittag: Takt 45 Min', $automation->run() === ['pool']);
foreach ($messages->queued() as $m) { $messages->update((int) $m['id'], ['status' => 'shown']); }
$clock->set('2026-10-27 13:30:00');
check('Nachmittag: nach 40 Min noch nicht', $automation->run() === []);
$clock->set('2026-10-27 13:36:00');
check('Nachmittag: nach 46 Min wieder', $automation->run() === ['pool']);
$old = $automation->config('clock');
check('Alte Zeitfenster ohne Takt nutzen das frühere Intervall', !isset($old['windows'][0]['interval']) && (int) $old['interval'] === 60);


section('Uhr: Takt ab Fensterbeginn');
[$messages, $settings, $clock, $automation] = automationEnv('2026-10-27 07:00:00');
$automation->save('clock', ['enabled' => true, 'style' => 'digital', 'interval' => 60, 'windows' => [['days' => Schedule::ALL_DAYS, 'from' => '07:00', 'to' => '21:00', 'interval' => 300]]]);
check('Takt 5 Std: um 07:00', $automation->run() === ['clock']);
foreach ($messages->queued() as $m) { $messages->update((int) $m['id'], ['status' => 'shown']); }
$clock->set('2026-10-27 10:00:00');
check('… nicht um 10:00 (früher ab Mitternacht)', $automation->run() === []);
$clock->set('2026-10-27 12:01:00');
check('… wieder um 12:00', $automation->run() === ['clock']);
foreach ($messages->queued() as $m) { $messages->update((int) $m['id'], ['status' => 'shown']); }
$automation->save('clock', ['windows' => [['days' => Schedule::ALL_DAYS, 'from' => '07:00', 'to' => '21:00', 'interval' => 1440]]] + $automation->config('clock'));
$clock->set('2026-10-28 07:01:00');
check('Takt 24 Std im Fenster 07–21: einmal um 07:00', $automation->run() === ['clock']);

finish();
