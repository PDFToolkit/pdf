<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\PageLabelDictionaryTable159Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** A range's numbering rule, ISO12.4.2/Table159, PDF pp382–383. */
final class PageLabel extends SchemaDictionary implements VersionedComponent
{
    public function __construct(public readonly ?LabelNumbering $numbering = null, public readonly ?TextString $prefix = null, public readonly IntegerType $start = new IntegerType(1))
    {
        if ($start->value < 1) {
            throw new \InvalidArgumentException('PageLabel.St must be at least one.');
        }
        $entries = [new Entry(new NameType('Type'), new NameType('PageLabel')), new Entry(new NameType('St'), $start)];
        if ($numbering !== null) {
            $entries[] = new Entry(new NameType('S'), new NameType($numbering->value));
        }
        if ($prefix !== null) {
            $entries[] = new Entry(new NameType('P'), $prefix);
        }
        parent::__construct(new PageLabelDictionaryTable159Definition(), ...$entries);
    }

    public function label(IntegerType $offset, NavigationLimits $limits = new NavigationLimits()): TextString
    {
        if ($offset->value < 0) {
            throw new \InvalidArgumentException('A page label offset cannot be negative.');
        }
        $text = $this->prefix === null ? '' : $this->prefix->text;
        if ($this->numbering !== null) {
            if ($offset->integer() > PHP_INT_MAX - $this->start->integer()) {
                throw new \OutOfRangeException('The sequential page number exceeds the integer representation.');
            }
            $text .= $this->numbering->format($this->start->integer() + $offset->integer(), $limits);
        }
        if (strlen($text) > $limits->maxLabelBytes) {
            throw new \LengthException('The page label exceeds its explicit text output budget.');
        }
        return new TextString($text);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF13, 'Page labels');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Supply page label values in the constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Supply page label values in the constructor.');
    }
}
