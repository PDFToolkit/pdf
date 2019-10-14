<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\PageTree;

use PDF\StringClass;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\LexicalConvention\CharacterSet\Delimiter;
use PDF\Syntax\LexicalConvention\CharacterSet\WhiteSpace;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\StreamObject;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1956489
 */
class PageObject extends DictionaryType implements PageTree
{
    /** @var Rectangle */
    private $mediaBox;

    /** @var StreamObject */
    private $contents;

    public function __toString(): string
    {
        $string = sprintf(
            <<<PDF
/Type /Page
/Parent %s
/MediaBox %s
PDF
            ,
            new IndirectReference($this->parent()),
            $this->mediaBox
        );

        if ($this->contents) {
            $string .= sprintf('%s/Contents %s%s', WhiteSpace::EOF(), new IndirectReference($this->contents), WhiteSpace::EOF());
        }

        return $this->enclose($string);
    }

    public function setmediaBox(Rectangle $rectangle): self
    {
        $this->mediaBox = $rectangle;

        return $this;
    }

    public function setContents(ContentStreams $contentStreams): self
    {
        $this->contents = $contentStreams;

        return $this;
    }

    /**
     * A dictionary shall be written as a sequence of key-value pairs enclosed in double angle brackets (<< … >>)
     */
    private function enclose(string $string)
    {
        $string = sprintf(
            '%s%s %s %s%s%sendobj%s',
            Delimiter::LESS_THAN_SIGN(),
            Delimiter::LESS_THAN_SIGN(),
            $string,
            Delimiter::GREATER_THAN_SIGN(),
            Delimiter::GREATER_THAN_SIGN(),
            WhiteSpace::EOF(),
            WhiteSpace::EOF()
        );

        if ($this->contents) {
            $string .= sprintf('%s', $this->contents);
        }

        return $string;
    }
}
