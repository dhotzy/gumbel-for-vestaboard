<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Dateisperre, damit Cron und Web-Request nie gleichzeitig senden.
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public function acquire(float $timeoutSeconds = 5.0): bool
    {
        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            return false;
        }
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        fclose($handle);
        return false;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
