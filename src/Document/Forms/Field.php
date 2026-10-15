<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\SchemaDictionary;
use PDF\Specification\TableDefinition;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** A document-scoped field-tree builder (ISO 12.7.3, PDF pp.440–443). */
abstract class Field extends SchemaDictionary
{
    private ?NonTerminalField $parentField = null;
    private ?FieldTree $owningTree = null;

    protected function __construct(TableDefinition $definition, private readonly ?PartialFieldName $partialName, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Parent', 'Kids', 'T'], true)) {
                throw new \InvalidArgumentException('Field-tree Parent, Kids and T entries are managed by the field.');
            }
        }
        parent::__construct($definition, ...$entries);
    }

    public function parent(): ?NonTerminalField
    {
        return $this->parentField;
    }

    public function tree(): ?FieldTree
    {
        return $this->owningTree;
    }

    public function qualifiedName(): string
    {
        $prefix = $this->parentField?->qualifiedName() ?? '';
        $name = $this->partialName?->value->text;
        return $name === null ? $prefix : ($this->parentField === null ? $name : $prefix . '.' . $name);
    }

    /** @internal Attach through NonTerminalField::addField(). */
    public function attachTo(NonTerminalField $parent): void
    {
        if ($this->parentField !== null || $this->owningTree !== null) {
            throw new \InvalidArgumentException('A field can have only one parent or owning form.');
        }
        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->parent()) {
            if ($ancestor === $this) {
                throw new \InvalidArgumentException('A field tree cannot contain a cycle.');
            }
        }
        $this->parentField = $parent;
    }

    /** @internal Ownership is established only by FieldTree. */
    public function claim(FieldTree $tree): void
    {
        if ($this->owningTree !== null && $this->owningTree !== $tree) {
            throw new \InvalidArgumentException('A field cannot belong to multiple forms.');
        }
        $this->owningTree = $tree;
        if ($this instanceof NonTerminalField) {
            foreach ($this->fields() as $child) {
                $child->claim($tree);
            }
        }
    }

    /** Resolve only attributes declared inheritable by the field's definition. */
    public function effective(string $key): ?BasicType
    {
        $local = parent::get($key);
        if ($local !== null && !$local instanceof NullType) {
            return $local;
        }
        if ($this->definition()->has($key) && $this->definition()->entry($key)->requirement->inheritable) {
            for ($ancestor = $this->parentField; $ancestor !== null; $ancestor = $ancestor->parent()) {
                $value = $ancestor->get($key);
                if ($value !== null && !$value instanceof NullType) {
                    return $value;
                }
            }
        }
        $default = $this->owningTree?->defaultValue($key);
        return $default instanceof NullType ? null : $default;
    }

    public function flags(): int
    {
        $value = $this->effective('Ff');
        if ($value !== null && (!$value instanceof IntegerType || $value->value < 0 || $value->value > 0xffffffff)) {
            throw new \InvalidArgumentException('Field flags are an unsigned 32-bit integer.');
        }
        return $value instanceof IntegerType ? $value->value : 0;
    }

    public function entries(): iterable
    {
        if ($this->parentField !== null) {
            if (!in_array($this, $this->parentField->fields(), true)) {
                throw new \LogicException('A field parent must contain its child in Kids.');
            }
            yield new Entry(new NameType('Parent'), new IndirectReference($this->parentField));
        }
        if ($this->partialName !== null) {
            yield new Entry(new NameType('T'), $this->partialName->value);
        }
        yield from parent::entries();
    }

    public function validate(): void
    {
        foreach ($this->entries() as $entry) {
            if ($this->definition()->has($entry->key)) {
                $this->definition()->entry($entry->key)->type->validate($entry->value);
            }
        }
        foreach ($this->definition()->entries() as $entry) {
            $value = $this->effective($entry->name);
            if ($entry->requirement->unconditional() && $value === null) {
                throw new \InvalidArgumentException('Missing explicit or inherited field entry /' . $entry->name . '.');
            }
            if ($value !== null) {
                $entry->type->validate($value);
            }
        }
        $this->flags();
        $quadding = $this->effective('Q');
        if ($quadding !== null && (!$quadding instanceof IntegerType || !in_array($quadding->value, [0, 1, 2], true))) {
            throw new \InvalidArgumentException('Field quadding is 0, 1 or 2.');
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('A field is a tree builder; provide all local entries in its constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('A field is a tree builder; configure local entries in its constructor.');
    }

    private function __clone(): void
    {
    }
}
