<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** All policy ownership is derived from the emitted catalog's frozen tree, never arbitrary V dictionaries. */
final class FrozenSignatureGraph
{
    public readonly DictionaryType $catalog;
    public readonly ?DictionaryType $form;
    /** @var list<FrozenSignatureField> */
    private array $fields = [];
    /** @var array<string, true> */
    private array $visited = [];

    public function __construct(public readonly FileRevision $revision)
    {
        $this->catalog = $revision->binding($revision->root->target())->dictionary ?? throw new \InvalidArgumentException('The signature operation requires a frozen document catalog.');
        $form = $this->resolve($this->catalog->get('AcroForm'));
        if ($form !== null && !$form instanceof DictionaryType) {
            throw new \InvalidArgumentException('The effective AcroForm is a dictionary.');
        }
        $this->form = $form;
        $roots = $this->resolve($form?->get('Fields'));
        if ($form !== null && !$roots instanceof ArrayType) {
            throw new \InvalidArgumentException('The effective AcroForm has its required Fields array.');
        }
        foreach ($roots instanceof ArrayType ? $roots->items : [] as $root) {
            if (!$root instanceof IndirectReference) {
                throw new \InvalidArgumentException('Signature tree fields have explicit indirect identities.');
            }
            $this->visit($root, '', [], null);
        }
    }

    public function record(IndirectReference $reference): RevisionObject
    {
        $record = $this->revision->binding($reference->target());
        if ($reference->identifier !== null && (string) $reference->identifier !== (string) $record->identifier) {
            throw new \InvalidArgumentException('A frozen policy reference has a conflicting object identifier.');
        }
        return $record;
    }

    public function resolve(?BasicType $value): ?BasicType
    {
        if ($value instanceof NullType) {
            return null;
        }
        if (!$value instanceof IndirectReference) {
            return $value;
        }
        try {
            $record = $this->record($value);
        } catch (\OutOfBoundsException $error) {
            foreach ($this->revision->retiredObjects() as $retired) {
                if (!in_array($value->target(), $retired->object->aliases, true)) {
                    continue;
                }
                if ($value->identifier !== null && (string) $value->identifier !== (string) $retired->object->identifier) {
                    throw new \InvalidArgumentException('An obsolete policy reference preserves its actual historical generation.');
                }
                return null;
            }
            throw $error;
        }
        $resolved = $record->dictionary ?? $record->snapshot;
        return $resolved instanceof NullType ? null : $resolved;
    }

    /** @return list<FrozenSignatureField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return list<FrozenSignatureField> */
    public function bindings(ObjectIdentifier $signature): array
    {
        $fields = [];
        foreach ($this->fields as $field) {
            $value = $field->effective('V');
            if ($value instanceof IndirectReference && $this->resolve($value) !== null && (string) $this->record($value)->identifier === (string) $signature) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /** @param array<string, BasicType> $inherited */
    private function visit(IndirectReference $reference, string $prefix, array $inherited, ?ObjectIdentifier $parent): void
    {
        $record = $this->record($reference);
        $identifier = (string) $record->identifier;
        if (isset($this->visited[$identifier])) {
            throw new \InvalidArgumentException('A signature field tree has no cycles or repeated field ownership.');
        }
        $this->visited[$identifier] = true;
        $dictionary = $record->dictionary ?? throw new \InvalidArgumentException('Each effective field is a dictionary.');
        $parentEntry = $dictionary->get('Parent');
        if ($parent === null ? $parentEntry !== null : (!$parentEntry instanceof IndirectReference || (string) $this->record($parentEntry)->identifier !== (string) $parent)) {
            throw new \InvalidArgumentException('The actual frozen field Parent/Kids relationships agree.');
        }
        $name = $dictionary->get('T');
        if ($name !== null && !$name instanceof StringType) {
            throw new \InvalidArgumentException('A partial field name is a text string.');
        }
        $qualified = $name instanceof StringType ? ($prefix === '' ? (new TextStringDecoder())->decode($name) : $prefix . '.' . (new TextStringDecoder())->decode($name)) : $prefix;
        foreach (['FT', 'V', 'Ff', 'DV'] as $key) {
            $value = $dictionary->get($key);
            if ($value === null) {
                continue;
            }
            $resolved = $this->resolve($value);
            if ($resolved !== null) {
                $inherited[$key] = in_array($key, ['FT', 'Ff'], true) ? $resolved : $value;
            }
        }
        $children = $this->resolve($dictionary->get('Kids'));
        if ($children !== null && !$children instanceof ArrayType) {
            throw new \InvalidArgumentException('Field Kids is an array.');
        }
        $fieldChildren = [];
        foreach ($children instanceof ArrayType ? $children->items : [] as $child) {
            if (!$child instanceof IndirectReference) {
                throw new \InvalidArgumentException('Each field/widget child has an indirect identity.');
            }
            $childRecord = $this->record($child);
            $value = $childRecord->dictionary ?? throw new \InvalidArgumentException('Field/widget children are dictionaries.');
            $subtype = $value->get('Subtype');
            if ($subtype instanceof NameType && $subtype->value === 'Widget' && !$value->has('T') && !$value->has('FT') && !$value->has('V') && !$value->has('SV') && !$value->has('Lock') && !$value->has('Ff')) {
                $parentValue = $value->get('Parent');
                $childIdentifier = (string) $childRecord->identifier;
                if (!$parentValue instanceof IndirectReference || (string) $this->record($parentValue)->identifier !== $identifier || isset($this->visited[$childIdentifier])) {
                    throw new \InvalidArgumentException('A separate widget belongs once to its actual frozen parent field.');
                }
                $this->visited[$childIdentifier] = true;
                continue;
            }
            $fieldChildren[] = $child;
        }
        if ($fieldChildren !== []) {
            foreach ($fieldChildren as $child) {
                $this->visit($child, $qualified, $inherited, $record->identifier);
            }
            return;
        }
        $type = $inherited['FT'] ?? null;
        if ($type instanceof NameType && $type->value === 'Sig') {
            $this->fields[] = new FrozenSignatureField($record->identifier, $dictionary, $qualified, $inherited);
        }
    }
}
