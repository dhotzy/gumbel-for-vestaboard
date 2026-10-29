<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;

/**
 * Phase 2: erzeugt automatische Nachrichten (Wecker, Uhr, Wetter, Zufallspool).
 * Sendet nie selbst – das macht der Arbiter mit Rang, Mindestdauer und Mute.
 * Läuft minütlich im Cron vor dem Arbiter.
 */
final class Automation
{
    public const ALARM_WINDOW_MINUTES = 5;
    private const EXPIRES = ['alarm' => 15, 'clock' => 2, 'weather' => 30, 'pool' => 30, 'news' => 60];
    /** Vorgegebene Nachrichten-Feeds (Kopfzeile auf dem Board + RSS-Adresse); 'custom' = eigene Adresse */
    public const NEWS_FEEDS = [
        'tagesschau' => ['label' => 'TAGESSCHAU', 'url' => 'https://www.tagesschau.de/index~rss2.xml'],
        'bbc' => ['label' => 'BBC NEWS', 'url' => 'https://feeds.bbci.co.uk/news/rss.xml'],
    ];
    public const NEWS_MAX = 3;

    /** @param null|callable(string): ?array $fetchJson HTTP-GET → JSON (für Tests ersetzbar) */
    private $fetchJson;
    private Feed $feed;

    public function __construct(
        private readonly MessageRepo $messages,
        private readonly Settings $settings,
        private readonly Clock $clock,
        ?callable $fetchJson = null,
        ?Feed $feed = null, // RSS für „Nachrichten“ (für Tests ersetzbar)
    ) {
        $this->fetchJson = $fetchJson ?? [self::class, 'httpJson'];
        $this->feed = $feed ?? new Feed();
    }

    public static function defaults(): array
    {
        return [
            'alarms' => [],
            'clock' => ['enabled' => false, 'interval' => 60, 'style' => 'digital', 'windows' => [['days' => Schedule::ALL_DAYS, 'from' => '07:00', 'to' => '21:00']]],
            'weather' => ['enabled' => false, 'city' => null, 'mode' => 'times', 'times' => [['days' => [1, 2, 3, 4, 5], 'time' => '07:00'], ['days' => [6, 7], 'time' => '09:00']], 'interval' => 120, 'windows' => [['days' => Schedule::ALL_DAYS, 'from' => '06:30', 'to' => '20:00']]],
            'pool' => ['enabled' => false, 'items' => [], 'inactive' => [], 'order' => [], 'art' => [], 'interval' => 90, 'windows' => [['days' => Schedule::ALL_DAYS, 'from' => '08:00', 'to' => '20:00']]],
            // feed: tagesschau | bbc | custom (dann url); count: 1–3 Schlagzeilen, jede als eigene Nachricht
            'news' => ['enabled' => false, 'feed' => 'tagesschau', 'url' => '', 'count' => 1, 'times' => [['days' => [1, 2, 3, 4, 5], 'time' => '07:00'], ['days' => [6, 7], 'time' => '09:00']]],
        ];
    }

