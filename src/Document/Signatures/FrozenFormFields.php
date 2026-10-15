<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** A strict, identity-based inventory used only when classifying protected form changes. */
final class FrozenFormFields
{
    /** @var array<string, FrozenFormField> */
    private array $fields = [];
    /** @var array<string, true> */
    private array $visited = [];
    /** @var array<string, true> */
    private array $names = [];
    private int $remaining = 100000;

    public function __construct(private readonly FrozenSignatureGraph $graph)
    {
        $form = $graph->form;
        if ($form === null) {
            return;
        }
        $catalog = (string) $graph->revision->binding($graph->revision->root->target())->identifier;
        $formOwner = $this->owner($graph->catalog->get('AcroForm'), $catalog);
        $roots = $graph->resolve($form->get('Fields'));
        if (!$roots instanceof ArrayType) {
            throw new \LogicException('Protected form filling requires the actual Fields array.');
        }
        $owner = $this->owner($form->get('Fields'), $formOwner);
        foreach ($roots->items as $root) {
            $this->charge();
            if (!$root instanceof IndirectReference) {
                throw new \LogicException('Protected field identities must be indirect.');
            }
            $this->visit($root, '', [], null, $owner);
        }
    }

    /** @return array<string, FrozenFormField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** The actual page Annots container owning this widget, with no duplicate ownership. */
    public function widgetOwner(ObjectIdentifier $widget, DictionaryType $dictionary): string
    {
        $owners = [];
        $pages = [];
        foreach ($this->graph->revision->objects() as $record) {
            $this->charge();
            $type = $record->dictionary?->get('Type');
            if (!$type instanceof NameType || $type->value !== 'Page') {
                continue;
            }
            $annots = $record->dictionary->get('Annots');
            $array = $this->graph->resolve($annots);
            foreach ($array instanceof ArrayType ? $array->items : [] as $item) {
                $this->charge();
                if ($item instanceof IndirectReference && (string) $this->graph->record($item)->identifier === (string) $widget) {
                    $owners[] = $this->owner($annots, (string) $record->identifier);
                    $pages[] = (string) $record->identifier;
                }
            }
        }
        $page = $dictionary->get('P');
        if (count($owners) !== 1 || ($page !== null && (!$page instanceof IndirectReference || (string) $this->graph->record($page)->identifier !== $pages[0]))) {
            throw new \LogicException('A protected widget belongs to exactly one actual page Annots array.');
        }
        return $owners[0];
    }

    /** @param array<string, BasicType> $inherited */
    private function visit(IndirectReference $reference, string $prefix, array $inherited, ?ObjectIdentifier $parent, string $owner, int $depth = 0): void
    {
        $this->charge();
        if ($depth > 128) {
            throw new \LogicException('Protected field-tree depth exceeds its classification budget.');
        }
        $record = $this->graph->record($reference);
        $identifier = (string) $record->identifier;
        if (isset($this->visited[$identifier])) {
            throw new \LogicException('Protected form trees have no cycles or repeated ownership.');
        }
        $this->visited[$identifier] = true;
        $dictionary = $record->dictionary ?? throw new \LogicException('Protected fields are dictionaries.');
        $actualParent = $dictionary->get('Parent');
        if ($parent === null ? $actualParent !== null : (!$actualParent instanceof IndirectReference || (string) $this->graph->record($actualParent)->identifier !== (string) $parent)) {
            throw new \LogicException('Protected field Parent and Kids identities agree.');
        }
        $name = $dictionary->get('T');
        if ($name !== null && !$name instanceof StringType) {
            throw new \LogicException('Protected partial field names are direct text strings.');
        }
        $partial = $name instanceof StringType ? (new TextStringDecoder())->decode($name) : '';
        if (str_contains($partial, '.')) {
            throw new \LogicException('Protected partial field names cannot contain qualification separators.');
        }
        $qualified = $partial === '' ? $prefix : ($prefix === '' ? $partial : $prefix . '.' . $partial);
        foreach (['FT', 'V', 'Ff', 'DV', 'DA', 'Q', 'Opt', 'MaxLen'] as $key) {
            $value = $dictionary->get($key);
            if ($value !== null && $this->graph->resolve($value) !== null) {
                $inherited[$key] = $value;
            }
        }
        $childrenValue = $dictionary->get('Kids');
        $children = $this->graph->resolve($childrenValue);
        if ($children !== null && !$children instanceof ArrayType) {
            throw new \LogicException('Protected field Kids is an array.');
        }
        $childOwner = $this->owner($childrenValue, $identifier);
        $fields = [];
        $widgets = [];
        foreach ($children instanceof ArrayType ? $children->items : [] as $child) {
            $this->charge();
            if (!$child instanceof IndirectReference) {
                throw new \LogicException('Protected field and widget children are indirect.');
            }
            $childRecord = $this->graph->record($child);
            $value = $childRecord->dictionary ?? throw new \LogicException('Protected field and widget children are dictionaries.');
            $subtype = $value->get('Subtype');
            $separate = $subtype instanceof NameType && $subtype->value === 'Widget';
            foreach (['T', 'FT', 'V', 'DV', 'Ff', 'SV', 'Lock', 'Kids'] as $key) {
                $separate = $separate && !$value->has($key);
            }
            if (!$separate) {
                $fields[] = $child;
                continue;
            }
            $childIdentifier = (string) $childRecord->identifier;
            $childParent = $value->get('Parent');
            if (isset($this->visited[$childIdentifier]) || !$childParent instanceof IndirectReference || (string) $this->graph->record($childParent)->identifier !== $identifier) {
                throw new \LogicException('A separate protected widget has exactly one field owner.');
            }
            $this->visited[$childIdentifier] = true;
            $widgets[] = $childRecord;
        }
        if ($fields !== []) {
            if ($widgets !== []) {
                throw new \LogicException('Protected field trees do not mix terminal widgets and descendant fields.');
            }
            foreach ($fields as $child) {
                $this->visit($child, $qualified, $inherited, $record->identifier, $childOwner, $depth + 1);
            }
            return;
        }
        if ($qualified === '' || isset($this->names[$qualified])) {
            throw new \LogicException('Protected terminal fields require distinct, unambiguous qualified names.');
        }
        $this->names[$qualified] = true;
        $subtype = $dictionary->get('Subtype');
        if ($subtype instanceof NameType && $subtype->value === 'Widget') {
            if ($widgets !== []) {
                throw new \LogicException('A merged protected widget cannot also own separate widgets.');
            }
            $widgets[] = $record;
        }
        $owners = [$owner];
        foreach ($widgets as $widget) {
            if ((string) $widget->identifier === $identifier) {
                $owners[] = $this->widgetOwner($record->identifier, $dictionary);
            } else {
                $owners[] = (string) $widget->identifier;
            }
        }
        $this->fields[$identifier] = new FrozenFormField($record->identifier, $dictionary, $qualified, $inherited, $widgets, $owners);
    }

    private function owner(?BasicType $value, string $parent): string
    {
        return $value instanceof IndirectReference ? (string) $this->graph->record($value)->identifier : $parent;
    }

    private function charge(): void
    {
        if (--$this->remaining < 0) {
            throw new \LogicException('Protected field classification exceeds its bounded inventory budget.');
        }
    }
}
