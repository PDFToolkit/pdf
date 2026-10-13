<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Definition\CatalogDictionaryTable28Definition;
use PDF\Specification\Definition\PageObjectTable30Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Conservative legacy-visible closure, ISO7.5.8.4, PDF page60; resources and complete font graphs stay visible. */
final class HybridVisibleGraph
{
    /** @var array<int, true> */
    private array $numbers = [];
    /** @var \SplObjectStorage<BasicType, null> */
    private \SplObjectStorage $seen;
    /** @var array<int, RevisionObject> */
    private array $historical = [];

    public function __construct(private readonly SerializationContext $context, DictionaryType $catalog, ?FileRevision $previous = null)
    {
        $this->seen = new \SplObjectStorage();
        foreach ($previous?->objects() ?? [] as $record) {
            $this->historical[$record->identifier->objectNumber->value] = $record;
        }
        $this->numbers[$context->identifier($catalog)->objectNumber->value] = true;
        $this->visit($catalog);
    }

    /** @return array<int, true> */
    public function objectNumbers(): array
    {
        return $this->numbers;
    }

    private function visit(BasicType $value): void
    {
        if ($value instanceof IndirectReference) {
            $number = $this->context->reference($value)->objectNumber->value;
            $this->numbers[$number] = true;
            $this->visit($this->context->objects()[$number] ?? $this->historical[$number]->snapshot ?? $value->target());
            return;
        }
        if ($this->seen->offsetExists($value)) {
            return;
        }
        $this->seen->offsetSet($value);
        if ($value instanceof ArrayType) {
            foreach ($value->items as $item) {
                $this->visit($item);
            }
            return;
        }
        if ($value instanceof StreamObject) {
            $this->visit($value->dictionary());
            foreach ($value->filters as $filter) {
                if (($parameters = $filter->parameters()) !== null) {
                    $this->visit($parameters);
                }
            }
            return;
        }
        if (!$value instanceof DictionaryType) {
            return;
        }
        $dictionary = $value;
        if ($this->context->isHistorical($value)) {
            $number = $this->context->identifier($value)->objectNumber->value;
            if (($this->context->objects()[$number] ?? null) !== $value) {
                $dictionary = $this->historical[$number]->dictionary ?? $value;
            }
        }
        $type = $dictionary->get('Type');
        $type = $type instanceof NameType ? $type->value : '';
        $definition = $value instanceof SchemaDictionary ? $value->definition() : match ($type) {
            'Catalog' => new CatalogDictionaryTable28Definition(),
            'Page' => new PageObjectTable30Definition(),
            default => null,
        };
        foreach ($dictionary->entries() as $entry) {
            // Direct objects and all array elements are visible. Only an optional
            // indirect key can be hidden. Unknown/conditional contracts are kept
            // visible; page content/resources and complete fonts are conservative.
            if (!$entry->value instanceof IndirectReference || in_array($type, ['Font', 'FontDescriptor'], true)
                || ($type === 'Page' && in_array($entry->key->value, ['Resources', 'Contents'], true))
                || $this->required($definition, $entry->key)) {
                $this->visit($entry->value);
            }
        }
    }

    private function required(?TableDefinition $definition, NameType $name): bool
    {
        if ($definition === null || !$definition->has($name)) {
            return true;
        }
        $requirement = $definition->entry($name)->requirement;
        return $requirement->required || $requirement->conditional;
    }
}
