<?php

declare(strict_types=1);

namespace PDF\Document\Forms\Fdf;

use PDF\Document\Forms\PartialFieldName;
use PDF\Specification\Definition\FDFFieldDictionaryTable246Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IntegerType;

/** Immutable FDF field; unlike PDF fields, Kids can be direct (ISO p.469). */
final class FdfField extends SchemaDictionary
{
    public function __construct(PartialFieldName $name, ?BasicType $value = null, ?ArrayType $children = null, Entry ...$entries)
    {
        $managed = [new Entry(new NameType('T'), $name->value)];
        if ($value !== null) {
            $managed[] = new Entry(new NameType('V'), $value);
        }
        if ($children !== null) {
            foreach ($children->items as $child) {
                $child = $child instanceof IndirectReference ? $child->target() : $child;
                if (!$child instanceof self) {
                    throw new \InvalidArgumentException('FDF Kids contain FDF fields, directly or indirectly.');
                }
            }
            $managed[] = new Entry(new NameType('Kids'), $children);
        }
        parent::__construct(new FDFFieldDictionaryTable246Definition(), ...[...$managed, ...$entries]);
    }

    public function validate(): void
    {
        // Opt is required only for choice fields; FDF dictionaries carry no FT.
        foreach ($this->entries() as $entry) {
            if ($this->definition()->has($entry->key)) {
                $this->definition()->entry($entry->key)->type->validate($entry->value);
            }
        }
        foreach (['Ff', 'SetFf', 'ClrFf', 'F', 'SetF', 'ClrF'] as $key) {
            $flags = $this->get($key);
            if ($flags !== null && (!$flags instanceof IntegerType || $flags->value < 0 || $flags->value > 0xffffffff)) {
                throw new \InvalidArgumentException('FDF flags are unsigned 32-bit integers.');
            }
        }
    }

    /** Explicitly apply PDF's replace, set, then clear precedence (ISO pp.469–470). */
    public function applyFieldFlags(IntegerType $previous): IntegerType
    {
        return $this->applyFlags($previous, 'Ff', 'SetFf', 'ClrFf');
    }

    public function applyAnnotationFlags(IntegerType $previous): IntegerType
    {
        return $this->applyFlags($previous, 'F', 'SetF', 'ClrF');
    }

    private function applyFlags(IntegerType $previous, string $replaceKey, string $setKey, string $clearKey): IntegerType
    {
        $this->validate();
        if ($previous->value < 0 || $previous->value > 0xffffffff) {
            throw new \InvalidArgumentException('Existing flags are an unsigned 32-bit integer.');
        }
        $replace = $this->get($replaceKey);
        if ($replace instanceof IntegerType) {
            return $replace;
        }
        $set = $this->get($setKey);
        $clear = $this->get($clearKey);
        return new IntegerType(($previous->value | ($set instanceof IntegerType ? $set->value : 0)) & ~($clear instanceof IntegerType ? $clear->value : 0));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('FDF field construction is explicit and immutable.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('FDF field construction is explicit and immutable.');
    }
}
