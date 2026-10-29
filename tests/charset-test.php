<?php
declare(strict_types=1);

// Aufruf: php tests/charset-test.php

require __DIR__ . '/../src/bootstrap.php';

use Vesta\Charset;

$failures = 0;

function check(string $name, bool $condition): void
{
    global $failures;
    echo ($condition ? '[OK]     ' : '[FEHLER] ') . $name . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function row(array $grid, int $index): string
{
    return trim(explode("\n", Charset::gridToText($grid))[$index + 1], '|');
}

$hallo = Charset::textToGrid('Hallo');
check('"Hallo" mittig in Zeile 2', row($hallo['grid'], 1) === '     HALLO     ');
check('"Hallo" Codes H A L L O', array_slice($hallo['grid'][1], 5, 5) === [8, 1, 12, 12, 15]);
check('"Hallo" passt', $hallo['fits'] && $hallo['warnings'] === []);
check('Raster gültig', Charset::isValidGrid($hallo['grid']));

check('Umlaute/ß → GRUESSE', trim(row(Charset::textToGrid('Grüße')['grid'], 1)) === 'GRUESSE');
check('Großbuchstaben-Umlaut Ö → OE', trim(row(Charset::textToGrid('Öl')['grid'], 1)) === 'OEL');

$wrapped = Charset::textToGrid('Guten Morgen liebe Familie');
check('Umbruch an Wortgrenze Zeile 1', trim(row($wrapped['grid'], 0)) === 'GUTEN MORGEN');
check('Umbruch an Wortgrenze Zeile 2', trim(row($wrapped['grid'], 1)) === 'LIEBE FAMILIE');

$newline = Charset::textToGrid("A\nB\nC");
check('Expliziter Zeilenumbruch', trim(row($newline['grid'], 0)) === 'A' && trim(row($newline['grid'], 2)) === 'C');

$long = Charset::textToGrid('Das ist ein viel zu langer Text für ein kleines Vestaboard Note mit drei Zeilen');
check('Zu langer Text: fits = false', $long['fits'] === false);
check('Zu langer Text: Warnung', count($long['warnings']) === 1);
check('Zu langer Text: Raster bleibt 3×15', Charset::isValidGrid($long['grid']));

$split = Charset::textToGrid('Donaudampfschifffahrt');
check('Überlanges Wort wird hart getrennt', trim(row($split['grid'], 0)) === 'DONAUDAMPFSCHIF' || trim(row($split['grid'], 1)) === 'DONAUDAMPFSCHIF');

$tokens = Charset::textToGrid('{red}{Green}');
check('Farb-Platzhalter (auch Großschreibung)', array_slice($tokens['grid'][1], 6, 2) === [63, 66]);

check('Herz ♥ → 62', in_array(62, Charset::textToGrid('♥')['grid'][1], true));
check('Herz ❤️ (mit Variation Selector) → 62', in_array(62, Charset::textToGrid('❤️')['grid'][1], true));
check('Herz {heart} → 62', in_array(62, Charset::textToGrid('{heart}')['grid'][1], true));

$unknown = Charset::textToGrid('Hi 漢');
check('Unbekanntes Zeichen → Warnung', count($unknown['warnings']) === 1 && str_contains($unknown['warnings'][0], '漢'));

check('Ziffern 1 und 0', array_slice(Charset::textToGrid('10')['grid'][1], 6, 2) === [27, 36]);
check('Satzzeichen „Hallo!“ → "HALLO!"', trim(row(Charset::textToGrid('„Hallo!“')['grid'], 1)) === '"HALLO!"');
check('Leerer Text → leeres Raster', Charset::textToGrid('')['grid'] === Charset::emptyGrid());

check('Akzent é → E', trim(row(Charset::textToGrid('Café')['grid'], 1)) === 'CAFE');
check('Akzent Großbuchstabe É → E', trim(row(Charset::textToGrid('École')['grid'], 1)) === 'ECOLE');
if (class_exists(Normalizer::class)) {
    check('Seltener Akzent ŝ → S (intl)', trim(row(Charset::textToGrid('ŝ')['grid'], 1)) === 'S');
}

check('Links ausgerichtet', row(Charset::textToGrid('Hi', 'left')['grid'], 1) === 'HI             ');

$designer = Charset::textToGrid("{red}\n  HI\n              {blue}", 'grid');
check('Designer: Position bleibt exakt', $designer['grid'][0][0] === 63 && row($designer['grid'], 1) === '  HI           ' && $designer['grid'][2][14] === 67);
check('Designer: Rückwandlung verlustfrei', Charset::textToGrid(Charset::gridToRawText($designer['grid'], true), 'grid')['grid'] === $designer['grid']);
check('Designer: Zeile zu lang', !Charset::textToGrid(str_repeat('A', 16), 'grid')['fits']);
check('Designer: 4 Zeilen zu viel', !Charset::textToGrid("A\nB\nC\nD", 'grid')['fits']);
check('Unbekannte Ausrichtung → mittig', Charset::align('kaputt') === 'center');

check('Ungültig: 2 Zeilen', !Charset::isValidGrid([array_fill(0, 15, 0), array_fill(0, 15, 0)]));
check('Ungültig: Code 43', !Charset::isValidGrid([array_fill(0, 15, 0), array_fill(0, 15, 43), array_fill(0, 15, 0)]));
check('Ungültig: String statt Int', !Charset::isValidGrid([array_fill(0, 15, '1'), array_fill(0, 15, 0), array_fill(0, 15, 0)]));

check('Layout-String der API wird gelesen', Charset::layoutToGrid(json_encode($hallo['grid'])) === $hallo['grid']);

echo PHP_EOL . '── Kürzungsroutine' . PHP_EOL;
check('Länge: Umlaute doppelt, Herz einfach', Charset::length('MÜLLER {heart}') === 9);
check('cut an Wortgrenze', Charset::cut('ANNA MARIA MUELLER', 10) === 'ANNA MARIA');
check('cut: einzelnes langes Wort hart', Charset::cut('DONAUDAMPFSCHIFFFAHRT', 6) === 'DONAUD');
check('cut: Umlaut zählt doppelt', Charset::cut('ÄÄÄÄ', 5) === 'ÄÄ');
check('fit: passender Text unverändert', Charset::fit("HALLO\nWELT") === "HALLO\nWELT");
$fitted = Charset::fit('DAS IST EIN SEHR LANGER SATZ DER NIEMALS AUF DAS KLEINE BOARD PASSEN WIRD');
check('fit: langer Satz passt danach', Charset::textToGrid($fitted)['fits'], $fitted);
check('fit: nutzt alle 3 Zeilen', count(explode("\n", $fitted)) === 3, $fitted);
$fitted = Charset::fit("NEUE BUCHUNG\nANNA-MARIA MUELLER-LUEDENSCHEIDT\n12.11. 14:00 UHR\nZUSATZZEILE");
check('fit: Absätze bleiben, vierter fällt weg', Charset::textToGrid($fitted)['fits'] && str_starts_with($fitted, "NEUE BUCHUNG\n") && !str_contains($fitted, 'ZUSATZ'), $fitted);
$fitted = Charset::fit("KURZ\nEIN ETWAS LAENGERER ZWEITER ABSATZ HIER");
check('fit: freie Zeile geht an den Absatz, der sie braucht', Charset::textToGrid($fitted)['fits'] && count(explode("\n", $fitted)) === 3, $fitted);

$started = microtime(true);
$huge = Charset::fit(str_repeat('A', 900000) . "
" . str_repeat('😀', 50000));
check('fit: riesige Eingabe schnell und passend (Schutz vor DoS)', microtime(true) - $started < 0.5 && Charset::textToGrid($huge)['fits']);
$started = microtime(true);
Charset::cut(str_repeat('B', 500000), 15);
check('cut: riesiges Wort schnell', microtime(true) - $started < 0.5);

echo PHP_EOL . ($failures === 0 ? 'Alle Tests bestanden.' : "$failures Test(s) fehlgeschlagen.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
