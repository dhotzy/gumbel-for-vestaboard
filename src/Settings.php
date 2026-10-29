<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;
use PDO;

/**
 * Einstellungen als Key/Value (JSON) in der Tabelle settings, mit Defaults.
 */
final class Settings
{
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
        try {
            Sources::useCustom($this->get('custom_sources')); // eigene Quellen für Rangfolge, Labels, Integrationen
        } catch (\PDOException) {
            // vor der Installation gibt es noch keine Tabelle settings
        }
    }

    public static function defaults(): array
    {
        return [
            'source_order' => array_keys(Sources::all()),
            'min_duration' => array_map(static fn (array $s): int => $s['duration'], Sources::all()),
            // Sprache der Oberfläche und der Board-Texte (en | de); null = aus config 'language'
            'language' => null,
            // eigene Quellen: [['id' => 'c_buchungen', 'label' => 'Buchungen', 'duration' => 5], …]
            'custom_sources' => [],
            // mode: off | on | until; sources: einzeln stummgeschaltete Quellen; strict = auch Force wartet
            'mute' => ['mode' => 'off', 'until' => null, 'sources' => [], 'strict' => false],
            // Fenster: enabled, from "22:00", to "07:00", days [1..7] (1 = Montag)
            'quiet_hours' => [],
            'force_all' => false,
            // Diese Quellen dürfen die Mindestanzeigedauer einer Nachricht aus einer ANDEREN Quelle beenden
            'interrupt_sources' => ['alarm', 'admin'],
            'force_sources' => [],
            // mode: last | clear | text
            'idle' => ['mode' => 'last', 'after_min' => 30, 'text' => '', 'align' => 'center'],
            // Cloud API (Standard) oder Local API; Token/Key nur, wenn nicht in der Konfiguration
            'board_connection' => ['mode' => 'cloud', 'cloud_token' => '', 'local_host' => '', 'local_key' => ''],
            // Briefkasten für Gäste ohne Konto (siehe GuestPost)
            'guest_post' => GuestPost::DEFAULTS,
            // Telegram/Slack (siehe Messenger)
            'messenger' => Messenger::DEFAULTS,
            'last_sent_at' => null,
            'last_cron_at' => null,
            // zuletzt vom echten Board gelesener Stand: grid, read_at
            'board_live' => ['grid' => null, 'read_at' => null],
            'board_status' => ['last_ok_at' => null, 'last_error' => null, 'last_error_at' => null, 'external_mute' => false],
        ];
    }

    /** Cache verwerfen, z. B. wenn ein anderer Prozess (Cron) inzwischen geschrieben haben kann. */
    public function refresh(): void
    {
        $this->cache = [];
    }

    public function get(string $name): mixed
    {
        if (!array_key_exists($name, $this->cache)) {
            $statement = $this->pdo->prepare('SELECT value FROM settings WHERE name = ?');
            $statement->execute([$name]);
            $raw = $statement->fetchColumn();
            $default = self::defaults()[$name] ?? null;
            $value = $raw === false ? $default : json_decode((string) $raw, true);
            if (is_array($default) && is_array($value) && !array_is_list($default)) {
                $value = array_replace($default, $value);
            }
            $this->cache[$name] = $value;
        }
        return $this->cache[$name];
    }

    public function set(string $name, mixed $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $update = $this->pdo->prepare('UPDATE settings SET value = ? WHERE name = ?');
        $update->execute([$json, $name]);
        if ($update->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT 1 FROM settings WHERE name = ?');
            $exists->execute([$name]);
            if ($exists->fetchColumn() === false) {
                $this->pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute([$name, $json]);
            }
        }
        $this->cache[$name] = $value;
        if ($name === 'custom_sources') {
            Sources::useCustom($value);
        }
    }

    /** Rangfolge inkl. neu hinzugekommener Quellen (am Ende). @return list<string> */
    public function sourceOrder(): array
    {
        $order = array_values(array_filter((array) $this->get('source_order'), [Sources::class, 'exists']));
        foreach (array_keys(Sources::all()) as $source) {
            if (!in_array($source, $order, true)) {
                $order[] = $source;
            }
        }
        return $order;
    }

    /** 0 = höchster Rang. Unbekannte Quellen (z. B. Ruhezustand) ganz hinten. */
    public function rank(string $source): int
    {
        $index = array_search($source, $this->sourceOrder(), true);
        return $index === false ? PHP_INT_MAX : $index;
    }

    public function minDurationSeconds(string $source): int
    {
        if ($source === Sources::IDLE || $source === Sources::EXTERNAL) {
            return 0;
        }
        $durations = (array) $this->get('min_duration');
        $minutes = $durations[$source] ?? Sources::all()[$source]['duration'] ?? 5;
        return max(0, (int) $minutes) * 60;
    }

    /** Darf eine Nachricht aus $candidate die laufende aus $current vor Ablauf der Mindestdauer ablösen? */
    public function canInterrupt(string $candidate, string $current): bool
    {
        return $candidate !== $current && in_array($candidate, (array) $this->get('interrupt_sources'), true);
    }

    public function isForcedSource(string $source): bool
    {
        return (bool) $this->get('force_all') || in_array($source, (array) $this->get('force_sources'), true);
    }

    /**
     * Grund für eine globale Stummschaltung oder null.
     */
    public function globalMuteReason(DateTimeImmutable $now): ?string
    {
        $mute = (array) $this->get('mute');
        if (($mute['mode'] ?? 'off') === 'on') {
            return t('Stummgeschaltet');
        }
        if (($mute['mode'] ?? 'off') === 'until' && !empty($mute['until'])) {
            $until = Time::fromDb((string) $mute['until']);
            if ($until > $now) {
                return t('Stumm bis {time}', ['time' => Time::local($until)->format(I18n::dateFormat('d.m. H:i'))]);
            }
        }
        foreach ((array) $this->get('quiet_hours') as $window) {
            if (self::inQuietWindow((array) $window, $now)) {
                return t('Ruhezeit {from}–{to}', ['from' => $window['from'], 'to' => $window['to']]);
            }
        }
        return null;
    }

    /** Von Hand stummgeschaltet (an / an bis) – ohne Ruhezeiten. @return array{active: bool, until: ?DateTimeImmutable, strict: bool} */
    public function manualMute(DateTimeImmutable $now): array
    {
        $mute = (array) $this->get('mute');
        $mode = $mute['mode'] ?? 'off';
        $until = $mode === 'until' && !empty($mute['until']) ? Time::fromDb((string) $mute['until']) : null;
        $active = $mode === 'on' || ($until !== null && $until > $now);
        return ['active' => $active, 'until' => $active ? $until : null, 'strict' => $active && !empty($mute['strict'])];
    }

    /** „Komplett still“: auch Force-Nachrichten warten, bis die Stummschaltung endet */
    public function isStrictMute(DateTimeImmutable $now): bool
    {
        return $this->manualMute($now)['strict'];
    }

    /** Stummschaltung von Hand starten (Schnell-Mute) – einzeln stumme Quellen bleiben */
    public function startMute(?DateTimeImmutable $until, bool $strict): void
    {
        $mute = (array) $this->get('mute');
        $this->set('mute', ['mode' => $until === null ? 'on' : 'until', 'until' => $until === null ? null : Time::db($until), 'strict' => $strict] + $mute);
    }

    public function stopMute(): void
    {
        $this->set('mute', ['mode' => 'off', 'until' => null, 'strict' => false] + (array) $this->get('mute'));
    }

    public function isMuted(string $source, DateTimeImmutable $now): bool
    {
        return $this->globalMuteReason($now) !== null
            || in_array($source, (array) ($this->get('mute')['sources'] ?? []), true);
    }

    public static function inQuietWindow(array $window, DateTimeImmutable $now): bool
    {
        if (empty($window['enabled']) || empty($window['from']) || empty($window['to'])) {
            return false;
        }
        $local = Time::local($now);
        $time = $local->format('H:i');
        $today = (int) $local->format('N');
        $yesterday = $today === 1 ? 7 : $today - 1;
        $days = array_map('intval', (array) ($window['days'] ?? [1, 2, 3, 4, 5, 6, 7]));
        $from = (string) $window['from'];
        $to = (string) $window['to'];

        if ($from === $to) {
            return false;
        }
        if ($from < $to) {
            return in_array($today, $days, true) && $time >= $from && $time < $to;
        }
        // über Mitternacht, z. B. 22:00–07:00: der Starttag zählt
        return ($time >= $from && in_array($today, $days, true))
            || ($time < $to && in_array($yesterday, $days, true));
    }

    public function recordBoardOk(DateTimeImmutable $now): void
    {
        $status = (array) $this->get('board_status');
        $status['last_ok_at'] = Time::db($now);
        $status['external_mute'] = false;
        $this->set('board_status', $status);
    }

    public function recordBoardError(DateTimeImmutable $now, string $error, bool $externalMute): void
    {
        $status = (array) $this->get('board_status');
        $status['last_error'] = $error;
        $status['last_error_at'] = Time::db($now);
        $status['external_mute'] = $externalMute;
        $this->set('board_status', $status);
    }
}
