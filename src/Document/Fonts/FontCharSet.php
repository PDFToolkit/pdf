<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\ByteString;
use PDF\Syntax\Object\NameType;

/** Table 122: glyph names serialized as PDF names; .notdef is implicit and omitted. */
final class FontCharSet extends ByteString
{
    /** @var list<NameType> */
    public readonly array $names;

    public function __construct(NameType ...$names)
    {
        $seen = [];
        $serialized = '';
        $context = new SerializationContext();
        foreach ($names as $name) {
            if ($name->value === '' || $name->value === '.notdef' || isset($seen[$name->value])) {
                throw new \InvalidArgumentException('CharSet lists distinct nonempty glyph names and omits .notdef.');
            }
            $seen[$name->value] = true;
            $serialized .= $context->serialize($name);
        }
        $this->names = array_values($names);
        parent::__construct($serialized);
    }
}
