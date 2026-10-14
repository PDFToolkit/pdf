<?php

declare(strict_types=1);

namespace PDF\Security;

/** FIPS 202 SHAKE256 with the RFC 8419 id-shake256 fixed 512-bit output. */
final readonly class Shake256
{
    public function digest(string $message): string
    {
        $state = array_fill(0, 50, 0);
        $remaining = 136 - strlen($message) % 136;
        $message .= $remaining === 1 ? "\x9f" : "\x1f" . str_repeat("\0", $remaining - 2) . "\x80";
        for ($position = 0; $position < strlen($message); $position += 136) {
            $words = array_values(unpack('V*', substr($message, $position, 136)) ?: []);
            foreach ($words as $index => $word) {
                $state[$index] ^= $word;
            }
            $state = $this->permute(array_values($state));
        }
        return pack('V*', ...array_slice($state, 0, 16));
    }

    /**
     * @param list<int> $state
     * @return list<int>
     */
    private function permute(array $state): array
    {
        $rotations = [0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14];
        $constants = ['0000000000000001', '0000000000008082', '800000000000808a', '8000000080008000', '000000000000808b', '0000000080000001', '8000000080008081', '8000000000008009', '000000000000008a', '0000000000000088', '0000000080008009', '000000008000000a', '000000008000808b', '800000000000008b', '8000000000008089', '8000000000008003', '8000000000008002', '8000000000000080', '000000000000800a', '800000008000000a', '8000000080008081', '8000000000008080', '0000000080000001', '8000000080008008'];
        foreach ($constants as $constant) {
            $columns = array_fill(0, 10, 0);
            for ($x = 0; $x < 5; ++$x) {
                for ($y = 0; $y < 5; ++$y) {
                    $columns[2 * $x] ^= $state[2 * ($x + 5 * $y)];
                    $columns[2 * $x + 1] ^= $state[2 * ($x + 5 * $y) + 1];
                }
            }
            for ($x = 0; $x < 5; ++$x) {
                [$lo, $hi] = $this->rotate($columns[2 * (($x + 1) % 5)], $columns[2 * (($x + 1) % 5) + 1], 1);
                $lo ^= $columns[2 * (($x + 4) % 5)];
                $hi ^= $columns[2 * (($x + 4) % 5) + 1];
                for ($y = 0; $y < 5; ++$y) {
                    $state[2 * ($x + 5 * $y)] ^= $lo;
                    $state[2 * ($x + 5 * $y) + 1] ^= $hi;
                }
            }
            $b = array_fill(0, 50, 0);
            for ($x = 0; $x < 5; ++$x) {
                for ($y = 0; $y < 5; ++$y) {
                    $index = $x + 5 * $y;
                    [$lo, $hi] = $this->rotate($state[2 * $index], $state[2 * $index + 1], $rotations[$index]);
                    $target = $y + 5 * ((2 * $x + 3 * $y) % 5);
                    $b[2 * $target] = $lo;
                    $b[2 * $target + 1] = $hi;
                }
            }
            for ($x = 0; $x < 5; ++$x) {
                for ($y = 0; $y < 5; ++$y) {
                    for ($half = 0; $half < 2; ++$half) {
                        $state[2 * ($x + 5 * $y) + $half] = $b[2 * ($x + 5 * $y) + $half] ^ ((~$b[2 * (($x + 1) % 5 + 5 * $y) + $half]) & $b[2 * (($x + 2) % 5 + 5 * $y) + $half]);
                    }
                }
            }
            $state[0] ^= (int) hexdec(substr($constant, 8));
            $state[1] ^= (int) hexdec(substr($constant, 0, 8));
        }
        return array_values($state);
    }

    /** @return array{int,int} */
    private function rotate(int $lo, int $hi, int $bits): array
    {
        if ($bits >= 32) {
            [$lo, $hi] = [$hi, $lo];
            $bits -= 32;
        }
        return $bits === 0 ? [$lo, $hi] : [((($lo << $bits) & 0xffffffff) | ($hi >> (32 - $bits))), ((($hi << $bits) & 0xffffffff) | ($lo >> (32 - $bits)))];
    }
}
