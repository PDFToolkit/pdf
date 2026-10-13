<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Explicit separate widget children or one deliberately merged field/widget dictionary. */
abstract class TerminalField extends Field
{
    /** @var list<WidgetAnnotation> */
    private array $annotations = [];
    private bool $mergedWidget = false;

    protected function __construct(TableDefinition $definition, ?PartialFieldName $name, private readonly FieldType $fieldType, FieldTypeDeclaration $declaration, ?FieldFlags $flags, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if ($entry->key->value === 'FT' || ($flags !== null && $entry->key->value === 'Ff')) {
                throw new \InvalidArgumentException('Field type and supplied flags are constructor-controlled.');
            }
        }
        $managed = [];
        if ($declaration === FieldTypeDeclaration::Explicit) {
            $managed[] = new Entry(new NameType('FT'), new NameType($fieldType->value));
        }
        if ($flags !== null) {
            $managed[] = new Entry(new NameType('Ff'), $flags->value);
        }
        parent::__construct($definition, $name, ...[...$managed, ...$entries]);
    }

    public function addWidget(WidgetAnnotation $widget): static
    {
        if ($this->mergedWidget) {
            throw new \LogicException('A merged field/widget cannot have additional widgets.');
        }
        $widget->attachTo($this);
        $this->annotations[] = $widget;
        return $this;
    }

    public function mergeWidget(WidgetAnnotation $widget): static
    {
        if ($this->annotations !== []) {
            throw new \LogicException('Merge one widget only into a field without existing widgets.');
        }
        $widget->attachTo($this, WidgetRepresentation::Merged);
        $this->mergedWidget = true;
        $this->annotations[] = $widget;
        return $this;
    }

    /** @return list<WidgetAnnotation> */
    public function widgets(): array
    {
        return $this->annotations;
    }

    public function entries(): iterable
    {
        if ($this->mergedWidget) {
            yield from $this->annotations[0]->entries();
        } elseif ($this->annotations !== []) {
            yield new Entry(new NameType('Kids'), new ArrayType(...array_map(static fn (WidgetAnnotation $widget) => new IndirectReference($widget), $this->annotations)));
        }
        yield from parent::entries();
    }

    public function validate(): void
    {
        parent::validate();
        $type = $this->effective('FT');
        if (!$type instanceof NameType || $type->value !== $this->fieldType->value) {
            throw new \InvalidArgumentException('A terminal field requires its matching explicit or inherited FT.');
        }
        if ($this->fieldType === FieldType::Text || $this->fieldType === FieldType::Choice) {
            $appearance = $this->effective('DA');
            $resources = $this->tree()?->defaultValue('DR');
            if (!$appearance instanceof DefaultAppearance || !$resources instanceof DictionaryType) {
                throw new \InvalidArgumentException('Variable text needs an explicit typed default appearance and form default resources.');
            }
            $appearance->validateResources($resources);
        }
        foreach ($this->annotations as $widget) {
            $widget->validate();
        }
    }
}
