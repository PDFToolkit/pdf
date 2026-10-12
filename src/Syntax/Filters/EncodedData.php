<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Already encoded external data, e.g. JPEG/JPEG2000. No codec is applied. */
final readonly class EncodedData implements Filter
{
    public function __construct(public FilterName $filter, private ?DictionaryType $decodeParameters = null)
    {
        if ($filter === FilterName::Crypt) {
            throw new \InvalidArgumentException('Crypt requires document-level security handling.');
        }
    }
    public function name(): NameType
    {
        return new NameType($this->filter->value);
    }
    public function parameters(): ?DictionaryType
    {
        return $this->decodeParameters;
    }
    public function encode(string $bytes): string
    {
        return $bytes;
    }
}
