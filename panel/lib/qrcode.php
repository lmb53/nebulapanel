<?php
/**
 * Minimal QR Code encoder (byte mode, error-correction level M, versions
 * 1–20) producing an SVG. Used only to show the authenticator-app setup
 * code, so it favours clarity over completeness. The algorithm follows the
 * ISO/IEC 18004 model as laid out in Project Nayuki's reference encoder.
 */

final class NebulaQr
{
    private const MAX_VERSION = 20;
    // Index 0 is unused; level M.
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];
    private const NUM_BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16];
    private const FORMAT_BITS_M = 0;

    private int $size;
    private array $modules = [];
    private array $isFunction = [];

    /** Encode $text and return SVG markup. Throws if the text is too long. */
    public static function svg(string $text, int $border = 4, string $title = 'QR code'): string
    {
        $qr = new self($text);
        return $qr->toSvg($border, $title);
    }

    /** The module matrix (true = dark), for tests. */
    public static function matrix(string $text): array
    {
        return (new self($text))->modules;
    }

    private function __construct(string $text)
    {
        $data = array_values(unpack('C*', $text) ?: []);
        $version = 0;
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $countBits = $v <= 9 ? 8 : 16;
            if (4 + $countBits + 8 * count($data) <= self::dataCodewords($v) * 8) { $version = $v; break; }
        }
        if ($version === 0) { throw new InvalidArgumentException('Text is too long for a QR code.'); }

        // Bit stream: byte mode indicator, length, payload, terminator, padding.
        $bits = [];
        $push = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) { $bits[] = ($value >> $i) & 1; }
        };
        $push(0x4, 4);
        $push(count($data), $version <= 9 ? 8 : 16);
        foreach ($data as $byte) { $push($byte, 8); }
        $capacity = self::dataCodewords($version) * 8;
        $push(0, min(4, $capacity - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) { $push($pad, 8); }
        $codewords = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $byte = 0;
            foreach ($chunk as $bit) { $byte = ($byte << 1) | $bit; }
            $codewords[] = $byte;
        }

        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;
        $this->drawFunctionPatterns($version);
        $this->drawCodewords($this->addEccAndInterleave($codewords, $version));

        // Choose the mask with the lowest penalty.
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) { $best = $mask; $bestPenalty = $penalty; }
            $this->applyMask($mask); // XOR again to undo
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    private static function rawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) { $result -= 36; }
        }
        return $result;
    }

    private static function dataCodewords(int $ver): int
    {
        return intdiv(self::rawDataModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::NUM_BLOCKS[$ver];
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(int $ver): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx; $y = $cy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) { continue; }
                    $dist = max(abs($dx), abs($dy));
                    $this->setFunction($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
        $positions = $this->alignmentPositions($ver);
        $n = count($positions);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) { continue; }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunction($positions[$i] + $dx, $positions[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0); // reserve; redrawn after masking
        if ($ver >= 7) {
            $rem = $ver;
            for ($i = 0; $i < 12; $i++) { $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25); }
            $bits = ($ver << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->setFunction($a, $b, $bit);
                $this->setFunction($b, $a, $bit);
            }
        }
    }

    private function alignmentPositions(int $ver): array
    {
        if ($ver === 1) { return []; }
        $numAlign = intdiv($ver, 7) + 2;
        $step = intdiv($ver * 8 + $numAlign * 3 + 5, $numAlign * 4 - 4) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $numAlign; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::FORMAT_BITS_M << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) { $rem = ($rem << 1) ^ (($rem >> 9) * 0x537); }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) { $this->setFunction(8, $i, $bit($i)); }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) { $this->setFunction(14 - $i, 8, $bit($i)); }
        for ($i = 0; $i < 8; $i++) { $this->setFunction($this->size - 1 - $i, 8, $bit($i)); }
        for ($i = 8; $i < 15; $i++) { $this->setFunction(8, $this->size - 15 + $i, $bit($i)); }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function addEccAndInterleave(array $data, int $ver): array
    {
        $numBlocks = self::NUM_BLOCKS[$ver];
        $eccLen = self::ECC_PER_BLOCK[$ver];
        $raw = intdiv(self::rawDataModules($ver), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) { $dat[] = 0; }
            $blocks[] = array_merge($dat, $ecc);
        }
        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) { $result[] = $block[$i]; }
            }
        }
        return $result;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) { $result[$j] ^= $result[$j + 1]; }
            }
            $root = self::gfMultiply($root, 0x02);
        }
        return $result;
    }

    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coefficient) { $result[$i] ^= self::gfMultiply($coefficient, $factor); }
        }
        return $result;
    }

    private function drawCodewords(array $data): void
    {
        $total = count($data) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) { $right = 5; }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
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
                if ($this->isFunction[$y][$x]) { continue; }
                switch ($mask) {
                    case 0: $invert = ($x + $y) % 2 === 0; break;
                    case 1: $invert = $y % 2 === 0; break;
                    case 2: $invert = $x % 3 === 0; break;
                    case 3: $invert = ($x + $y) % 3 === 0; break;
                    case 4: $invert = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $invert = $x * $y % 2 + $x * $y % 3 === 0; break;
                    case 6: $invert = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                    default: $invert = (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
                }
                if ($invert) { $this->modules[$y][$x] = !$this->modules[$y][$x]; }
            }
        }
    }

    /** Simplified penalty (runs, 2×2 blocks, finder-like patterns, balance). */
    private function penalty(): int
    {
        $n = $this->size;
        $score = 0;
        $dark = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $run = 0; $prev = null; $line = '';
                for ($b = 0; $b < $n; $b++) {
                    $m = $pass === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];
                    $line .= $m ? '1' : '0';
                    if ($m === $prev) { $run++; } else { if ($run >= 5) { $score += 3 + $run - 5; } $run = 1; $prev = $m; }
                }
                if ($run >= 5) { $score += 3 + $run - 5; }
                $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
            }
        }
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($this->modules[$y][$x]) { $dark++; }
                if ($x < $n - 1 && $y < $n - 1) {
                    $c = $this->modules[$y][$x];
                    if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) { $score += 3; }
                }
            }
        }
        $total = $n * $n;
        $score += intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) * 10 - 10;
        return $score;
    }

    private function toSvg(int $border, string $title): string
    {
        $dim = $this->size + $border * 2;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) { $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z'; }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($title, ENT_QUOTES) . '">'
            . '<rect width="100%" height="100%" fill="#ffffff"/><path d="' . $path . '" fill="#000000"/></svg>';
    }
}
