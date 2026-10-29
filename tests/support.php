<?php
declare(strict_types=1);

// Gemeinsame Hilfen für die Tests: Mini-Assertions, feste Uhr, Fake-Board, In-Memory-DB.

require_once __DIR__ . '/../src/bootstrap.php';

use Vesta\BoardClient;
use Vesta\Clock;
use Vesta\Db;
use Vesta\Time;

$GLOBALS['failures'] = 0;

function check(string $name, bool $condition, string $hint = ''): void
{
    echo ($condition ? '[OK]     ' : '[FEHLER] ') . $name . (!$condition && $hint !== '' ? " ($hint)" : '') . PHP_EOL;
    if (!$condition) {
        $GLOBALS['failures']++;
    }
}

function section(string $title): void
{
    echo PHP_EOL . "── $title" . PHP_EOL;
}

function finish(): never
{
    $failures = $GLOBALS['failures'];
    echo PHP_EOL . ($failures === 0 ? 'Alle Tests bestanden.' : "$failures Test(s) fehlgeschlagen.") . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}

function memoryDb(): PDO
{
    $pdo = Db::connect(['driver' => 'sqlite', 'path' => ':memory:']);
    Db::install($pdo);
    return $pdo;
}

final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    /** @param string $local Ortszeit Europe/Berlin, z. B. "2026-10-26 12:00:00" */
    public function __construct(string $local = '2026-10-26 12:00:00')
    {
        $this->set($local);
    }

    public function set(string $local): void
    {
        $this->now = (new DateTimeImmutable($local, Time::zone()))->setTimezone(Time::utc());
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+$seconds seconds");
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

class FakeBoard implements BoardClient
{
    /** @var list<array{grid: array, forced: bool}> */
    public array $sent = [];
    /** Nächste Antworten (FIFO); leer = Erfolg */
    public array $responses = [];

    public function read(): array
    {
        $last = end($this->sent);
        return ['ok' => true, 'status' => 200, 'error' => null, 'body' => null, 'raw' => '', 'duration_ms' => 1, 'grid' => $last ? $last['grid'] : null];
    }

    public function send(array $grid, bool $forced = false): array
    {
        $response = array_shift($this->responses) ?? ['ok' => true, 'status' => 200, 'error' => null];
        if ($response['ok']) {
            $this->sent[] = ['grid' => $grid, 'forced' => $forced];
        }
        return $response + ['body' => null, 'raw' => '', 'duration_ms' => 1];
    }

    public function fail(int $status, string $error): void
    {
        $this->responses[] = ['ok' => false, 'status' => $status, 'error' => $error];
    }
}
