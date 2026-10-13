<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Graphics\GraphicsState\BlendingColourSpace;
use PDF\Specification\Definition\TransparencyGroupAttributesDictionaryTable147Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Group attributes usable for pages, isolated forms and soft-mask groups (table 147). */
final class TransparencyGroup extends SchemaDictionary implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF14, 'Transparency group');
    }

    public const string TABLE = '147';

    public function __construct(public readonly ?BlendingColourSpace $colourSpace = null, public readonly BooleanType $isolated = new BooleanType(false), public readonly BooleanType $knockout = new BooleanType(false))
    {
        $entries = [new Entry(new NameType('Type'), new NameType('Group')), new Entry(new NameType('S'), new NameType('Transparency')), new Entry(new NameType('I'), $isolated), new Entry(new NameType('K'), $knockout)];
        if ($colourSpace !== null) {
            $entries[] = new Entry(new NameType('CS'), $colourSpace->space->value());
        }
        parent::__construct(new TransparencyGroupAttributesDictionaryTable147Definition(), ...$entries);
    }

    public function validateForm(): void
    {
        if ($this->colourSpace !== null && !$this->isolated->value) {
            throw new \InvalidArgumentException('A form transparency group may declare CS only when isolated.');
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Transparency-group declarations are constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Transparency-group declarations are constructor-controlled.');
    }
}
