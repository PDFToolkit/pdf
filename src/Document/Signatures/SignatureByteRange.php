<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;

/** Full file coverage except the Contents hexadecimal token, including both delimiters. */
final readonly class SignatureByteRange
{
    public function __construct(public int $before, public int $afterOffset, public int $afterLength)
    {
        if ($before < 0 || $afterOffset <= $before || $afterLength < 0 || $afterOffset > PHP_INT_MAX - $afterLength) {
            throw new \InvalidArgumentException('Invalid signature byte range.');
        }
    }

    public function object(): ArrayType
    {
        return new ArrayType(new IntegerType(0), new IntegerType($this->before), new IntegerType($this->afterOffset), new IntegerType($this->afterLength));
    }

    public function coveredBytes(string $bytes): string
    {
        if (strlen($bytes) < $this->afterOffset + $this->afterLength) {
            throw new \InvalidArgumentException('The supplied bytes are shorter than the signed revision.');
        }
        return substr($bytes, 0, $this->before) . substr($bytes, $this->afterOffset, $this->afterLength);
    }
}
