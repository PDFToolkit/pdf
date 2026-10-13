<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\Encodings;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** A starting byte followed by glyph names for consecutive codes (9.6.6). */
final readonly class EncodingDifference
{
    /** @var list<NameType> */
    public array $names;
    public function __construct(public IntegerType $first, NameType ...$names)
    {
        if ($names === [] || $first->integer() < 0 || $first->integer() > 256 - count($names)) {
            throw new \InvalidArgumentException('A Differences run is nonempty and fits byte codes 0–255.');
        }
        foreach ($names as $name) {
            if ($name->value === '') {
                throw new \InvalidArgumentException('An encoding glyph name is nonempty.');
            }
        }
        $this->names = array_values($names);
    }
}
