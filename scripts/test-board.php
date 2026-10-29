<?php
declare(strict_types=1);

/**
 * Phase-0-Test für die Konsole.
 *
 *   php scripts/test-board.php                       Umgebung prüfen + aktuelle Anzeige lesen
 *   php scripts/test-board.php preview "Text"        nur Vorschau, ohne API (kein Token nötig)
 *   php scripts/test-board.php send "Text" [--force] senden und zurücklesen
 */

require __DIR__ . '/../src/bootstrap.php';

use Vesta\Charset;
use Vesta\Diagnostics;
use Vesta\VestaClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args = array_slice($argv, 1);
$forced = in_array('--force', $args, true);
$args = array_values(array_diff($args, ['--force']));
$command = $args[0] ?? 'read';
$text = $args[1] ?? "HALLO {heart}\nTEST OK {green}";

function line(bool $ok, string $label, string $detail = ''): void
{
    echo ($ok ? '[OK]     ' : '[FEHLER] ') . $label . ($detail !== '' ? " – $detail" : '') . PHP_EOL;
}

function preview(string $text): array
{
    $result = Charset::textToGrid($text);
    echo PHP_EOL . 'Vorschau für: "' . $text . '"' . PHP_EOL;
    echo Charset::gridToText($result['grid']) . PHP_EOL;
    foreach ($result['warnings'] as $warning) {
        echo '[HINWEIS] ' . $warning . PHP_EOL;
    }
    return $result;
}

function readBoard(VestaClient $client): bool
{
    $read = $client->read();
    line($read['ok'], 'Aktuelle Anzeige lesen', $read['ok'] ? "HTTP {$read['status']}, {$read['duration_ms']} ms" : (string) $read['error']);
    if ($read['ok']) {
        echo Charset::gridToText($read['grid']) . PHP_EOL;
        $rows = count($read['grid']);
        $cols = count($read['grid'][0] ?? []);
        line($rows === Charset::ROWS && $cols === Charset::COLS, 'Format Vestaboard Note', "{$rows}×{$cols}");
    } elseif ($read['raw'] !== '') {
        echo 'Antwort: ' . mb_substr($read['raw'], 0, 300) . PHP_EOL;
    }
    return $read['ok'];
}

if ($command === 'preview') {
    preview($text);
    exit(0);
}

echo 'Umgebung' . PHP_EOL;
$envOk = true;
foreach (Diagnostics::environment() as $check) {
    line($check['ok'], $check['label'], $check['detail']);
    $envOk = $envOk && $check['ok'];
}
if (!$envOk) {
    echo PHP_EOL . 'Bitte zuerst die Fehler oben beheben.' . PHP_EOL;
    exit(1);
}

$client = VestaClient::fromConfig();
echo PHP_EOL;

switch ($command) {
    case 'read':
        exit(readBoard($client) ? 0 : 1);

    case 'send':
        $result = preview($text);
        if (!$result['fits']) {
            echo 'Abbruch: Text passt nicht auf das Board.' . PHP_EOL;
            exit(1);
        }
        echo PHP_EOL . 'Sende' . ($forced ? ' (forced)' : '') . ' …' . PHP_EOL;
        $outcome = Diagnostics::sendAndVerify($client, $result['grid'], $forced);
        $send = $outcome['send'];
        line($send['ok'], 'Senden', $send['ok'] ? "HTTP {$send['status']}, {$send['duration_ms']} ms" : (string) $send['error']);
        if (!$send['ok']) {
            if ($send['raw'] !== '') {
                echo 'Antwort: ' . mb_substr($send['raw'], 0, 300) . PHP_EOL;
            }
            exit(1);
        }
        $read = $outcome['read'];
        line($outcome['matches'], 'Zurücklesen und vergleichen', $outcome['matches']
            ? 'Board zeigt die Testnachricht'
            : ($read['ok'] ? 'Board zeigt etwas anderes (Verzögerung? Board offline?)' : (string) $read['error']));
        if ($read['ok'] && !$outcome['matches']) {
            echo Charset::gridToText($read['grid']) . PHP_EOL;
        }
        echo PHP_EOL . 'Hinweis: vor dem nächsten Senden mindestens 15 Sekunden warten.' . PHP_EOL;
        exit($outcome['matches'] ? 0 : 1);

    default:
        echo 'Unbekannter Befehl. Möglich: read | preview "Text" | send "Text" [--force]' . PHP_EOL;
        exit(2);
}
