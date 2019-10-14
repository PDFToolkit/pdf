<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\ParentClass;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\LexicalConvention\CharacterSet\Delimiter;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1638921
 */
abstract class DictionaryType implements BasicType
{
    protected $string;

    /** @var BasicType */
    protected $parent;

    public function parent(): ?BasicType
    {
        return $this->parent;
    }

    public function setParent(BasicType $parent): void
    {
        $this->parent = $parent;
    }
}
