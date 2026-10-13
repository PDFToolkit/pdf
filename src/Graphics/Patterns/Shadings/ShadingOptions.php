<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Explicit Background/BBox/AntiAlias declarations (table 78, PDF page 191). */
final readonly class ShadingOptions
{
    public function __construct(public ?ColourComponents $background = null, public ?Rectangle $boundingBox = null, public BooleanType $antiAlias = new BooleanType(false))
    {
    }

    /** @return list<Entry> */
    public function entries(ColourSpace $space): array
    {
        if ($this->background !== null && $this->background->space !== $space) {
            throw new \InvalidArgumentException('A shading background must belong to the same explicitly declared colour space.');
        }
        $entries = [new Entry(new NameType('AntiAlias'), $this->antiAlias)];
        if ($this->background !== null) {
            $entries[] = new Entry(new NameType('Background'), $this->background);
        }
        if ($this->boundingBox !== null) {
            $entries[] = new Entry(new NameType('BBox'), $this->boundingBox);
        }
        return $entries;
    }
}
