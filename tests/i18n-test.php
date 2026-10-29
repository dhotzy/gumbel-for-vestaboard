<?php
declare(strict_types=1);

// Aufruf: php tests/i18n-test.php
// Findet jeden t('…')-Text ohne englische Übersetzung und prüft, dass Platzhalter übereinstimmen.

require __DIR__ . '/support.php';

use Vesta\I18n;

/** @return list<array{0: string, 1: string}> [Text, Fundstelle] */
function translatableStrings(): array
{
    $files = array_merge(glob(__DIR__ . '/../public/*.php') ?: [], glob(__DIR__ . '/../public/api/*.php') ?: [], glob(__DIR__ . '/../src/*.php') ?: []);
    $found = [];
    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        // t('…') mit einfachen Anführungszeichen
        preg_match_all("/(?<![\\w>:\$])t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $single);
        foreach ($single[1] as $raw) {
            $found[] = [str_replace(["\\'", '\\\\'], ["'", '\\'], $raw), basename($file)];
        }
        // tc('Kontext', '…') → Schlüssel 'Kontext|…'
        preg_match_all("/(?<![\\w>:\$])tc\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $context);
        foreach ($context[1] as $i => $ctx) {
            $found[] = [$ctx . '|' . str_replace(["\\'", '\\\\'], ["'", '\\'], $context[2][$i]), basename($file)];
        }
        // t("…") mit doppelten Anführungszeichen (nur \n als Escape)
        preg_match_all('/(?<![\w>:$])t\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $code, $double);
        foreach ($double[1] as $raw) {
            $found[] = [str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $raw), basename($file)];
        }
    }
    return $found;
}

section('Übersetzungen');
$strings = translatableStrings();
$dictionary = I18n::dictionary('en');
check('Es gibt übersetzbare Texte', count($strings) > 0, (string) count($strings));
$missing = [];
$placeholderMismatch = [];
foreach ($strings as [$text, $file]) {
    if (!array_key_exists($text, $dictionary)) {
        $missing[$text] = $file;
        continue;
    }
    preg_match_all('/\{(\w+)\}/', $text, $a);
    preg_match_all('/\{(\w+)\}/', $dictionary[$text], $b);
    $placeholders = static fn (array $m): array => array_values(array_unique(array_diff($m[1], ['heart', 'red', 'orange', 'yellow', 'green', 'blue', 'violet', 'white', 'black', 'filled'])));
    if (array_diff($placeholders($a), $placeholders($b)) !== [] || array_diff($placeholders($b), $placeholders($a)) !== []) {
        $placeholderMismatch[$text] = $file;
    }
}
foreach (array_slice($missing, 0, 25, true) as $text => $file) {
    echo "         fehlt ($file): " . str_replace("\n", '\n', $text) . PHP_EOL;
}
check('Alle Texte übersetzt (' . count(array_unique(array_column($strings, 0))) . ')', $missing === [], count($missing) . ' fehlen');
foreach ($placeholderMismatch as $text => $file) {
    echo "         Platzhalter ($file): $text" . PHP_EOL;
}
check('Platzhalter stimmen überein', $placeholderMismatch === []);

section('Umschalten');
I18n::set('en');
check('Englisch', I18n::lang() === 'en');
check('Unbekannter Text bleibt stehen', I18n::t('Gibt es nicht') === 'Gibt es nicht');
check('Platzhalter werden ersetzt', I18n::t('X {a} Y', ['a' => 1]) === 'X 1 Y');
check('Datumsformat', I18n::dateFormat('d.m. H:i') === 'M j, H:i');
I18n::set('fr');
check('Unbekannte Sprache → Deutsch', I18n::lang() === 'de');
I18n::set('de');

finish();
