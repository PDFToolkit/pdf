<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\PageTree;

use PDF\Syntax\DocumentStructure\CatalogDictionary\Entry;
use PDF\Syntax\DocumentStructure\PageTree;
use PDF\Syntax\LexicalConvention\CharacterSet\Delimiter;
use PDF\Syntax\LexicalConvention\CharacterSet\WhiteSpace;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1639962
 */
class PageTreeNode extends DictionaryType implements PageTree, Entry
{
    /**
     * An array of indirect references to the immediate children of this node.
     * The children shall only be page objects or other page tree nodes.
     *
     * @var PageTree[]
     */
    public $kids;

    public function __construct()
    {
        $this->kids = [];
    }

    public function addKid(PageTree $kid): self
    {
        $kid->setParent($this);
        $this->kids[] = $kid;

        return $this;
    }

    /**
     * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1942297
     */
    public function __toString(): string
    {
        $kids = '';
        foreach ($this->kids as $kid) {
            $kids .= sprintf('%s', new IndirectReference($kid));
        }
        $string = $this->enclose(sprintf('/Type /Pages%s /Kids [ %s ]%s/Count %d', WhiteSpace::EOF(), $kids, WhiteSpace::EOF(), count($this->kids)));
        foreach ($this->kids as $kid) {
            $string .= sprintf('%s', new IndirectObject($kid));
        }

        return $string;
    }

    /**
     * A dictionary shall be written as a sequence of key-value pairs enclosed in double angle brackets (<< … >>)
     */
    private function enclose(string $string)
    {
        return sprintf(
            '%s%s %s %s%s%sendobj%s',
            Delimiter::LESS_THAN_SIGN(),
            Delimiter::LESS_THAN_SIGN(),
            $string,
            Delimiter::GREATER_THAN_SIGN(),
            Delimiter::GREATER_THAN_SIGN(),
            WhiteSpace::EOF(),
            WhiteSpace::EOF()
        );
    }

    public function key()
    {
        return Entry::PAGES;
    }
}
