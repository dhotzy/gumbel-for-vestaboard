<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Wandelt Text in ein Vestaboard-Raster (Zeichencodes) um und zurück.
 * Zeichencodes laut https://docs.vestaboard.com/docs/charactercodes
 */
final class Charset
{
    public const ROWS = 3;
    public const COLS = 15;

    public const BLANK = 0;
    public const HEART = 62;

    /** Platzhalter im Text, z. B. "{red}" → rote Farbkachel */
    public const TOKENS = [
        'heart' => 62,
        'red' => 63,
        'orange' => 64,
        'yellow' => 65,
        'green' => 66,
        'blue' => 67,
        'violet' => 68,
        'white' => 69,
        'black' => 70,
        'filled' => 71,
    ];

    /** center/left: Text wird umgebrochen; grid: jede Zeile steht exakt so auf dem Board (Designer) */
    public const ALIGNS = ['center', 'left', 'grid'];

    private const TRANSLITERATION = [
        'Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE',
        'ä' => 'AE', 'ö' => 'OE', 'ü' => 'UE',
        'ß' => 'SS', 'ẞ' => 'SS',
        '€' => 'EUR',
        '„' => '"', '“' => '"', '”' => '"', '«' => '"', '»' => '"',
        '‚' => "'", '‘' => "'", '’' => "'", '´' => "'", '`' => "'",
        '–' => '-', '—' => '-',
        '…' => '...',
        '×' => 'X',
        '[' => '(', ']' => ')',
    ];

    /** Fallback, falls die intl-Erweiterung fehlt */
    private const ACCENTS = [
        'à' => 'A', 'á' => 'A', 'â' => 'A', 'ã' => 'A', 'å' => 'A',
        'ç' => 'C',
        'è' => 'E', 'é' => 'E', 'ê' => 'E', 'ë' => 'E',
        'ì' => 'I', 'í' => 'I', 'î' => 'I', 'ï' => 'I',
        'ñ' => 'N',
        'ò' => 'O', 'ó' => 'O', 'ô' => 'O', 'õ' => 'O', 'ø' => 'O',
        'ù' => 'U', 'ú' => 'U', 'û' => 'U',
        'ý' => 'Y', 'ÿ' => 'Y',
    ];

    private const HEART_SYMBOLS = ['♥', '❤', '♡'];

    private static ?array $charCodes = null;
    private static ?array $validCodes = null;

