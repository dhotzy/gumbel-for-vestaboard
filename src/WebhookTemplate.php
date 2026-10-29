<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Vorlagen für Integrationen: Platzhalter {{feld.pfad|filter}}, Vorlagen mit Bedingung (Regeln) und ihre Optionen.
 * Rein funktional (keine DB) – genutzt von Webhooks, Abholen und der Vorschau.
 */
final class WebhookTemplate
{
    public const MAX_RULES = 10;
    public const OPS = ['equals' => 'ist gleich', 'contains' => 'enthält', 'exists' => 'ist vorhanden'];
    /** Anzeigedauer-Auswahl je Vorlage (Sekunden, 0 = wie die Quelle) */
    public const DURATIONS = [0 => 'wie Quelle', 60 => '1 Min', 120 => '2 Min', 300 => '5 Min', 600 => '10 Min', 1800 => '30 Min'];
    /** Ablauf-Auswahl je Vorlage (Minuten, 0 = Standard 60 bzw. expires_in_min aus dem Payload) */
    public const EXPIRES = [0 => 'Standard', 15 => '15 Min', 60 => '1 Std', 240 => '4 Std', 720 => '12 Std', 1440 => '24 Std'];

    /** Vergleiche mit übersetzten Bezeichnungen (Schlüssel wie OPS) */
    public static function ops(): array
    {
        return ['equals' => t('ist gleich'), 'contains' => t('enthält'), 'exists' => t('ist vorhanden')];
    }

    /** Anzeigedauer-Auswahl, übersetzt (Schlüssel wie DURATIONS) */
    public static function durations(): array
    {
        return [0 => t('wie Quelle'), 60 => t('1 Min'), 120 => t('2 Min'), 300 => t('5 Min'), 600 => t('10 Min'), 1800 => t('30 Min')];
    }

    /** Ablauf-Auswahl, übersetzt (Schlüssel wie EXPIRES) */
    public static function expiresOptions(): array
    {
        return [0 => t('Standard'), 15 => t('15 Min'), 60 => t('1 Std'), 240 => t('4 Std'), 720 => t('12 Std'), 1440 => t('24 Std')];
    }

    /**
     * Vorlage wählen: erste passende Regel, sonst Standard-Vorlage (fixed_text, leer = Feld "text").
     * @return array{text: string, rule: ?int, ignored: bool, options: array{force: bool, duration: int, expires: int}}
     */
    public static function render(array $hook, array $payload): array
    {
        foreach (self::rules($hook) as $i => $rule) {
            if (self::matches($rule, $payload)) {
                return [
                    'text' => $rule['template'] === '' ? '' : self::textFor($rule['template'], $payload),
                    'rule' => $i,
                    'ignored' => $rule['template'] === '',
                    'options' => self::options($rule),
                ];
            }
        }
        return ['text' => self::textFor((string) ($hook['fixed_text'] ?? ''), $payload), 'rule' => null, 'ignored' => false, 'options' => self::defaultOptions($hook)];
    }

    /** @return list<array{field: string, op: string, value: string, template: string, force: bool, duration: int, expires: int}> */
    public static function rules(array $hook): array
    {
        $rules = json_decode((string) ($hook['rules'] ?? ''), true);
        return is_array($rules) ? array_values(array_filter($rules, static fn ($r): bool => is_array($r) && ($r['field'] ?? '') !== '')) : [];
    }

    /** Optionen der Standard-Vorlage (Spalte options) */
    public static function defaultOptions(array $hook): array
    {
        $options = json_decode((string) ($hook['options'] ?? ''), true);
        return self::options(is_array($options) ? $options : []);
    }

    /** @return array{force: bool, duration: int, expires: int} */
    public static function options(array $source): array
    {
        $duration = (int) ($source['duration'] ?? 0);
        $expires = (int) ($source['expires'] ?? 0);
        return [
            'force' => !empty($source['force']),
            'duration' => isset(self::DURATIONS[$duration]) ? $duration : 0,
            'expires' => isset(self::EXPIRES[$expires]) ? $expires : 0,
        ];
    }

    /** Formular → bereinigte Regeln (Feld ist Pflicht) */
    public static function parseRules(mixed $rows): array
    {
        $rules = [];
        foreach ((array) $rows as $row) {
            $field = trim((string) ($row['field'] ?? ''));
            if ($field === '' || !preg_match('/^[\w.\-]{1,120}$/u', $field)) {
                continue;
            }
            $rules[] = [
                'field' => $field,
                'op' => isset(self::OPS[$row['op'] ?? '']) ? (string) $row['op'] : 'equals',
                'value' => mb_substr(trim((string) ($row['value'] ?? '')), 0, 120),
                'template' => mb_substr(trim(str_replace("\r", '', (string) ($row['template'] ?? ''))), 0, 300),
            ] + self::options((array) $row);
        }
        return array_slice($rules, 0, self::MAX_RULES);
    }

