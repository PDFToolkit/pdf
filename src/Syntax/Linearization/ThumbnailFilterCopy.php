<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Encoding is unchanged; decoding parameters use the output thumbnail dependency graph. */
final readonly class ThumbnailFilterCopy implements Filter
{
    public function __construct(private Filter $source, private ThumbnailGraphCopies $copies)
    {
    }

    public function name(): NameType
    {
        return $this->source->name();
    }

    public function parameters(): ?DictionaryType
    {
        return ($parameters = $this->source->parameters()) === null ? null : new ThumbnailDictionaryCopy($parameters, $this->copies);
    }

    public function encode(string $bytes): string
    {
        return $this->source->encode($bytes);
    }
}
