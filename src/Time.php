<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Zeitstempel werden in UTC gespeichert und für die Anzeige in die eingestellte Zeitzone umgerechnet
 * (config 'timezone', in Docker die Variable TZ; Standard Europe/Berlin).
 */
final class Time
{
    public const ZONE = 'Europe/Berlin';
    private static ?DateTimeZone $zone = null;
    private const DB_FORMAT = 'Y-m-d H:i:s';

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public static function zone(): DateTimeZone
    {
        if (self::$zone === null) {
            try {
                $name = (string) (app_config()['timezone'] ?? self::ZONE);
            } catch (\RuntimeException) {
                $name = self::ZONE; // ohne Konfigurationsdatei (Tests)
            }
            self::$zone = in_array($name, \DateTimeZone::listIdentifiers(), true) ? new DateTimeZone($name) : new DateTimeZone(self::ZONE);
        }
        return self::$zone;
    }

    public static function db(DateTimeImmutable $time): string
    {
        return $time->setTimezone(self::utc())->format(self::DB_FORMAT);
    }

    public static function fromDb(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, self::utc());
    }

    public static function local(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(self::zone());
    }

    /** Formatiert einen DB-Zeitstempel in Ortszeit, z. B. "26.10. 14:30". */
    public static function format(?string $dbValue, string $format = 'd.m. H:i'): string
    {
        return $dbValue === null || $dbValue === '' ? '–' : self::local(self::fromDb($dbValue))->format(I18n::dateFormat($format));
    }

    /** Liest "2026-10-26T18:00" (datetime-local, Ortszeit). */
    public static function parseLocal(string $input): ?DateTimeImmutable
    {
        $input = trim($input);
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $input, self::zone());
            if ($parsed !== false) {
                return $parsed->setTimezone(self::utc());
            }
        }
        return null;
    }

    /** Für value-Attribute von datetime-local-Feldern. */
    public static function toLocalInput(?string $dbValue): string
    {
        return $dbValue === null || $dbValue === '' ? '' : self::local(self::fromDb($dbValue))->format('Y-m-d\TH:i');
    }

    public static function secondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }

    /** "3 Min", "1 Std 5 Min" */
    public static function duration(int $seconds): string
    {
        $minutes = (int) ceil(max(0, $seconds) / 60);
        $min = I18n::lang() === 'en' ? 'min' : 'Min';
        if ($minutes < 60) {
            return $minutes . ' ' . $min;
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        return $hours . (I18n::lang() === 'en' ? ' h' : ' Std') . ($rest > 0 ? " $rest $min" : '');
    }
}
