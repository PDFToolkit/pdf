<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\DictionaryType;

/** 7.4.4: zlib framing, not raw deflate. Predictors are a separate transform. */
final readonly class FlateDecode implements Filter
{
    public function __construct(public int $compressionLevel = -1)
    {
        if ($compressionLevel < -1 || $compressionLevel > 9) {
            throw new \InvalidArgumentException('Compression level must be -1..9.');
        }
    }
    public function name(): NameType
    {
        return new NameType('FlateDecode');
    }
    public function parameters(): ?DictionaryType
    {
        return null;
    }
    public function encode(string $bytes): string
    {
        $encoded = gzcompress($bytes, $this->compressionLevel);
        if ($encoded === false) {
            throw new \RuntimeException('zlib compression failed.');
        }
        return $encoded;
    }
}
