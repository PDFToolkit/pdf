<?php

declare(strict_types=1);

namespace PDF\Security;

/** Strict definite-length DER framing, without interpreting certificates, policies or PKI trust. */
final readonly class SignatureDerReader
{
    public function element(string $bytes): SignatureDerElement
    {
        $elements = $this->elements($bytes);
        if (count($elements) !== 1) {
            throw new \InvalidArgumentException('A signature envelope contains exactly one unpadded DER object.');
        }
        return $elements[0];
    }

    /** @return list<SignatureDerElement> */
    public function elements(string $bytes): array
    {
        $elements = [];
        $position = 0;
        $size = strlen($bytes);
        while ($position < $size) {
            $start = $position;
            if ($size - $position < 2) {
                throw new \InvalidArgumentException('Truncated signature DER header.');
            }
            $tag = ord($bytes[$position++]);
            if ($tag === 0 || ($tag & 31) === 31) {
                throw new \InvalidArgumentException('The native signature inspector supports only its known single-byte DER tags.');
            }
            $length = ord($bytes[$position++]);
            if (($length & 128) !== 0) {
                $count = $length & 127;
                if ($count === 0 || $count > PHP_INT_SIZE || $count > $size - $position || $bytes[$position] === "\0") {
                    throw new \InvalidArgumentException('Signature DER lengths are definite and minimally encoded.');
                }
                $length = 0;
                for ($index = 0; $index < $count; ++$index) {
                    $byte = ord($bytes[$position++]);
                    if ($length > intdiv(PHP_INT_MAX - $byte, 256)) {
                        throw new \OverflowException('Signature DER length exceeds native bounds.');
                    }
                    $length = $length * 256 + $byte;
                }
                if ($length < 128) {
                    throw new \InvalidArgumentException('Long DER length encoding is unnecessary below128 bytes.');
                }
            }
            if ($length > $size - $position) {
                throw new \InvalidArgumentException('Signature DER content exceeds the supplied bytes.');
            }
            $value = substr($bytes, $position, $length);
            $position += $length;
            $elements[] = new SignatureDerElement($tag, $value, substr($bytes, $start, $position - $start));
        }
        return $elements;
    }
}
