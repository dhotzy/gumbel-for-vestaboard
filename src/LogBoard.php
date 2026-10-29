<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Board-Ersatz für lokale Entwicklung ('board_driver' => 'log'): schreibt in eine Datei statt ans echte Board.
 */
final class LogBoard implements BoardClient
{
    public function __construct(private readonly string $file)
    {
    }

    public function read(): array
    {
        $lines = is_file($this->file) ? file($this->file, FILE_IGNORE_NEW_LINES) : [];
        $last = $lines === [] ? null : json_decode((string) end($lines), true);
        return ['ok' => true, 'status' => 200, 'error' => null, 'body' => null, 'raw' => '', 'duration_ms' => 0,
            'grid' => $last['grid'] ?? Charset::emptyGrid(), 'message_id' => null];
    }

    public function send(array $grid, bool $forced = false): array
    {
        if (!Charset::isValidGrid($grid)) {
            return ['ok' => false, 'status' => 0, 'error' => 'Ungültiges Raster.', 'body' => null, 'raw' => '', 'duration_ms' => 0];
        }
        file_put_contents($this->file, json_encode(['at' => date('c'), 'forced' => $forced, 'grid' => $grid]) . "\n", FILE_APPEND);
        return ['ok' => true, 'status' => 200, 'error' => null, 'body' => null, 'raw' => '', 'duration_ms' => 0];
    }
}
