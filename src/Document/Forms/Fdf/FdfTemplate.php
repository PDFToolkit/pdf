<?php

declare(strict_types=1);

namespace PDF\Document\Forms\Fdf;

use PDF\Specification\Definition\FDFTemplateDictionaryTable249Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** ISO p.472: page template import declaration; Rename specifies no renaming algorithm. */
final class FdfTemplate extends SchemaDictionary
{
    public function __construct(FdfNamedPageReference $reference, ?ArrayType $fields = null, BooleanType $rename = new BooleanType(true))
    {
        $entries = [new Entry(new NameType('TRef'), $reference), new Entry(new NameType('Rename'), $rename)];
        if ($fields !== null) {
            foreach ($fields->items as $field) {
                if (!$field instanceof IndirectReference || !$field->target() instanceof FdfField) {
                    throw new \InvalidArgumentException('FDF template Fields contains indirect root field references.');
                }
            }
            $entries[] = new Entry(new NameType('Fields'), $fields);
        }
        parent::__construct(new FDFTemplateDictionaryTable249Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('FDF template import data is constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('FDF template import data is constructor-controlled.');
    }
}
