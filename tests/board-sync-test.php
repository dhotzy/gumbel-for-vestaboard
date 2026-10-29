<?php
declare(strict_types=1);

// Aufruf: php tests/board-sync-test.php

require __DIR__ . '/support.php';

use Vesta\Arbiter;
use Vesta\BoardSync;
use Vesta\Charset;
use Vesta\MessageRepo;
use Vesta\Settings;
use Vesta\Sources;
use Vesta\Time;

/** Board, dessen Anzeige der Test von außen verändern kann (wie die Vestaboard-App) */
final class ExternalBoard extends FakeBoard
{
    public ?array $shown = null;
    public bool $readFails = false;

    public function read(): array
    {
        if ($this->readFails) {
            return ['ok' => false, 'status' => 500, 'error' => 'Server-Fehler', 'body' => null, 'raw' => '', 'duration_ms' => 1, 'grid' => null];
        }
        return ['ok' => true, 'status' => 200, 'error' => null, 'body' => null, 'raw' => '', 'duration_ms' => 1, 'grid' => $this->shown];
    }

    public function send(array $grid, bool $forced = false): array
    {
        $result = parent::send($grid, $forced);
        if ($result['ok']) {
            $this->shown = $grid;
        }
        return $result;
    }
}

function syncEnv(): array
{
    $pdo = memoryDb();
    $clock = new FixedClock('2026-10-26 12:00:00');
    $board = new ExternalBoard();
    $messages = new MessageRepo($pdo);
    $settings = new Settings($pdo);
    return [$messages, $settings, $board, $clock, new Arbiter($messages, $settings, $board, $clock), new BoardSync($messages, $settings, $board, $clock)];
}

section('Abgleich');
[$messages, $settings, $board, $clock, $arbiter, $sync] = syncEnv();
$board->shown = Charset::textToGrid('VON DER APP')['grid'];
$result = $sync->sync();
check('Erster Abgleich ohne bekannten Stand → extern erkannt', $result['changed']);
$current = $messages->current();
check('Externe Anzeige ist jetzt "läuft"', $current['source'] === Sources::EXTERNAL);
check('Text aus dem Raster rekonstruiert', $current['text_raw'] === 'VON DER APP');
check('Live-Stand mit Zeitpunkt gespeichert', $settings->get('board_live')['read_at'] === Time::db($clock->now()));
$clock->advance(60);
check('Gleicher Stand → keine neue Archiv-Zeile', !$sync->sync()['changed']);

section('Zusammenspiel mit dem Arbiter');
$id = $messages->insert(['source' => 'kids', 'text_raw' => 'HALLO', 'grid' => json_encode(Charset::textToGrid('HALLO')['grid']), 'status' => 'queued', 'created_at' => Time::db($clock->now())]);
check('Externe Anzeige blockiert die Warteschlange nicht', $arbiter->run()['message_id'] === $id);
check('Externe Anzeige im Archiv beendet', $messages->find((int) $current['id'])['status'] === 'shown');
$clock->advance(10);
check('Direkt nach dem Senden kein Abgleich (Cloud braucht Zeit)', !$sync->sync()['changed'] && $messages->current()['id'] == $id);
$clock->advance(60);
check('Eigene Nachricht wird nicht als extern erkannt', !$sync->sync()['changed']);
$board->shown = Charset::textToGrid('HANDY')['grid'];
$clock->advance(60);
check('Änderung per Vestaboard-App wird erkannt', $sync->sync()['changed']);
check('Unsere Nachricht endet zum Zeitpunkt der Änderung', $messages->find($id)['status'] === 'shown' && $messages->find($id)['ended_at'] === Time::db($clock->now()));

section('Fehler');
[$messages, $settings, $board, $clock, $arbiter, $sync] = syncEnv();
$board->readFails = true;
$result = $sync->sync();
check('Lesefehler → ok = false, nichts archiviert', !$result['ok'] && $messages->current() === null);
$board->readFails = false;
$board->shown = [[1, 2, 3]];
check('Falsches Format → kein Archiv-Eintrag', !$sync->sync()['ok'] && $messages->current() === null);
$board->shown = Charset::emptyGrid();
$board->shown[0][0] = 43; // Code, den unser Editor nicht kennt
check('Unbekannter Code auf dem Board wird trotzdem archiviert', $sync->sync()['changed']);

section('Raster → Text');
check('Farben und Herz als Platzhalter', Charset::gridToRawText(Charset::textToGrid("{red} HI {heart}\nZEILE 2")['grid']) === "{red} HI {heart}\nZEILE 2");
check('Leeres Raster → leerer Text', Charset::gridToRawText(Charset::emptyGrid()) === '');

finish();
