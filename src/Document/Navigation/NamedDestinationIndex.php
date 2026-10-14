<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** One immutable lookup of the actual document namespaces, preserving every byte key. */
final readonly class NamedDestinationIndex
{
    /** @var array<string, ArrayType> */
    private array $names;
    /** @var array<string, ArrayType> */
    private array $strings;
    /** @var array<string, ArrayType> */
    private array $structureNames;
    /** @var array<string, ArrayType> */
    private array $structureStrings;
    public bool $hasStructure;
    public bool $hasLegacy;
    public bool $hasNameTree;

    public function __construct(DocumentGraphContext $graph, NavigationPageIndex $pages, NavigationLimits $limits = new NavigationLimits())
    {
        if ($pages->graph !== $graph) {
            throw new \InvalidArgumentException('The destination and page indices belong to the same explicit document context.');
        }
        $names = [];
        $strings = [];
        $structureNames = [];
        $structureStrings = [];
        $structureResolver = null;
        $legacy = $graph->entry($graph->catalog, 'Dests');
        $this->hasLegacy = $legacy !== null;
        if ($legacy !== null) {
            if (!$legacy instanceof IndirectReference || ($dictionary = $graph->dictionary($legacy)) === null) {
                throw new \InvalidArgumentException('Effective catalogue Dests must be an indirect dictionary.');
            }
            foreach ($dictionary->entries() as $entry) {
                if (!$graph->resolve($entry->value) instanceof NullType) {
                    $names[bin2hex($entry->key->value)] = $this->explicit($graph, $pages, $entry->value);
                    $sd = $this->structureValue($graph, $entry->value, $structureResolver, $limits);
                    if ($sd !== null) {
                        $structureNames[bin2hex($entry->key->value)] = $sd;
                    }
                }
                if (count($names) > $limits->maxNodes) {
                    throw new \LengthException('The destination mapping exceeds its explicit construction budget.');
                }
            }
        }
        $namesValue = $graph->entry($graph->catalog, 'Names');
        $dictionary = $namesValue === null ? null : $graph->dictionary($namesValue);
        $tree = $dictionary === null ? null : $graph->entry($dictionary, 'Dests');
        $this->hasNameTree = $tree !== null;
        if ($tree !== null) {
            foreach ($graph->nameTree($tree) as $key => $value) {
                $strings[$key] = $this->explicit($graph, $pages, $value);
                $sd = $this->structureValue($graph, $value, $structureResolver, $limits);
                if ($sd !== null) {
                    $structureStrings[$key] = $sd;
                }
                if (count($names) + count($strings) > $limits->maxNodes) {
                    throw new \LengthException('The destination mapping exceeds its explicit construction budget.');
                }
            }
        }
        $this->names = $names;
        $this->strings = $strings;
        $this->structureNames = $structureNames;
        $this->structureStrings = $structureStrings;
        $this->hasStructure = $structureNames !== [] || $structureStrings !== [];
    }

    public function find(NameType|StringType $name): ArrayType
    {
        $mapping = $name instanceof NameType ? $this->names : $this->strings;
        return $mapping[bin2hex($name->value)] ?? throw new \InvalidArgumentException('The named destination is absent from its effective catalogue namespace.');
    }

    public function structure(NameType|StringType $name): ?ArrayType
    {
        $this->find($name);
        $mapping = $name instanceof NameType ? $this->structureNames : $this->structureStrings;
        return $mapping[bin2hex($name->value)] ?? null;
    }

    private function structureValue(DocumentGraphContext $graph, BasicType $value, ?StructureDestinationResolver &$resolver, NavigationLimits $limits): ?ArrayType
    {
        $dictionary = $graph->dictionary($value);
        $sd = $dictionary === null ? null : $graph->entry($dictionary, 'SD');
        if ($sd === null) {
            return null;
        }
        $sd = $graph->resolve($sd);
        if (!$sd instanceof ArrayType) {
            throw new \InvalidArgumentException('Named destination SD requires a structure destination array.');
        }
        ($resolver ??= new StructureDestinationResolver($graph, $limits))->page($sd);
        return $sd;
    }

    private function explicit(DocumentGraphContext $graph, NavigationPageIndex $pages, BasicType $value): ArrayType
    {
        $value = $graph->resolve($value);
        if ($value instanceof DictionaryType) {
            $target = $graph->entry($value, 'D');
            $value = $target === null ? null : $graph->resolve($target);
        }
        if (!$value instanceof ArrayType) {
            throw new \InvalidArgumentException('A named destination value contains an explicit array or a dictionary D array.');
        }
        $parameters = new ExplicitDestinationParameters($value);
        $pages->index($parameters->reference);
        return $value;
    }
}
