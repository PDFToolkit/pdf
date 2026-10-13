<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Document\DocumentGraphContext;
use PDF\Document\Files\FileSpecificationRules;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** Portable collections are validated against the effective catalog, including frozen historical CI values. */
final readonly class CollectionGraphValidation
{
    public function validate(DocumentGraphContext $graph, ?EffectivePdfVersion $version = null): void
    {
        $root = $graph->entry($graph->catalog, 'Collection');
        if ($root === null) {
            return;
        }
        $collection = $graph->dictionary($root) ?? throw new \InvalidArgumentException('Catalog.Collection must resolve to a dictionary.');
        $version?->require(PdfVersion::PDF17, 'Portable collections');
        $this->type($collection, 'Collection', $graph);
        $view = $graph->entry($collection, 'View');
        if ($view !== null && (!($view = $graph->resolve($view)) instanceof NameType || CollectionView::tryFrom($view->value) === null)) {
            throw new \InvalidArgumentException('Collection.View must be D, T or H.');
        }
        $initial = $graph->entry($collection, 'D');
        if ($initial !== null && !$graph->resolve($initial) instanceof StringType) {
            throw new \InvalidArgumentException('Collection.D must be an embedded-file name-tree string key.');
        }
        $schemaValue = $graph->entry($collection, 'Schema');
        $schema = $schemaValue === null ? null : $graph->dictionary($schemaValue);
        if ($schemaValue !== null && $schema === null) {
            throw new \InvalidArgumentException('Collection.Schema must resolve to a dictionary.');
        }
        if ($schema !== null) {
            $this->type($schema, 'CollectionSchema', $graph);
            foreach ($schema->entries() as $entry) {
                if ($entry->key->value === 'Type' || $graph->entry($schema, $entry->key) === null) {
                    continue;
                }
                $field = $graph->dictionary($entry->value) ?? throw new \InvalidArgumentException('Collection schema entries require field dictionaries.');
                $this->type($field, 'CollectionField', $graph);
                $subtype = $graph->entry($field, 'Subtype');
                $subtype = $subtype === null ? null : $graph->resolve($subtype);
                $label = $graph->entry($field, 'N');
                if (!$subtype instanceof NameType || CollectionFieldType::tryFrom($subtype->value) === null || $label === null || !$graph->resolve($label) instanceof StringType) {
                    throw new \InvalidArgumentException('A collection field requires a supported Subtype and a display-name string N.');
                }
                foreach (['O' => IntegerType::class, 'V' => BooleanType::class, 'E' => BooleanType::class] as $key => $class) {
                    $value = $graph->entry($field, $key);
                    if ($value !== null && !$graph->resolve($value) instanceof $class) {
                        throw new \InvalidArgumentException('A collection field has an invalid presentation value ' . $key . '.');
                    }
                }
            }
        }
        $sortValue = $graph->entry($collection, 'Sort');
        if ($sortValue !== null) {
            $sort = $graph->dictionary($sortValue) ?? throw new \InvalidArgumentException('Collection.Sort must resolve to a dictionary.');
            $this->type($sort, 'CollectionSort', $graph);
            $fields = $graph->entry($sort, 'S');
            $fields = $fields === null ? null : $graph->resolve($fields);
            $names = $fields instanceof NameType ? [$fields] : ($fields instanceof ArrayType ? $fields->items : null);
            if ($names === null) {
                throw new \InvalidArgumentException('Collection.Sort.S requires a name or an array of names.');
            }
            foreach ($names as $name) {
                $name = $graph->resolve($name);
                if (!$name instanceof NameType || ($schema !== null && ($name->value === 'Type' || $graph->entry($schema, $name) === null))) {
                    throw new \InvalidArgumentException('Sort fields must be names described by the actual collection schema.');
                }
            }
            $directions = $graph->entry($sort, 'A');
            $directions = $directions === null ? null : $graph->resolve($directions);
            if ($directions !== null && !$directions instanceof BooleanType) {
                if (!$directions instanceof ArrayType) {
                    throw new \InvalidArgumentException('Collection.Sort.A requires a boolean or an array of booleans.');
                }
                foreach ($directions as $direction) {
                    if (!$graph->resolve($direction) instanceof BooleanType) {
                        throw new \InvalidArgumentException('Every collection-sort direction must be a boolean.');
                    }
                }
            }
        }
        $namesValue = $graph->entry($graph->catalog, 'Names');
        $nameDictionary = $namesValue === null ? null : $graph->dictionary($namesValue);
        $embedded = $nameDictionary === null ? null : $graph->entry($nameDictionary, 'EmbeddedFiles');
        if ($embedded === null) {
            return;
        }
        foreach ($graph->nameTree($embedded) as $value) {
            $file = $graph->dictionary($value);
            if (!$value instanceof IndirectReference || $file === null || $graph->entry($file, 'EF') === null) {
                throw new \InvalidArgumentException('A collection consists only of indirect file specifications in the actual EmbeddedFiles tree.');
            }
            (new FileSpecificationRules())->validate($file, $graph);
            $ci = $graph->entry($file, 'CI');
            if ($ci !== null) {
                $item = $graph->dictionary($ci);
                if (!$ci instanceof IndirectReference || $item === null) {
                    throw new \InvalidArgumentException('File specification CI must be an indirect collection-item dictionary.');
                }
                $this->type($item, 'CollectionItem', $graph);
                if ($schema !== null) {
                    $this->validateItem($item, $schema, $graph);
                }
            }
        }
    }

    private function validateItem(DictionaryType $item, DictionaryType $schema, DocumentGraphContext $graph): void
    {
        foreach ($item->entries() as $entry) {
            if ($entry->key->value === 'Type' || $graph->entry($item, $entry->key) === null) {
                continue;
            }
            $fieldValue = $graph->entry($schema, $entry->key);
            $field = $fieldValue === null ? null : $graph->dictionary($fieldValue);
            $subtype = $field === null ? null : $graph->entry($field, 'Subtype');
            $subtype = $subtype === null ? null : $graph->resolve($subtype);
            $type = $subtype instanceof NameType ? CollectionFieldType::tryFrom($subtype->value) : null;
            if ($type === null) {
                throw new \InvalidArgumentException('Every collection-item field must be described by the actual collection schema.');
            }
            $data = $graph->resolve($entry->value);
            if ($data instanceof DictionaryType) {
                $this->type($data, 'CollectionSubitem', $graph);
                $prefix = $graph->entry($data, 'P');
                if ($prefix !== null && !$graph->resolve($prefix) instanceof StringType) {
                    throw new \InvalidArgumentException('A collection-subitem prefix P must be a text string.');
                }
                $data = $graph->entry($data, 'D');
                if ($data === null) {
                    continue; // Table49 explicitly permits absent D; no value is invented from its display prefix.
                }
                $data = $graph->resolve($data);
            }
            if (!$this->matches($data, $type)) {
                throw new \InvalidArgumentException('An effective collection-item value has the wrong type for the actual collection schema.');
            }
        }
    }

    private function type(DictionaryType $dictionary, string $name, DocumentGraphContext $graph): void
    {
        $type = $graph->entry($dictionary, 'Type');
        if ($type !== null && (!($type = $graph->resolve($type)) instanceof NameType || $type->value !== $name)) {
            throw new \InvalidArgumentException('A native collection dictionary has an invalid Type, expected ' . $name . '.');
        }
    }

    private function matches(BasicType $value, CollectionFieldType $type): bool
    {
        if (in_array($type, [CollectionFieldType::Number, CollectionFieldType::FileSize], true)) {
            return $value instanceof NumericType;
        }
        if (!$value instanceof StringType) {
            return false;
        }
        if (in_array($type, [CollectionFieldType::Date, CollectionFieldType::CreationDate, CollectionFieldType::ModificationDate], true)) {
            // Native Date values remain typed; generic values must at least carry the specified date syntax.
            $text = $value->value;
            if (str_starts_with($text, "\xfe\xff")) {
                if (strlen($text) % 2 !== 0) {
                    return false;
                }
                $ascii = '';
                for ($i = 2; $i < strlen($text); $i += 2) {
                    if ($text[$i] !== "\0") {
                        return false;
                    }
                    $ascii .= $text[$i + 1];
                }
                $text = $ascii;
            }
            return preg_match("/\\AD:[0-9]{4}(?:(?:0[1-9]|1[0-2])(?:(?:0[1-9]|[12][0-9]|3[01])(?:(?:[01][0-9]|2[0-3])(?:[0-5][0-9](?:[0-5][0-9])?)?)?)?)?(?:Z|[+-](?:[01][0-9]|2[0-3])(?:'(?:[0-5][0-9])?'?)?)?\\z/D", $text) === 1;
        }
        return true;
    }
}
