<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;

/**
 * Zeitpläne aus mehreren „Zyklen“ – wiederverwendbar für Wecker, Wetter, Uhr, Zufallspool.
 *  - Zeitpunkte: [['days' => [1..7], 'time' => 'HH:MM'], …]          (einmal am Tag je Zyklus)
 *  - Zeitfenster: [['days' => [1..7], 'from' => 'HH:MM', 'to' => 'HH:MM'], …]
 */
final class Schedule
{
    public const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
    public const ALL_DAYS = [1, 2, 3, 4, 5, 6, 7];
    public const MAX_SLOTS = 10;

    /** Wochentagskürzel in der aktuellen Sprache (Mo … So / Mon … Sun) */
    public static function dayLabel(int $day): string
    {
        return match ($day) {
            1 => t('Mo'),
            2 => t('Di'),
            3 => t('Mi'),
            4 => t('Do'),
            5 => t('Fr'),
            6 => t('Sa'),
            default => t('So'),
        };
    }

    /** @return list<int> */
    public static function days(mixed $days): array
    {
        $days = array_values(array_unique(array_intersect(array_map('intval', (array) $days), self::ALL_DAYS)));
        sort($days);
        return $days;
    }

    /** Formular-Eingabe (z. B. $_POST['times']) → bereinigte Zyklen; unvollständige fallen weg. */
    public static function parse(mixed $rows, string $kind): array
    {
        $slots = [];
        foreach ((array) $rows as $row) {
            $days = self::days($row['days'] ?? []);
            if ($days === []) {
                continue;
            }
            if ($kind === 'times') {
                $time = (string) ($row['time'] ?? '');
                if (preg_match(self::TIME_PATTERN, $time)) {
                    $slots[] = ['days' => $days, 'time' => $time];
                }
            } else {
                $from = (string) ($row['from'] ?? '');
                $to = (string) ($row['to'] ?? '');
                if (preg_match(self::TIME_PATTERN, $from) && preg_match(self::TIME_PATTERN, $to) && $from !== $to) {
                    $slot = ['days' => $days, 'from' => $from, 'to' => $to];
                    if ($kind === 'intervals') { // Zeitfenster mit eigenem Takt: alle X Std Y Min (15 Min bis 24 Std)
                        $minutes = (int) ($row['every_h'] ?? 0) * 60 + (int) ($row['every_m'] ?? 0);
                        $slot['interval'] = max(self::MIN_INTERVAL, min(1440, $minutes ?: 60));
                    }
                    $slots[] = $slot;
                }
            }
        }
        if ($kind === 'times') {
            usort($slots, static fn (array $a, array $b): int => strcmp($a['time'], $b['time']));
        }
        return array_slice($slots, 0, self::MAX_SLOTS);
    }

    public const MIN_INTERVAL = 15;

    /** Erstes Zeitfenster, in dem $now liegt (mit seinem Intervall), oder null */
    public static function activeWindow(array $windows, DateTimeImmutable $now): ?array
    {
        foreach ($windows as $window) {
            if (Settings::inQuietWindow(['enabled' => true] + (array) $window, $now)) {
                return (array) $window;
            }
        }
        return null;
    }

    /** Liegt $now in einem der Zeitfenster? */
    public static function inWindows(array $windows, DateTimeImmutable $now): bool
    {
        foreach ($windows as $window) {
            if (Settings::inQuietWindow(['enabled' => true] + (array) $window, $now)) {
                return true;
            }
        }
        return false;
    }

    /** Nächster Beginn eines Zeitfensters nach $now (UTC zurück), null = keins in den nächsten 8 Tagen */
    public static function nextWindowStart(array $windows, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $local = Time::local($now);
        $best = null;
        foreach ($windows as $window) {
            for ($offset = 0; $offset <= 8; $offset++) {
                $day = $local->modify("+$offset days");
                if (!in_array((int) $day->format('N'), self::days($window['days'] ?? []), true)) {
                    continue;
                }
                $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day->format('Y-m-d') . ' ' . ($window['from'] ?? ''), $local->getTimezone());
                if ($start !== false && $start > $local) {
                    $best = $best === null || $start < $best ? $start : $best;
                    break;
                }
            }
        }
        return $best?->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Zeitpunkte, die jetzt fällig sind (heute, ab der Uhrzeit, höchstens $graceMinutes später).
     * @return list<array> fällige Zyklen
     */
    public static function dueTimes(array $times, DateTimeImmutable $now, int $graceMinutes): array
    {
        $local = Time::local($now);
        $due = [];
        foreach ($times as $slot) {
            if (!in_array((int) $local->format('N'), self::days($slot['days'] ?? []), true) || !preg_match(self::TIME_PATTERN, (string) ($slot['time'] ?? ''))) {
                continue;
            }
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $local->format('Y-m-d') . ' ' . $slot['time'], $local->getTimezone());
            if ($start !== false && $local >= $start && $local < $start->modify("+$graceMinutes minutes")) {
                $due[] = $slot;
            }
        }
        return $due;
    }

    /** Nächster Zeitpunkt ab $local (inkl. bis zu $graceMinutes zurück), der $skip nicht ausschließt. */
    public static function next(array $times, DateTimeImmutable $local, int $graceMinutes, ?callable $skip = null, int $days = 7): ?DateTimeImmutable
    {
        $best = null;
        foreach ($times as $slot) {
            for ($offset = 0; $offset <= $days; $offset++) {
                $day = $local->modify("+$offset days");
                $at = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day->format('Y-m-d') . ' ' . ($slot['time'] ?? ''), $local->getTimezone());
                if ($at === false || !in_array((int) $day->format('N'), self::days($slot['days'] ?? []), true)) {
                    continue;
                }
                if ($at >= $local->modify("-$graceMinutes minutes") && ($skip === null || !$skip($slot, $at))) {
                    $best = $best === null || $at < $best ? $at : $best;
                    break;
                }
            }
        }
        return $best;
    }

    /** „Mo–Fr“, „Sa, So“, „täglich“ */
    public static function daysLabel(array $days): string
    {
        $days = self::days($days);
        if ($days === self::ALL_DAYS) {
            return t('täglich');
        }
        if ($days === [1, 2, 3, 4, 5]) {
            return t('Mo–Fr');
        }
        if ($days === [6, 7]) {
            return t('Sa + So');
        }
        // zusammenhängende Läufe zusammenfassen: Mo, Mi–Fr
        $parts = [];
        $run = [];
        foreach ($days as $day) {
            if ($run !== [] && $day !== end($run) + 1) {
                $parts[] = $run;
                $run = [];
            }
            $run[] = $day;
        }
        if ($run !== []) {
            $parts[] = $run;
        }
        return implode(', ', array_map(static fn (array $r): string => count($r) >= 3 ? self::dayLabel($r[0]) . '–' . self::dayLabel(end($r)) : implode(', ', array_map(static fn (int $d): string => self::dayLabel($d), $r)), $parts));
    }

    /** Kurzbeschreibung für Listen: „Mo–Fr 06:30 · Sa + So 08:30“ */
    public static function summary(array $slots): string
    {
        return implode(' · ', array_map(static fn (array $s): string => self::daysLabel($s['days'] ?? []) . ' ' . (isset($s['time']) ? $s['time'] : ($s['from'] ?? '') . '–' . ($s['to'] ?? '')), $slots));
    }
}