    /** @return list<string> Protokoll, was erzeugt wurde (für die Cron-Antwort) */
    public function run(): array
    {
        $now = $this->clock->now();
        $done = [];
        foreach ($this->dueAlarms($now) as [$alarm, $slot]) {
            $this->remember(self::slotKey('alarm_' . $alarm['id'], $slot), Time::local($now)->format('Y-m-d'));
            if (empty($alarm['forced']) && $this->settings->isMuted('alarm', $now)) {
                continue; // würde während der Stummschaltung nur verfallen
            }
            $this->create('alarm', (string) $alarm['text'], (string) ($alarm['align'] ?? 'center'), !empty($alarm['forced']), $now);
            $done[] = 'alarm';
        }
        $clock = $this->config('clock');
        if (!$this->settings->isMuted('clock', $now) && $this->intervalDue('clock', $clock, $now, true)) {
            $this->create('clock', self::clockText((string) $clock['style'], Time::local($now)), 'center', false, $now);
            $done[] = 'clock';
        }
        $weather = $this->config('weather');
        if (!empty($weather['city']) && !$this->settings->isMuted('weather', $now)) {
            if ($weather['mode'] === 'times') {
                $slot = $this->dueTimeSlot('weather', $weather, $now, 30);
                if ($slot !== null) {
                    $this->remember('weather', Time::db($now)); // letzter Versuch: bei Fehler frühestens in 5 Min erneut
                    if ($this->sendWeather($now) === null) {
                        $this->remember(self::slotKey('weather', $slot), Time::local($now)->format('Y-m-d'));
                        $done[] = 'weather';
                    }
                }
            } elseif ($this->intervalDue('weather', $weather, $now)) {
                $this->remember('weather', Time::db($now)); // auch bei Fehler: nicht jede Minute die API fragen
                if ($this->sendWeather($now) === null) {
                    $done[] = 'weather';
                }
            }
        }
        $pool = $this->config('pool');
        if (($pool['items'] !== [] || $pool['art'] !== []) && !$this->settings->isMuted('pool', $now) && $this->intervalDue('pool', $pool, $now)) {
            $this->remember('pool', Time::db($now));
            if ($this->sendPool($now) === null) {
                $done[] = 'pool';
            }
        }
        $news = $this->config('news');
        if (!$this->settings->isMuted('news', $now)) {
            $slot = $this->dueTimeSlot('news', $news, $now, 30);
            if ($slot !== null) {
                $this->remember('news', Time::db($now)); // bei Fehler frühestens in 5 Min erneut
                if ($this->sendNews($now) === null) {
                    $this->remember(self::slotKey('news', $slot), Time::local($now)->format('Y-m-d'));
                    $done[] = 'news';
                }
            }
        }
        return $done;
    }

    public function config(string $name): array
    {
        $value = $this->settings->get('automation_' . $name);
        $default = self::defaults()[$name];
        if (!is_array($value)) {
            return $default;
        }
        if ($name === 'alarms') {
            return array_values(array_map([self::class, 'normalizeAlarm'], array_filter($value, 'is_array')));
        }
        return self::upgrade($name, $value, $default);
    }

    /** Altformate (ein Zeitfenster from/to/days, Wetter Mo–Fr/Sa+So) → Listen von Zyklen */
    private static function upgrade(string $name, array $value, array $default): array
    {
        if (!isset($value['windows']) && isset($value['from'], $value['to'])) {
            $value['windows'] = [['days' => Schedule::days($value['days'] ?? []) ?: Schedule::ALL_DAYS, 'from' => (string) $value['from'], 'to' => (string) $value['to']]];
        }
        if ($name === 'weather') {
            if (!isset($value['times'])) {
                $times = [];
                foreach (['weekday_time' => [1, 2, 3, 4, 5], 'weekend_time' => [6, 7]] as $key => $days) {
                    if (preg_match(Schedule::TIME_PATTERN, (string) ($value[$key] ?? ''))) {
                        $times[] = ['days' => $days, 'time' => (string) $value[$key]];
                    }
                }
                if ($times !== []) {
                    $value['times'] = $times;
                }
            }
            $value['mode'] = in_array($value['mode'] ?? '', ['times', 'daily'], true) ? 'times' : 'interval';
        }
        unset($value['from'], $value['to'], $value['days'], $value['weekday_time'], $value['weekend_time']);
        return array_replace($default, $value);
    }

    /** Wecker im Altformat (eine Uhrzeit + Tage) → Zyklen */
    public static function normalizeAlarm(array $alarm): array
    {
        if (!isset($alarm['slots'])) {
            $alarm['slots'] = preg_match(Schedule::TIME_PATTERN, (string) ($alarm['time'] ?? ''))
                ? [['days' => Schedule::days($alarm['days'] ?? []) ?: Schedule::ALL_DAYS, 'time' => (string) $alarm['time']]]
                : [];
        }
        unset($alarm['time'], $alarm['days']);
        return $alarm;
    }

