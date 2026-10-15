<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\Adobe\Definition\WidgetAnnotationTable8_39Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Serialization\SerializationContext;

/** Separate widget, explicitly bound to both a field and its actual page. */
final class WidgetAnnotation extends SchemaDictionary
{
    private ?TerminalField $parentField = null;
    private ?PageObject $owningPage = null;
    private WidgetRepresentation $representation = WidgetRepresentation::Separate;

    public function __construct(Rectangle $rectangle, public readonly ?WidgetAppearance $appearance = null, public readonly ?NameType $state = null, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Type', 'Subtype', 'Rect', 'Parent', 'P', 'AP', 'AS'], true)) {
                throw new \InvalidArgumentException('Widget structure and appearance are constructor-controlled.');
            }
        }
        $managed = [new Entry(new NameType('Type'), new NameType('Annot')), new Entry(new NameType('Subtype'), new NameType('Widget')), new Entry(new NameType('Rect'), $rectangle)];
        if ($appearance !== null) {
            $managed[] = new Entry(new NameType('AP'), $appearance);
        }
        if ($state !== null) {
            $managed[] = new Entry(new NameType('AS'), $state);
        }
        parent::__construct(new WidgetAnnotationTable8_39Definition(), ...[...$managed, ...$entries]);
    }

    /** @internal Attach through TerminalField::addWidget(). */
    public function attachTo(TerminalField $field, WidgetRepresentation $representation = WidgetRepresentation::Separate): void
    {
        if ($this->parentField !== null) {
            throw new \InvalidArgumentException('A widget can belong to only one field.');
        }
        $this->parentField = $field;
        $this->representation = $representation;
    }

    public function bindToPage(PageObject $page): self
    {
        if ($this->owningPage !== null) {
            throw new \InvalidArgumentException('A widget can belong to only one page.');
        }
        $this->owningPage = $page;
        return $this;
    }

    public function page(): ?PageObject
    {
        return $this->owningPage;
    }

    public function entries(): iterable
    {
        if ($this->parentField !== null && $this->representation === WidgetRepresentation::Separate) {
            yield new Entry(new NameType('Parent'), new IndirectReference($this->parentField));
        }
        if ($this->owningPage !== null) {
            yield new Entry(new NameType('P'), new IndirectReference($this->owningPage));
        }
        yield from parent::entries();
    }

    public function validate(): void
    {
        parent::validate();
        if ($this->parentField === null || !in_array($this, $this->parentField->widgets(), true)) {
            throw new \InvalidArgumentException('A separate widget must belong to its parent field Kids.');
        }
        $annots = $this->owningPage?->get('Annots');
        $annots = $annots instanceof IndirectReference ? $annots->target() : $annots;
        $listed = false;
        foreach ($annots instanceof ArrayType ? $annots->items : [] as $item) {
            if ($item instanceof IndirectReference && $item->target() === ($this->representation === WidgetRepresentation::Merged ? $this->parentField : $this)) {
                $listed = true;
            }
        }
        if (!$listed) {
            throw new \InvalidArgumentException('A widget must belong to its page Annots array.');
        }
        if ($this->appearance?->requiresState() && $this->state === null) {
            throw new \InvalidArgumentException('Any state dictionary in N, R or D requires an AS appearance state.');
        }
        if ($this->appearance?->normal instanceof AppearanceStates) {
            $absentOff = $this->state?->value === 'Off' && $this->parentField instanceof ButtonField && $this->parentField->allowsAbsentOffAppearance();
            if ($this->state === null || (!$this->appearance->normal->has($this->state) && !$absentOff)) {
                throw new \InvalidArgumentException('A stateful appearance needs an AS selecting an existing normal state.');
            }
        }
        if ($this->appearance === null && $this->parentField->tree()?->needAppearances->value !== true) {
            throw new \InvalidArgumentException('Supply a widget appearance or explicitly request NeedAppearances.');
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('A widget is a builder; supply local entries in its constructor.');
    }

    public function toPdf(SerializationContext $context): string
    {
        if ($this->representation === WidgetRepresentation::Merged) {
            throw new \LogicException('A merged widget is serialized through its field dictionary.');
        }
        return parent::toPdf($context);
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('A widget is a builder; supply local entries in its constructor.');
    }

    private function __clone(): void
    {
    }
}
