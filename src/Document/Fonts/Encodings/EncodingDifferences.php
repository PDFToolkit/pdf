<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\Encodings;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

final class EncodingDifferences extends ArrayType
{
    /** @var array<int, NameType> */
    public readonly array $glyphs;
    public function __construct(EncodingDifference ...$runs)
    {
        $items = [];
        $glyphs = [];
        foreach ($runs as $run) {
            $items[] = $run->first;
            foreach ($run->names as $offset => $name) {
                $code = $run->first->integer() + $offset;
                if (isset($glyphs[$code])) {
                    throw new \InvalidArgumentException('Differences runs cannot define the same character code twice.');
                }
                $glyphs[$code] = $name;
                $items[] = $name;
            }
        }
        $this->glyphs = $glyphs;
        parent::__construct(...$items);
    }

    public function glyph(IntegerType $code): ?NameType
    {
        return $this->glyphs[$code->integer()] ?? null;
    }
}
