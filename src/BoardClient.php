<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Schnittstelle zum Board (echt: VestaClient, in Tests: Fake).
 * Ergebnis: array{ok: bool, status: int, error: ?string, body: ?array, raw: string, duration_ms: int}
 */
interface BoardClient
{
    public function read(): array;

    public function send(array $grid, bool $forced = false): array;
}
