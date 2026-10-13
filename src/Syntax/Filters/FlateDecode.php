<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\DictionaryType;

/** 7.4.4: zlib framing, with an explicitly composed PDF sample predictor. */
final readonly class FlateDecode implements Filter
{
    public function __construct(public int $compressionLevel = -1, public ?Predictor $predictor = null)
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
        return $this->predictor?->parameters();
    }
    public function encode(string $bytes): string
    {
        $encoded = gzcompress($this->predictor?->encode($bytes) ?? $bytes, $this->compressionLevel);
        if ($encoded === false) {
            throw new \RuntimeException('zlib compression failed.');
        }
        return $encoded;
    }
}
