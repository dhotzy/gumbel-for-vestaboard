<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Kleiner QR-Code-Erzeuger ohne Abhängigkeiten (Byte-Modus, Fehlerkorrektur M, Version 1–10, bis 213 Bytes).
 * Reicht für Links wie https://example.com/post.php?t=… – Ausgabe als SVG.
 * Aufbau nach ISO/IEC 18004, angelehnt an Project Nayuki's „QR Code generator library“ (MIT).
 */
final class QrCode
{
    private const TOTAL_CODEWORDS = [26, 44, 70, 100, 134, 172, 196, 242, 292, 346];
    private const ECC_PER_BLOCK = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    private const BLOCKS = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    private const ALIGNMENT = [[], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];

    /** @var list<list<bool>> */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $isFunction = [];
    private int $size;

    private function __construct(private readonly int $version)
    {
        $this->size = 17 + 4 * $version;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;
    }

    public static function encode(string $text): self
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        for ($version = 1; $version <= 10; $version++) {
            $dataCodewords = self::TOTAL_CODEWORDS[$version - 1] - self::ECC_PER_BLOCK[$version - 1] * self::BLOCKS[$version - 1];
            $countBits = $version < 10 ? 8 : 16;
            if (4 + $countBits + 8 * count($bytes) <= $dataCodewords * 8) {
                break;
            }
        }
        if ($version > 10) {
            throw new \InvalidArgumentException('Text zu lang für einen QR-Code (max. 213 Bytes).');
        }

        // Bits: Modus 0100 (Byte), Länge, Daten, Abschluss, Füllbytes
        $bits = [];
        $append = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $append(4, 4);
        $append(count($bytes), $countBits);
        foreach ($bytes as $byte) {
            $append($byte, 8);
        }
        $capacity = $dataCodewords * 8;
        $append(0, min(4, $capacity - count($bits)));
        $append(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $append($pad, 8);
        }
        $data = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $data[] = (int) bindec(implode('', $chunk));
        }

        $qr = new self($version);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEccAndInterleave($data));
        // Maske mit der geringsten Strafe wählen
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $qr->applyMask($mask);
            $qr->drawFormatBits($mask);
            $penalty = $qr->penalty();
            if ($penalty < $bestPenalty) {
                [$best, $bestPenalty] = [$mask, $penalty];
            }
            $qr->applyMask($mask); // XOR – wieder rückgängig
        }
        $qr->applyMask($best);
        $qr->drawFormatBits($best);
        return $qr;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isDark(int $x, int $y): bool
    {
        return $this->modules[$y][$x];
    }

    /** SVG mit Ruhezone (4 Module), schwarz auf weiß – druck- und scanfest */
    public function toSvg(int $pixelSize = 8, int $border = 4): string
    {
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
                }
            }
        }
        $dimension = $this->size + 2 * $border;
        $pixels = $dimension * $pixelSize;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dimension . ' ' . $dimension . '" width="' . $pixels . '" height="' . $pixels . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) { // Taktlinien
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) { // Suchmuster
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $distance = max(abs($dx), abs($dy));
                        $this->set($x, $y, $distance !== 2 && $distance !== 4);
                    }
                }
            }
        }
        $positions = self::ALIGNMENT[$this->version - 1];
        $count = count($positions);
        foreach ($positions as $i => $px) { // Ausrichtungsmuster (nicht auf den Suchmustern)
            foreach ($positions as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0); // Platz reservieren, echte Werte nach der Maskenwahl
        if ($this->version >= 7) {
            $remainder = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $remainder;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // Fehlerkorrektur M = 00
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }
        $this->set(8, $this->size - 8, true); // dunkles Modul
    }

    /** @param list<int> $data */
    private function addEccAndInterleave(array $data): array
    {
        $numBlocks = self::BLOCKS[$this->version - 1];
        $eccLength = self::ECC_PER_BLOCK[$this->version - 1];
        $raw = self::TOTAL_CODEWORDS[$this->version - 1];
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLength = intdiv($raw, $numBlocks);
        $divisor = self::divisor($eccLength);
        $blocks = [];
        $offset = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $length = $shortLength - $eccLength + ($i < $numShort ? 0 : 1);
            $block = array_slice($data, $offset, $length);
            $offset += $length;
            $ecc = self::remainder($block, $divisor);
            if ($i < $numShort) {
                $block[] = 0; // Platzhalter, wird beim Verschränken übersprungen
            }
            $blocks[] = array_merge($block, $ecc);
        }
        $result = [];
        for ($i = 0, $n = count($blocks[0]); $i < $n; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLength - $eccLength || $j >= $numShort) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    /** @param list<int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $i = 0;
        $total = count($codewords) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert && !$this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** Vereinfachte Strafpunkte (Läufe, 2×2-Blöcke, Suchmuster-Ähnlichkeit, Hell/Dunkel-Verhältnis) */
    private function penalty(): int
    {
        $penalty = 0;
        $dark = 0;
        for ($a = 0; $a < $this->size; $a++) {
            foreach ([true, false] as $horizontal) {
                $run = 0;
                $last = null;
                $line = '';
                for ($b = 0; $b < $this->size; $b++) {
                    $value = $horizontal ? $this->modules[$a][$b] : $this->modules[$b][$a];
                    $line .= $value ? '1' : '0';
                    if ($value === $last) {
                        $run++;
                        if ($run === 5) {
                            $penalty += 3;
                        } elseif ($run > 5) {
                            $penalty++;
                        }
                    } else {
                        [$run, $last] = [1, $value];
                    }
                }
                $penalty += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
            }
        }
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $dark += $this->modules[$y][$x] ? 1 : 0;
                if ($x < $this->size - 1 && $y < $this->size - 1) {
                    $value = $this->modules[$y][$x];
                    if ($value === $this->modules[$y][$x + 1] && $value === $this->modules[$y + 1][$x] && $value === $this->modules[$y + 1][$x + 1]) {
                        $penalty += 3;
                    }
                }
            }
        }
        $total = $this->size * $this->size;
        return $penalty + intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) * 10;
    }

    /** @return list<int> */
    private static function divisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::multiply($root, 0x02);
        }
        return $result;
    }

    /** @param list<int> $data @param list<int> $divisor @return list<int> */
    private static function remainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::multiply($coefficient, $factor);
            }
        }
        return $result;
    }

    private static function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }
}
