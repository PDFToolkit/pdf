<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\SignatureDerReader;
use PDF\Syntax\Object\StringType;

/** Separate the exact DER object from permitted zero padding in signature Contents. */
final readonly class SignatureContentsValue
{
    public SignatureValue $value;

    public function __construct(public StringType $contents)
    {
        $bytes = $contents->value;
        if (strlen($bytes) < 2) {
            throw new \InvalidArgumentException('Signature Contents has a complete DER object.');
        }
        $position = 2;
        $length = ord($bytes[1]);
        if (($length & 128) !== 0) {
            $count = $length & 127;
            if ($count < 1 || $count > PHP_INT_SIZE || $count > strlen($bytes) - 2) {
                throw new \InvalidArgumentException('The signature DER length header is bounded.');
            }
            $length = 0;
            for ($index = 0; $index < $count; ++$index) {
                $byte = ord($bytes[$position++]);
                if ($length > intdiv(PHP_INT_MAX - $byte, 256)) {
                    throw new \OverflowException('The signature Contents DER length exceeds native bounds.');
                }
                $length = $length * 256 + $byte;
            }
        }
        if ($length > strlen($bytes) - $position) {
            throw new \InvalidArgumentException('The signature DER object exceeds Contents.');
        }
        $size = $position + $length;
        $value = substr($bytes, 0, $size);
        (new SignatureDerReader())->element($value);
        if (substr($bytes, $size) !== str_repeat("\0", strlen($bytes) - $size)) {
            throw new \InvalidArgumentException('Only zero padding may follow the DER signature object.');
        }
        $this->value = new SignatureValue($value);
    }
}