    /**
     * @return array{grid: list<list<int>>, fits: bool, warnings: list<string>}
     */
    public static function textToGrid(
        string $text,
        string $align = 'center',
        int $rows = self::ROWS,
        int $cols = self::COLS
    ): array {
        $unknown = [];
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8'); // kaputte Bytes werden zu "?" statt die Regex scheitern zu lassen
        }
        if ($align === 'grid') {
            return self::exactGrid($text, $unknown, $rows, $cols);
        }
        $units = self::tokenize(trim($text), $unknown);
        $lines = self::wrap($units, $cols);
        $chars = count(array_filter($units, static fn ($unit): bool => $unit !== "
"));
        $lineCount = $units === [] ? 0 : count($lines);

        $warnings = [];
        $fits = count($lines) <= $rows;
        if (!$fits) {
            $warnings[] = t('Text ist zu lang: {lines} Zeilen, erlaubt sind {rows}. Der Rest wird abgeschnitten.', ['lines' => count($lines), 'rows' => $rows]);
            $lines = array_slice($lines, 0, $rows);
        }
        if ($unknown !== []) {
            $warnings[] = t('Nicht darstellbar (durch Leerfeld ersetzt): {chars}', ['chars' => implode(' ', array_unique($unknown))]);
        }

        $grid = self::emptyGrid($rows, $cols);
        $top = intdiv($rows - count($lines), 2);
        foreach ($lines as $i => $line) {
            $left = $align === 'left' ? 0 : intdiv($cols - count($line), 2);
            foreach ($line as $j => $code) {
                $grid[$top + $i][$left + $j] = $code;
            }
        }

        return ['grid' => $grid, 'fits' => $fits, 'warnings' => $warnings, 'chars' => $chars, 'lines' => $lineCount];
    }

    /** Anzahl Board-Zeichen (Umlaute zählen doppelt: Ä → AE, {heart} einfach), ohne Zeilenumbrüche */
    /** Längere Texte werden vor dem Kürzen abgeschnitten (Schutz vor teurer Verarbeitung riesiger Eingaben) */
    public const FIT_MAX_CHARS = 1000;

    public static function length(string $text): int
    {
        $unknown = [];
        return count(array_filter(self::tokenize($text, $unknown), static fn ($unit): bool => $unit !== "\n"));
    }

    /** Auf höchstens $max Board-Zeichen kürzen – an Wortgrenzen, ein einzelnes langes Wort notfalls hart. */
    public static function cut(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        // Laufzeit begrenzen: mehr als 8 Zeichen je Board-Feld ({heart} = 7) werden nie gebraucht
        $text = trim((string) preg_replace('/\s+/u', ' ', mb_substr($text, 0, $max * 8 + 8)));
        if (self::length($text) <= $max) {
            return $text;
        }
        $words = explode(' ', $text);
        $out = '';
        foreach ($words as $word) {
            $candidate = $out === '' ? $word : $out . ' ' . $word;
            if (self::length($candidate) > $max) {
                break;
            }
            $out = $candidate;
        }
        if ($out !== '') {
            return $out;
        }
        // erstes Wort allein zu lang → zeichenweise (Token wie {heart} bleiben ganz)
        preg_match_all('/\{(' . implode('|', array_keys(self::TOKENS)) . ')\}|\X/iu', $words[0], $matches);
        foreach ($matches[0] as $unit) {
            if (self::length($out . $unit) > $max) {
                break;
            }
            $out .= $unit;
        }
        return $out;
    }

    /**
     * Kürzungsroutine: Text so kürzen, dass er aufs Board passt, statt ihn abzulehnen.
     * Zeilenumbrüche (Absätze) bleiben erhalten: jeder Absatz bekommt mindestens eine Zeile,
     * freie Zeilen gehen der Reihe nach an Absätze, die mehr brauchen. Überlanges wird abgeschnitten.
     */
    public static function fit(string $text, int $rows = self::ROWS, int $cols = self::COLS): string
    {
        // Eingaben von außen (Webhooks) begrenzen: mehr als FIT_MAX_CHARS passen nie aufs Board
        $text = trim(str_replace(["\r\n", "\r"], "\n", mb_substr($text, 0, self::FIT_MAX_CHARS)));
        if ($text === '' || self::textToGrid($text, 'center', $rows, $cols)['fits']) {
            return $text;
        }
        $paragraphs = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $p): bool => $p !== ''));
        $wrapped = array_map(static fn (string $p): array => self::wrapWords($p, $cols, $rows), array_slice($paragraphs, 0, $rows));
        $budget = array_fill(0, count($wrapped), 1);
        $free = $rows - count($wrapped);
        foreach ($wrapped as $i => $lines) {
            $extra = max(0, min($free, count($lines) - 1));
            $budget[$i] += $extra;
            $free -= $extra;
        }
        $out = [];
        foreach ($wrapped as $i => $lines) {
            array_push($out, ...array_slice($lines, 0, $budget[$i]));
        }
        return implode("\n", $out);
    }

    /** @return list<string> höchstens $maxLines Zeilen mit je höchstens $cols Board-Zeichen (Wortumbruch, Überlanges hart getrennt) */
    private static function wrapWords(string $paragraph, int $cols, int $maxLines): array
    {
        $lines = [];
        $line = '';
        foreach (explode(' ', (string) preg_replace('/\s+/u', ' ', $paragraph)) as $word) {
            if (count($lines) >= $maxLines) {
                break; // mehr Zeilen werden nie angezeigt – nicht weiter umbrechen
            }
            while ($word !== '' && count($lines) < $maxLines && self::length($word) > $cols) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $head = self::cut($word, $cols);
                if ($head === '') {
                    break 2;
                }
                $lines[] = $head;
                $word = mb_substr($word, mb_strlen($head));
            }
            if ($word === '') {
                continue;
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (self::length($candidate) > $cols) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    public static function align(mixed $align): string
    {
        return in_array($align, self::ALIGNS, true) ? $align : 'center';
    }

    /**
     * Designer-Modus: Zeile für Zeile, Leerzeichen sind Leerfelder, kein Umbruch und keine Zentrierung.
     * @param list<string> $unknown
     */
    private static function exactGrid(string $text, array &$unknown, int $rows, int $cols): array
    {
        $text = rtrim(str_replace(["\r\n", "\r"], "\n", $text), "\n");
        $lines = $text === '' ? [] : explode("\n", $text);
        $grid = self::emptyGrid($rows, $cols);
        $warnings = [];
        $fits = count($lines) <= $rows;
        $chars = 0;
        foreach (array_slice($lines, 0, $rows) as $i => $line) {
            $units = array_map(static fn ($unit) => $unit === ' ' ? self::BLANK : $unit, self::tokenize($line, $unknown));
            $chars += count(array_filter($units, static fn ($unit): bool => $unit !== self::BLANK));
            if (count($units) > $cols) {
                $fits = false;
            }
            foreach (array_slice($units, 0, $cols) as $j => $code) {
                $grid[$i][$j] = $code;
            }
        }
        if (!$fits) {
            $warnings[] = t('Im Designer passen höchstens {rows} Zeilen à {cols} Felder. Der Rest wird abgeschnitten.', ['rows' => $rows, 'cols' => $cols]);
        }
        if ($unknown !== []) {
            $warnings[] = t('Nicht darstellbar (durch Leerfeld ersetzt): {chars}', ['chars' => implode(' ', array_unique($unknown))]);
        }
        return ['grid' => $grid, 'fits' => $fits, 'warnings' => $warnings, 'chars' => $chars, 'lines' => min(count($lines), $rows)];
    }

    /** Zählertext für den Editor, z. B. "17 / 45 Zeichen · 2 von 3 Zeilen" */
    public static function counterText(array $result, int $rows = self::ROWS, int $cols = self::COLS): string
    {
        return t('{chars} / {max} Zeichen · {lines} von {rows} Zeilen', ['chars' => (int) $result['chars'], 'max' => $rows * $cols, 'lines' => (int) $result['lines'], 'rows' => $rows]);
    }

    /** @return list<list<int>> */
    public static function emptyGrid(int $rows = self::ROWS, int $cols = self::COLS): array
    {
        return array_fill(0, $rows, array_fill(0, $cols, self::BLANK));
    }

    public static function isValidGrid(mixed $grid, int $rows = self::ROWS, int $cols = self::COLS): bool
    {
        if (!is_array($grid) || count($grid) !== $rows || !array_is_list($grid)) {
            return false;
        }
        $valid = self::validCodes();
        foreach ($grid as $row) {
            if (!is_array($row) || count($row) !== $cols || !array_is_list($row)) {
                return false;
            }
            foreach ($row as $code) {
                if (!is_int($code) || !isset($valid[$code])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Die Cloud API liefert das aktuelle Layout als JSON-String.
     * @return list<list<int>>|null
     */
    public static function layoutToGrid(mixed $layout): ?array
    {
        if (is_string($layout)) {
            $layout = json_decode($layout, true);
        }
        if (!is_array($layout)) {
            return null;
        }
        $grid = [];
        foreach ($layout as $row) {
            if (!is_array($row)) {
                return null;
            }
            $grid[] = array_map('intval', array_values($row));
        }
        return $grid;
    }

    /**
     * Zeichen zu einem Code: Leerfeld → ' ', Herz → '♥', Farbkacheln/unbekannt → null.
     */
    public static function symbol(int $code): ?string
    {
        static $symbols = null;
        $symbols ??= array_map('strval', array_flip(self::charCodes()));
        return match ($code) {
            self::BLANK => ' ',
            self::HEART => '♥',
            default => $symbols[$code] ?? null,
        };
    }

    /**
     * Raster zurück in Editor-Text (Zeilen getrimmt, Farben als Platzhalter), z. B. für Änderungen von außen.
     */
    public static function gridToRawText(array $grid, bool $exact = false): string
    {
        $tokens = array_flip(self::TOKENS);
        $lines = [];
        foreach ($grid as $row) {
            $line = '';
            foreach ($row as $code) {
                $code = (int) $code;
                $line .= $code === self::HEART ? '{heart}' : (isset($tokens[$code]) ? '{' . $tokens[$code] . '}' : (self::symbol($code) ?? ' '));
            }
            $lines[] = $exact ? rtrim($line) : trim($line);
        }
        return $exact ? rtrim(implode("\n", $lines), "\n") : trim(implode("\n", $lines));
    }

    /**
     * Textdarstellung für die Konsole. Farben: r o y g b v w k, gefüllt ▓.
     */
    public static function gridToText(array $grid): string
    {
        $colors = [63 => 'r', 64 => 'o', 65 => 'y', 66 => 'g', 67 => 'b', 68 => 'v', 69 => 'w', 70 => 'k', 71 => '▓'];
        $width = count($grid[0] ?? []);
        $out = '+' . str_repeat('-', $width) . "+\n";
        foreach ($grid as $row) {
            $out .= '|';
            foreach ($row as $code) {
                $out .= self::symbol((int) $code) ?? $colors[$code] ?? '?';
            }
            $out .= "|\n";
        }
        return $out . '+' . str_repeat('-', $width) . '+';
    }

    /**
     * Zerlegt Text in Einheiten: int = Zeichencode, ' ' = Wortgrenze, "\n" = Zeilenumbruch.
     * @param list<string> $unknown
     * @return list<int|string>
     */
    private static function tokenize(string $text, array &$unknown): array
    {
        $text = str_replace(["\r\n", "\r", "\u{FE0F}"], ["\n", "\n", ''], $text);
        $pattern = '/\{(' . implode('|', array_keys(self::TOKENS)) . ')\}|\X/iu';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        $codes = self::charCodes();
        $units = [];
        foreach ($matches as $match) {
            if (isset($match[1]) && $match[1] !== '') {
                $units[] = self::TOKENS[strtolower($match[1])];
                continue;
            }
            $char = $match[0];
            if ($char === "\n") {
                $units[] = "\n";
                continue;
            }
            if (preg_match('/^\s$/u', $char)) {
                $units[] = ' ';
                continue;
            }
            if (in_array($char, self::HEART_SYMBOLS, true)) {
                $units[] = self::HEART;
                continue;
            }
            foreach (mb_str_split(self::transliterate($char)) as $single) {
                if (array_key_exists($single, $codes)) {
                    $units[] = $codes[$single];
                } else {
                    $unknown[] = $char;
                    $units[] = self::BLANK;
                }
            }
        }
        return $units;
    }

    private static function transliterate(string $char): string
    {
        if (isset(self::TRANSLITERATION[$char])) {
            return self::TRANSLITERATION[$char];
        }
        $lower = mb_strtolower($char, 'UTF-8');
        if (isset(self::ACCENTS[$lower])) {
            return self::ACCENTS[$lower];
        }
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($char, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $char = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $char;
            }
        }
        return mb_strtoupper($char, 'UTF-8');
    }

    /**
     * Bricht an Wortgrenzen um. Wörter länger als eine Zeile werden hart getrennt.
     * @param list<int|string> $units
     * @return list<list<int>>
     */
    private static function wrap(array $units, int $cols): array
    {
        $paragraphs = [[]];
        foreach ($units as $unit) {
            if ($unit === "\n") {
                $paragraphs[] = [];
            } else {
                $paragraphs[array_key_last($paragraphs)][] = $unit;
            }
        }

        $lines = [];
        foreach ($paragraphs as $paragraph) {
            $words = [];
            $word = [];
            foreach ($paragraph as $unit) {
                if ($unit === ' ') {
                    if ($word !== []) {
                        $words[] = $word;
                        $word = [];
                    }
                } else {
                    $word[] = $unit;
                }
            }
            if ($word !== []) {
                $words[] = $word;
            }

            $line = [];
            foreach ($words as $word) {
                while (count($word) > $cols) {
                    if ($line !== []) {
                        $lines[] = $line;
                        $line = [];
                    }
                    $lines[] = array_slice($word, 0, $cols);
                    $word = array_slice($word, $cols);
                }
                $needed = $line === [] ? count($word) : count($line) + 1 + count($word);
                if ($needed > $cols) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $line === [] ? $word : array_merge($line, [self::BLANK], $word);
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** @return array<string|int, int> Zeichen → Code */
    private static function charCodes(): array
    {
        if (self::$charCodes === null) {
            $codes = [];
            foreach (range('A', 'Z') as $i => $letter) {
                $codes[$letter] = $i + 1;
            }
            foreach (str_split('1234567890') as $i => $digit) {
                $codes[$digit] = $i + 27;
            }
            $codes += [
                '!' => 37, '@' => 38, '#' => 39, '$' => 40, '(' => 41, ')' => 42,
                '-' => 44, '+' => 46, '&' => 47, '=' => 48, ';' => 49, ':' => 50,
                "'" => 52, '"' => 53, '%' => 54, ',' => 55, '.' => 56, '/' => 59, '?' => 60,
            ];
            self::$charCodes = $codes;
        }
        return self::$charCodes;
    }

    /** @return array<int, true> */
    private static function validCodes(): array
    {
        if (self::$validCodes === null) {
            $valid = [self::BLANK => true];
            foreach (self::charCodes() as $code) {
                $valid[$code] = true;
            }
            foreach (self::TOKENS as $code) {
                $valid[$code] = true;
            }
            self::$validCodes = $valid;
        }
        return self::$validCodes;
    }
}