    public function save(string $name, array $value): void
    {
        $this->settings->set('automation_' . $name, $value);
    }

    public function state(string $key): ?string
    {
        $state = (array) ($this->settings->get('automation_state') ?? []);
        return isset($state[$key]) ? (string) $state[$key] : null;
    }

    /** Wetter jetzt holen und als Nachricht anstellen. @return ?string Fehlertext */
    public function sendWeather(?DateTimeImmutable $now = null, bool $immediate = false): ?string
    {
        $now ??= $this->clock->now();
        $city = $this->config('weather')['city'] ?? null;
        if (!is_array($city)) {
            return t('Bitte zuerst eine Stadt speichern.');
        }
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%F&longitude=%F&current=temperature_2m,weather_code&daily=temperature_2m_max,temperature_2m_min&timezone=%s&forecast_days=1',
            (float) $city['lat'],
            (float) $city['lon'],
            rawurlencode(Time::zone()->getName())
        );
        $data = ($this->fetchJson)($url);
        if (!isset($data['current']['temperature_2m'], $data['current']['weather_code'])) {
            $this->remember('weather_error', Time::db($now) . ' Wetterdienst nicht erreichbar');
            return t('Der Wetterdienst ist gerade nicht erreichbar.');
        }
        $this->remember('weather_error', '');
        $text = self::weatherText(
            (string) $city['name'],
            (float) $data['current']['temperature_2m'],
            (int) $data['current']['weather_code'],
            isset($data['daily']['temperature_2m_max'][0]) ? (float) $data['daily']['temperature_2m_max'][0] : null,
            isset($data['daily']['temperature_2m_min'][0]) ? (float) $data['daily']['temperature_2m_min'][0] : null,
        );
        $this->create('weather', $text, 'center', $immediate, $now);
        return null;
    }

    /** RSS-Adresse und Kopfzeile der eingestellten Nachrichten-Quelle. @return array{url: string, label: ?string} */
    public static function newsSource(array $config): array
    {
        $preset = self::NEWS_FEEDS[$config['feed'] ?? ''] ?? null;
        return $preset !== null ? ['url' => $preset['url'], 'label' => $preset['label']] : ['url' => (string) ($config['url'] ?? ''), 'label' => null];
    }

    /** Neueste Schlagzeilen holen und je als eigene Nachricht anstellen. @return ?string Fehlertext */
    public function sendNews(?DateTimeImmutable $now = null, bool $immediate = false): ?string
    {
        $now ??= $this->clock->now();
        $config = $this->config('news');
        $source = self::newsSource($config);
        if ($source['url'] === '') {
            return t('Bitte zuerst eine RSS-Adresse speichern.');
        }
        $fetched = $this->feed->fetch($source['url']);
        $items = array_values(array_filter((array) ($fetched['payload']['items'] ?? []), static fn ($item): bool => is_array($item) && is_string($item['title'] ?? null) && trim($item['title']) !== ''));
        if ($items === []) {
            $error = $fetched['error'] ?? t('Der Feed enthält keine Schlagzeilen (ist es ein RSS- oder Atom-Feed?).');
            $this->remember('news_error', Time::db($now) . ' ' . $error);
            return $error;
        }
        $this->remember('news_error', '');
        $feedTitle = $fetched['payload']['feed'] ?? '';
        $label = $source['label'] ?? mb_strtoupper(Charset::cut((is_string($feedTitle) ? trim($feedTitle) : '') ?: 'NEWS', Charset::COLS));
        // in Feed-Reihenfolge anstellen – die Warteschlange zeigt sie nacheinander, die oberste zuerst
        foreach (array_slice($items, 0, max(1, min(self::NEWS_MAX, (int) $config['count']))) as $item) {
            $this->create('news', $label . "\n" . trim((string) $item['title']), 'center', $immediate, $now);
        }
        return null;
    }

    /** Zufälligen Spruch anstellen (nie derselbe wie zuletzt). @return ?string Fehlertext */
    public function sendPool(?DateTimeImmutable $now = null, bool $immediate = false): ?string
    {
        $now ??= $this->clock->now();
        $config = $this->config('pool');
        $items = [];
        foreach ($config['items'] as $item) {
            if (Charset::textToGrid((string) $item)['fits']) {
                $items[] = ['text' => (string) $item, 'align' => 'center'];
            }
        }
        foreach ((array) $config['art'] as $id) {
            $art = Art::text((string) $id);
            if ($art !== null) {
                $items[] = ['text' => $art, 'align' => 'grid'];
            }
        }
        if ($items === []) {
            return t('Im Pool sind keine passenden Sprüche oder Motive.');
        }
        $last = $this->state('pool_last');
        $candidates = count($items) > 1 ? array_values(array_filter($items, static fn (array $item): bool => $item['text'] !== $last)) : $items;
        $pick = $candidates[random_int(0, count($candidates) - 1)];
        $this->remember('pool_last', $pick['text']);
        $this->remember('pool', Time::db($now));
        $this->create('pool', $pick['text'], $pick['align'], $immediate, $now);
        return null;
    }

    /** Uhr sofort zeigen (Knopf „Jetzt anzeigen“ → Force, sonst verfällt sie hinter der laufenden Nachricht). */
    public function sendClock(?DateTimeImmutable $now = null): void
    {
        $now ??= $this->clock->now();
        $this->create('clock', self::clockText((string) $this->config('clock')['style'], Time::local($now)), 'center', true, $now);
    }

    /** Stadt per Open-Meteo-Geocoding suchen. @return ?array{name: string, lat: float, lon: float} */
    public function geocode(string $name): ?array
    {
        $data = ($this->fetchJson)('https://geocoding-api.open-meteo.com/v1/search?count=1&language=' . I18n::lang() . '&format=json&name=' . rawurlencode($name));
        $hit = $data['results'][0] ?? null;
        if (!is_array($hit) || !isset($hit['latitude'], $hit['longitude'], $hit['name'])) {
            return null;
        }
        return ['name' => (string) $hit['name'], 'lat' => (float) $hit['latitude'], 'lon' => (float) $hit['longitude']];
    }

    /**
     * Nächste Termine der aktiven Wecker (für die Warteschlange), sortiert.
     * @return list<array{alarm: array, at: DateTimeImmutable}> at = Ortszeit
     */
    public function upcomingAlarms(int $days = 7): array
    {
        $now = $this->clock->now();
        $local = Time::local($now);
        $upcoming = [];
        foreach ($this->config('alarms') as $alarm) {
            if (empty($alarm['enabled'])) {
                continue;
            }
            $prefix = 'alarm_' . $alarm['id'];
            $at = Schedule::next($alarm['slots'], $local, self::ALARM_WINDOW_MINUTES,
                fn (array $slot, DateTimeImmutable $at): bool => $at->format('Y-m-d') === $local->format('Y-m-d') && $this->firedToday($prefix, $slot, $now), $days);
            if ($at !== null) {
                $upcoming[] = ['alarm' => $alarm, 'at' => $at];
            }
        }
        usort($upcoming, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        return $upcoming;
    }

    /** @return list<array{0: array, 1: array}> fällige Wecker mit ihrem Zyklus */
    private function dueAlarms(DateTimeImmutable $now): array
    {
        $due = [];
        foreach ($this->config('alarms') as $alarm) {
            if (empty($alarm['enabled'])) {
                continue;
            }
            foreach (Schedule::dueTimes($alarm['slots'], $now, self::ALARM_WINDOW_MINUTES) as $slot) {
                if (!$this->firedToday('alarm_' . $alarm['id'], $slot, $now)) {
                    $due[] = [$alarm, $slot]; // Schlüssel = Uhrzeit: nach Umstellen am selben Tag klingelt er erneut
                    break;
                }
            }
        }
        return $due;
    }

    /** Fester Zeitpunkt (z. B. Wetter 07:00) fällig? Einmal pro Tag und Zyklus, Fehlversuche frühestens nach 5 Min wiederholen. */
    private function dueTimeSlot(string $source, array $config, DateTimeImmutable $now, int $graceMinutes): ?array
    {
        if (empty($config['enabled'])) {
            return null;
        }
        foreach (Schedule::dueTimes((array) ($config['times'] ?? []), $now, $graceMinutes) as $slot) {
            if ($this->firedToday($source, $slot, $now)) {
                continue;
            }
            $last = $this->state($source);
            if ($last !== null && $last !== '' && !str_contains($last, '@') && Time::secondsBetween(Time::fromDb($last), $now) < 300) {
                return null;
            }
            return $this->hasQueued($source) ? null : $slot;
        }
        return null;
    }

    private static function slotKey(string $prefix, array $slot): string
    {
        return $prefix . '@' . $slot['time'];
    }

    private function firedToday(string $prefix, array $slot, DateTimeImmutable $now): bool
    {
        $today = Time::local($now)->format('Y-m-d');
        // Altformat: 'alarm_<id>' = "Datum Uhrzeit"
        return $this->state(self::slotKey($prefix, $slot)) === $today || $this->state($prefix) === $today . ' ' . $slot['time'];
    }

    /**
     * Intervall fällig? Nur im Zeitfenster und wenn von der Quelle nichts mehr wartet.
     * $aligned: an runden Uhrzeiten (Uhr: 14:00, 14:15 …), sonst Abstand zur letzten Nachricht.
     */
    private function intervalDue(string $source, array $config, DateTimeImmutable $now, bool $aligned = false): bool
    {
        $window = empty($config['enabled']) ? null : Schedule::activeWindow((array) ($config['windows'] ?? []), $now);
        if ($window === null) {
            return false;
        }
        // jeder Zyklus hat seinen eigenen Takt; alte Einstellungen ohne Takt nutzen das frühere gemeinsame Intervall
        $interval = max(Schedule::MIN_INTERVAL, (int) ($window['interval'] ?? $config['interval']));
        $local = Time::local($now);
        if ($aligned) {
            // Takt ab Beginn des Zeitfensters (07:00, 07:45, …) – auch bei Takten, die nicht in den Tag aufgehen
            $minuteOfDay = (int) $local->format('G') * 60 + (int) $local->format('i');
            [$fromHour, $fromMinute] = array_map('intval', explode(':', (string) ($window['from'] ?? '00:00')) + [0, 0]);
            $since = ($minuteOfDay - ($fromHour * 60 + $fromMinute) + 1440) % 1440;
            $slot = $local->format('Y-m-d ') . ($window['from'] ?? '') . '/' . $interval . '/' . intdiv($since, $interval);
            if ($since % $interval > 2 || $this->state($source) === $slot) {
                return false; // nur in den ersten Minuten des Slots (Cron-Aussetzer), nie doppelt
            }
            $this->remember($source, $slot);
        } else {
            $last = $this->state($source);
            if ($last !== null && $last !== '' && Time::secondsBetween(Time::fromDb($last), $now) < $interval * 60 - 30) {
                return false;
            }
        }
        return !$this->hasQueued($source);
    }

    private function hasQueued(string $source): bool
    {
        foreach ($this->messages->queued() as $message) {
            if ($message['source'] === $source) {
                return true;
            }
        }
        return false;
    }

    private function create(string $source, string $text, string $align, bool $forced, DateTimeImmutable $now): void
    {
        $align = Charset::align($align);
        $text = $align === 'grid' ? $text : Charset::fit($text); // z. B. Wetter mit langem Ortsnamen: kürzen statt abschneiden
        $this->messages->insert([
            'source' => $source,
            'user_id' => null,
            'text_raw' => $text,
            'align' => $align,
            'grid' => json_encode(Charset::textToGrid($text, $align)['grid']),
            'forced' => $forced ? 1 : 0,
            'status' => 'queued',
            'expires_at' => Time::db($now->modify('+' . self::EXPIRES[$source] . ' minutes')),
            'created_at' => Time::db($now),
        ]);
    }

    /** Merker löschen – auch alle Zyklus-Merker „<key>@HH:MM“ */
    public function forget(string $key): void
    {
        $state = (array) ($this->settings->get('automation_state') ?? []);
        foreach (array_keys($state) as $existing) {
            if ($existing === $key || str_starts_with((string) $existing, $key . '@')) {
                unset($state[$existing]);
            }
        }
        $this->settings->set('automation_state', $state);
    }

    private function remember(string $key, string $value): void
    {
        $state = (array) ($this->settings->get('automation_state') ?? []);
        $state[$key] = $value;
        $this->settings->set('automation_state', $state);
    }

    /** Board-Texte richten sich nach der eingestellten Sprache (I18n::lang()) */
    public static function clockText(string $style, DateTimeImmutable $local): string
    {
        if (I18n::lang() === 'en') {
            return match ($style) {
                'words' => self::timeInWords($local),
                'date' => mb_strtoupper($local->format('l')) . "\n" . mb_strtoupper($local->format('M j, Y')) . "\n" . $local->format('H:i'),
                default => '{yellow} ' . $local->format('H:i') . ' {yellow}',
            };
        }
        $weekdays = [1 => 'MONTAG', 2 => 'DIENSTAG', 3 => 'MITTWOCH', 4 => 'DONNERSTAG', 5 => 'FREITAG', 6 => 'SAMSTAG', 7 => 'SONNTAG'];
        return match ($style) {
            'words' => self::timeInWords($local),
            'date' => $weekdays[(int) $local->format('N')] . "\n" . $local->format('d.m.Y') . "\n" . $local->format('H:i') . ' UHR',
            default => '{yellow} ' . $local->format('H:i') . ' {yellow}',
        };
    }

    /** „ES IST / VIERTEL NACH / DREI“ bzw. „IT IS / QUARTER PAST / THREE“ – auf 5 Minuten gerundet */
    public static function timeInWords(DateTimeImmutable $local): string
    {
        if (I18n::lang() === 'en') {
            return self::timeInWordsEnglish($local);
        }
        $hours = [1 => 'EINS', 2 => 'ZWEI', 3 => 'DREI', 4 => 'VIER', 5 => 'FUENF', 6 => 'SECHS', 7 => 'SIEBEN', 8 => 'ACHT', 9 => 'NEUN', 10 => 'ZEHN', 11 => 'ELF', 12 => 'ZWOELF'];
        $total = (int) $local->format('G') * 60 + (int) $local->format('i');
        $rounded = (int) (round($total / 5) * 5) % 1440;
        $minute = $rounded % 60;
        $hour = intdiv($rounded, 60);
        $prefix = [
            0 => '', 5 => 'FUENF NACH', 10 => 'ZEHN NACH', 15 => 'VIERTEL NACH', 20 => 'ZWANZIG NACH', 25 => 'FUENF VOR HALB',
            30 => 'HALB', 35 => 'FUENF NACH HALB', 40 => 'ZWANZIG VOR', 45 => 'VIERTEL VOR', 50 => 'ZEHN VOR', 55 => 'FUENF VOR',
        ][$minute];
        $target = $minute >= 25 ? $hour + 1 : $hour;
        $word = $hours[(($target + 11) % 12) + 1];
        if ($minute === 0) {
            return "ES IST\n" . ($word === 'EINS' ? 'EIN' : $word) . ' UHR';
        }
        return "ES IST\n" . $prefix . "\n" . $word;
    }

    /** „IT IS / QUARTER PAST / THREE“, „IT IS / THREE O'CLOCK“; ab :35 „… TO“ die nächste Stunde */
    private static function timeInWordsEnglish(DateTimeImmutable $local): string
    {
        $hours = [1 => 'ONE', 2 => 'TWO', 3 => 'THREE', 4 => 'FOUR', 5 => 'FIVE', 6 => 'SIX', 7 => 'SEVEN', 8 => 'EIGHT', 9 => 'NINE', 10 => 'TEN', 11 => 'ELEVEN', 12 => 'TWELVE'];
        $total = (int) $local->format('G') * 60 + (int) $local->format('i');
        $rounded = (int) (round($total / 5) * 5) % 1440;
        $minute = $rounded % 60;
        $hour = intdiv($rounded, 60);
        $target = $minute > 30 ? $hour + 1 : $hour;
        $word = $hours[(($target + 11) % 12) + 1];
        if ($minute === 0) {
            return "IT IS\n" . $word . " O'CLOCK";
        }
        if ($minute === 25 || $minute === 35) {
            // „TWENTY-FIVE PAST“ wäre 16 Zeichen – deshalb ohne „IT IS“ auf drei Zeilen
            return "TWENTY-FIVE\n" . ($minute === 25 ? 'PAST' : 'TO') . "\n" . $word;
        }
        $prefix = [
            5 => 'FIVE PAST', 10 => 'TEN PAST', 15 => 'QUARTER PAST', 20 => 'TWENTY PAST', 30 => 'HALF PAST',
            40 => 'TWENTY TO', 45 => 'QUARTER TO', 50 => 'TEN TO', 55 => 'FIVE TO',
        ][$minute];
        return "IT IS\n" . $prefix . "\n" . $word;
    }

    public static function weatherText(string $city, float $temp, int $code, ?float $max, ?float $min): string
    {
        [$tile, $label] = self::weatherLabel($code);
        $line1 = $tile . ' ' . mb_strtoupper(mb_substr($city, 0, 12));
        // Das Board kennt kein Gradzeichen: Englisch „NOW -3 C“
        $line2 = I18n::lang() === 'en' ? 'NOW ' . (int) round($temp) . ' C' : 'JETZT ' . (int) round($temp) . ' GRAD';
        $line3 = $label;
        if ($max !== null && $min !== null && mb_strlen($label) <= 6) {
            $line3 .= ' ' . (int) round($min) . '/' . (int) round($max);
        }
        return $line1 . "\n" . $line2 . "\n" . $line3;
    }

    /** WMO-Wettercode → Farbkachel + kurze Beschreibung (max. 15 Zeichen) */
    private static function weatherLabel(int $code): array
    {
        if (I18n::lang() === 'en') {
            return match (true) {
                $code === 0 => ['{yellow}', 'SUNNY'],
                $code <= 2 => ['{yellow}', 'FAIR'],
                $code === 3 => ['{white}', 'CLOUDY'],
                $code <= 48 => ['{white}', 'FOG'],
                $code <= 57 => ['{blue}', 'DRIZZLE'],
                $code <= 67 => ['{blue}', 'RAIN'],
                $code <= 77 => ['{white}', 'SNOW'],
                $code <= 82 => ['{blue}', 'SHOWERS'],
                $code <= 86 => ['{white}', 'SNOW SHOWERS'],
                default => ['{violet}', 'THUNDERSTORM'],
            };
        }
        return match (true) {
            $code === 0 => ['{yellow}', 'SONNIG'],
            $code <= 2 => ['{yellow}', 'HEITER'],
            $code === 3 => ['{white}', 'BEWOELKT'],
            $code <= 48 => ['{white}', 'NEBEL'],
            $code <= 57 => ['{blue}', 'NIESELREGEN'],
            $code <= 67 => ['{blue}', 'REGEN'],
            $code <= 77 => ['{white}', 'SCHNEE'],
            $code <= 82 => ['{blue}', 'SCHAUER'],
            $code <= 86 => ['{white}', 'SCHNEESCHAUER'],
            default => ['{violet}', 'GEWITTER'],
        };
    }

    private static function httpJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'Gumbel-Vestaboard/1.0',
            CURLOPT_SSL_OPTIONS => PHP_OS_FAMILY === 'Windows' ? CURLSSLOPT_NATIVE_CA : 0,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!is_string($body) || $status !== 200) {
            error_log('Automation: HTTP ' . $status . ' für ' . parse_url($url, PHP_URL_HOST) . ($status === 0 ? ' (' . curl_error($ch) . ')' : ''));
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }
}
