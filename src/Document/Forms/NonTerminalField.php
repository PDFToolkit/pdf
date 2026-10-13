<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\Definition\FieldDictionaryTable220Definition;
use PDF\Specification\Definition\FieldsContainingVariableTextTable222Definition;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** A field group. Its FT, Ff, V, DV and variable-text entries may be inherited. */
final class NonTerminalField extends Field
{
    /** @var list<Field> */
    private array $children = [];

    public function __construct(?PartialFieldName $name = null, Entry ...$inheritedEntries)
    {
        $common = new FieldDictionaryTable220Definition();
        parent::__construct(new TableDefinition($common->source, 'Non-terminal field', [], [$common, new FieldsContainingVariableTextTable222Definition()]), $name, ...$inheritedEntries);
    }

    public function addField(Field $field): self
    {
        $field->attachTo($this);
        $this->children[] = $field;
        if ($this->tree() !== null) {
            $field->claim($this->tree());
        }
        return $this;
    }

    /** @return list<Field> */
    public function fields(): array
    {
        return $this->children;
    }

    public function entries(): iterable
    {
        yield new Entry(new NameType('Kids'), new ArrayType(...array_map(static fn (Field $field) => new IndirectReference($field), $this->children)));
        yield from parent::entries();
    }

    public function validate(): void
    {
        // DA is required only by descendants that actually contain variable text.
        foreach ($this->entries() as $entry) {
            if ($this->definition()->has($entry->key)) {
                $this->definition()->entry($entry->key)->type->validate($entry->value);
            }
        }
        if ($this->children === []) {
            throw new \InvalidArgumentException('A non-terminal field needs at least one field child.');
        }
        $this->flags();
    }
}
