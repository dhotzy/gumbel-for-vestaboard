<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Übersetzungen: Quelltexte stehen auf Deutsch im Code (t('Speichern')), die englischen Texte in lang/en/*.php
 * (je Datei ein Array 'Deutsch' => 'English'). Platzhalter in geschweiften Klammern, Werte als zweites Argument.
 * Gleicher deutscher Text mit anderer Bedeutung: Funktion tc() mit Kontext → Schlüssel „Kontext|Text“.
 * Fehlt eine Übersetzung, erscheint der deutsche Text – tests/i18n-test.php meldet solche Lücken.
 */
final class I18n
{
    public const LANGUAGES = ['en' => 'English', 'de' => 'Deutsch'];

    private static string $lang = 'de';
    /** @var array<string, array<string, string>> */
    private static array $dictionaries = [];

    public static function set(?string $lang): void
    {
        self::$lang = isset(self::LANGUAGES[$lang ?? '']) ? (string) $lang : 'de';
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function t(string $text, array $params = [], ?string $context = null): string
    {
        if (self::$lang !== 'de') {
            $dictionary = self::dictionary(self::$lang);
            $text = $dictionary[$context !== null ? $context . '|' . $text : $text] ?? $dictionary[$text] ?? $text;
        }
        if ($params === []) {
            return $text;
        }
        $replace = [];
        foreach ($params as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }
        return strtr($text, $replace);
    }

    /** @return array<string, string> */
    public static function dictionary(string $lang): array
    {
        if (!isset(self::$dictionaries[$lang])) {
            $merged = [];
            foreach (glob(APP_ROOT . '/lang/' . $lang . '/*.php') ?: [] as $file) {
                $entries = require $file;
                if (is_array($entries)) {
                    $merged = $entries + $merged;
                }
            }
            self::$dictionaries[$lang] = $merged;
        }
        return self::$dictionaries[$lang];
    }

    /** Datumsformat der Sprache: deutsche Formate (d.m.) → englische (M j) */
    public static function dateFormat(string $format): string
    {
        if (self::$lang !== 'en') {
            return $format;
        }
        return match ($format) {
            'd.m. H:i' => 'M j, H:i',
            'd.m. H:i:s' => 'M j, H:i:s',
            'd.m.' => 'M j',
            'd.m.Y' => 'M j, Y',
            'd.m.Y H:i' => 'M j, Y H:i',
            'd.m. H:i \U\h\r' => 'M j, H:i',
            default => $format,
        };
    }

    /** Sprache für <html lang> und Zahlen */
    public static function htmlLang(): string
    {
        return self::$lang;
    }
}
