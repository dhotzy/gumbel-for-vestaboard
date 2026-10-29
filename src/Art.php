<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Vestaboard-Kunst: fertige 3×15-Motive aus Farbkacheln und Zeichen (Designer-Raster).
 * Notation pro Zeile: r o y g b v w k = Farbkachel (k = schwarz), . = leer, alles andere = Zeichen.
 */
final class Art
{
    private const TILES = ['r' => 'red', 'o' => 'orange', 'y' => 'yellow', 'g' => 'green', 'b' => 'blue', 'v' => 'violet', 'w' => 'white', 'k' => 'black'];

    public const ALL = [
        'rainbow' => ['Regenbogen', ['roygbvroygbvroy', 'oygbvroygbvroyg', 'ygbvroygbvroygb']],
        'sunset' => ['Sonnenuntergang', ['vvvvvvvvvvvvvvv', 'ooooooyyyoooooo', 'bbbbbbbbbbbbbbb']],
        'love' => ['Herz', ['.rr.rr.........', 'rrrrrrr.LOVE...', '.rrrrr.........']],
        'hearts' => ['Herzen-Muster', ['♥.♥.♥.♥.♥.♥.♥.♥', '.♥.♥.♥.♥.♥.♥.♥.', '♥.♥.♥.♥.♥.♥.♥.♥']],
        'checker' => ['Schachbrett', ['wkwkwkwkwkwkwkw', 'kwkwkwkwkwkwkwk', 'wkwkwkwkwkwkwkw']],
        'waves' => ['Wellen', ['b..b..b..b..b..', '.b.b.b.b.b.b.b.', '..b..b..b..b..b']],
        'night' => ['Gute Nacht', ['v.w.vv.w.vvw.v.', '..GUTE.NACHT...', 'vw.v.wvv.w.v.wv']],
        'party' => ['Party', ['r.y.b.g.o.v.r.y', '...PARTY!!!....', 'g.o.v.r.y.b.g.o']],
        'tree' => ['Tannenbaum', ['.......y.......', '......grg......', '.....gygbg.....']],
        'flag' => ['Schwarz-Rot-Gold', ['kkkkkkkkkkkkkkk', 'rrrrrrrrrrrrrrr', 'yyyyyyyyyyyyyyy']],
        'smile' => ['Smileys', ['.:-)....;-)....', '....:-D....:-P.', '.(-:....=).....']],
        'stripes' => ['Streifen', ['rrrrroooooyyyyy', 'gggggbbbbbvvvvv', 'rrrrroooooyyyyy']],
        'frame' => ['Rahmen „Hallo“', ['yyyyyyyyyyyyyyy', 'y....HALLO....y', 'yyyyyyyyyyyyyyy']],
        'pixel' => ['Pixel-Gesicht', ['....yy...yy....', '.......y.......', '....yyyyyyy....']],
    ];

    /** Motive mit deutschem Text auf dem Board: englische Raster (ebenfalls exakt 15 breit) */
    public const ROWS_EN = [
        'night' => ['v.w.vv.w.vvw.v.', '..GOOD.NIGHT...', 'vw.v.wvv.w.v.wv'],
        'frame' => ['yyyyyyyyyyyyyyy', 'y....HELLO....y', 'yyyyyyyyyyyyyyy'],
    ];

    /** @return list<string> Raster des Motivs in der aktuellen Sprache */
    public static function rows(string $id): array
    {
        if (I18n::lang() === 'en' && isset(self::ROWS_EN[$id])) {
            return self::ROWS_EN[$id];
        }
        return self::ALL[$id][1] ?? [];
    }

    /** Motiv als Editor-Text (Designer-Modus), z. B. "{red}{red} LOVE" */
    public static function text(string $id): ?string
    {
        if (!isset(self::ALL[$id])) {
            return null;
        }
        $lines = [];
        foreach (self::rows($id) as $row) {
            $line = '';
            foreach (mb_str_split($row) as $char) {
                $line .= isset(self::TILES[$char]) ? '{' . self::TILES[$char] . '}' : ($char === '.' ? ' ' : $char);
            }
            $lines[] = rtrim($line);
        }
        return implode("\n", $lines);
    }

    public static function label(string $id): string
    {
        return match ($id) {
            'rainbow' => t('Regenbogen'),
            'sunset' => t('Sonnenuntergang'),
            'love' => t('Herz'),
            'hearts' => t('Herzen-Muster'),
            'checker' => t('Schachbrett'),
            'waves' => t('Wellen'),
            'night' => t('Gute Nacht'),
            'party' => t('Party'),
            'tree' => t('Tannenbaum'),
            'flag' => t('Schwarz-Rot-Gold'),
            'smile' => t('Smileys'),
            'stripes' => t('Streifen'),
            'frame' => t('Rahmen „Hallo“'),
            'pixel' => t('Pixel-Gesicht'),
            default => self::ALL[$id][0] ?? $id,
        };
    }
}
