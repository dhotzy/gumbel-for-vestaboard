<?php
declare(strict_types=1);

namespace Vesta;

use DateTimeImmutable;

/** Zeitquelle – in Tests durch FixedClock ersetzbar. Liefert immer UTC. */
interface Clock
{
    public function now(): DateTimeImmutable;
}