    public static function matches(array $rule, array $payload): bool
    {
        $value = $payload;
        foreach (explode('.', (string) $rule['field']) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }
        if (($rule['op'] ?? '') === 'exists') {
            return $value !== null && $value !== '';
        }
        $actual = mb_strtolower(is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : ''));
        $expected = mb_strtolower((string) ($rule['value'] ?? ''));
        return ($rule['op'] ?? 'equals') === 'contains' ? $expected !== '' && str_contains($actual, $expected) : $actual === $expected;
    }

    /**
     * Vorlage füllen; leere Vorlage = Feld "text" aus dem Payload.
     * Platzhalter: {{feld.pfad}}, mit Filtern {{feld|oder:KEINER|10}} – siehe applyFilter().
     */
    public static function textFor(string $template, array $payload): string
    {
        $template = trim(str_replace("\r", '', $template));
        if ($template === '') {
            return trim(is_string($payload['text'] ?? null) ? $payload['text'] : '');
        }
        return trim((string) preg_replace_callback('/\{\{\s*([\w.\-]+)((?:\s*\|[^|}]*)*)\s*\}\}/u', static function (array $m) use ($payload): string {
            $value = self::valueAt($payload, $m[1]);
            foreach (array_filter(array_map('trim', explode('|', $m[2]))) as $filter) {
                $value = self::applyFilter($value, $filter);
            }
            return $value;
        }, $template));
    }

    /**
     * Filter (englisch | deutsch): 10 (auf 10 Board-Zeichen kürzen), number|zahl (1.234,5 bzw. 1,234.5), eur (289 EUR),
     * or:TEXT|oder:TEXT (Ersatz, wenn leer), date|datum (12.11. bzw. Nov 12), time|zeit (14:00) – aus ISO-Angaben, Ortszeit.
     */
    public static function applyFilter(string $value, string $filter): string
    {
        if (ctype_digit($filter)) {
            return Charset::cut($value, (int) $filter);
        }
        [$name, $argument] = array_pad(explode(':', $filter, 2), 2, '');
        // englische und deutsche Namen gleichwertig; Ausgabe im Format der eingestellten Sprache
        $name = ['or' => 'oder', 'number' => 'zahl', 'date' => 'datum', 'time' => 'zeit'][mb_strtolower($name)] ?? mb_strtolower($name);
        $english = I18n::lang() === 'en';
        switch ($name) {
            case 'oder':
                return $value === '' ? $argument : $value;
            case 'zahl':
            case 'eur':
                $number = self::number($value);
                if ($number === null) {
                    return $value;
                }
                $formatted = number_format($number, fmod($number, 1.0) === 0.0 ? 0 : 2, $english ? '.' : ',', $english ? ',' : '.');
                return $name === 'eur' ? $formatted . ' EUR' : $formatted;
            case 'datum':
            case 'zeit':
                $date = self::date($value);
                return $date === null ? $value : $date->format($name === 'datum' ? I18n::dateFormat('d.m.') : 'H:i');
            default:
                return $value;
        }
    }

    private static function number(string $value): ?float
    {
        $clean = str_replace([' ', 'EUR', '€'], '', $value);
        if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$/', $clean)) {
            $clean = str_replace(['.', ','], ['', '.'], $clean); // deutsches Format 1.234,50
        }
        return is_numeric($clean) ? (float) $clean : null;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if ($value === '' || !preg_match('/\d{4}-\d{2}-\d{2}|^\d{9,10}$/', $value)) {
            return null;
        }
        try {
            $date = ctype_digit($value) ? new DateTimeImmutable('@' . $value) : new DateTimeImmutable($value);
            return $date->setTimezone(new DateTimeZone(Time::ZONE));
        } catch (\Exception) {
            return null;
        }
    }

    /** Wert unter „a.b.0.c“ als Text (Listen/Objekte und Fehlendes → leer) */
    public static function valueAt(array $payload, string $path): string
    {
        $value = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return '';
            }
            $value = $value[$segment];
        }
        return match (true) {
            is_bool($value) => $value ? t('JA') : t('NEIN'),
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }

    /**
     * Payload flach als Liste [Pfad, Wert] – für die Feldauswahl beim Einrichten.
     * @return list<array{0: string, 1: string}>
     */
    public static function fields(array $payload, string $prefix = '', int $limit = 60): array
    {
        $fields = [];
        foreach ($payload as $key => $value) {
            if (count($fields) >= $limit) {
                break;
            }
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                array_push($fields, ...self::fields($value, $path, $limit - count($fields)));
            } elseif (is_scalar($value) || $value === null) {
                $fields[] = [$path, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
            }
        }
        return $fields;
    }
}
