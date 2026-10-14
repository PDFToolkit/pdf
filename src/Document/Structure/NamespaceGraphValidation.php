<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Effective/frozen PDF 2.0 namespace facts, including generic dictionary entry escape hatches. */
final readonly class NamespaceGraphValidation
{
    public function __construct(private int $maximumValues = 1000000)
    {
        if ($maximumValues < 1) {
            throw new \InvalidArgumentException('Namespace validation requires a positive graph budget.');
        }
    }

    public function validate(DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $rootValue = $graph->entry($graph->catalog, 'StructTreeRoot');
        $root = $rootValue === null ? null : $graph->dictionary($rootValue);
        $declared = $root === null ? null : $this->resolved($graph->entry($root, 'Namespaces'), $graph);
        $pending = [$graph->catalog];
        $seen = [];
        while ($pending !== []) {
            $value = array_pop($pending);
            $identifier = $graph->identifier($value);
            $key = $identifier === null ? 'direct:' . spl_object_id($value) : (string) $identifier;
            if (isset($seen[$key])) {
                continue;
            }
            if (count($seen) >= $this->maximumValues) {
                throw new \OverflowException('Namespace validation exceeds its explicit graph budget.');
            }
            $seen[$key] = true;
            $dictionary = $graph->dictionary($value);
            if ($dictionary === null) {
                $resolved = $graph->resolve($value);
                if ($resolved instanceof ArrayType) {
                    array_push($pending, ...$resolved->items);
                }
                continue;
            }
            $type = $this->resolved($graph->entry($dictionary, 'Type'), $graph);
            $namespace = $graph->entry($dictionary, 'NS');
            $namespaceName = $this->resolved($namespace, $graph);
            $isNamespace = ($type instanceof NameType && $type->value === 'Namespace') || $namespaceName instanceof StringType;
            if ($isNamespace) {
                $version->require(PdfVersion::PDF20, 'Structure namespaces');
                $name = $this->resolved($graph->entry($dictionary, 'NS'), $graph);
                if (!$name instanceof StringType || $name->value === '') {
                    throw new \InvalidArgumentException('A namespace dictionary requires a nonempty text-string NS identifier.');
                }
                $this->roleMap($dictionary, $graph);
            }
            if ($namespace !== null && !$isNamespace) {
                $version->require(PdfVersion::PDF20, 'Namespaced structure elements and attributes');
                $this->namespaceReference($namespace, $graph);
                if (($type instanceof NameType && $type->value === 'StructElem') || $graph->entry($dictionary, 'P') instanceof IndirectReference) {
                    if (!$declared instanceof ArrayType || array_filter($declared->items, static fn (BasicType $candidate): bool => $graph->sameObject($candidate, $namespace)) === []) {
                        throw new \InvalidArgumentException('Every structure-element namespace must be listed in StructureTreeRoot.Namespaces.');
                    }
                }
                if ($graph->entry($dictionary, 'O') !== null) {
                    throw new \InvalidArgumentException('An attribute object must identify its owner using O or NS, not both.');
                }
            }
            if ($type instanceof NameType && $type->value === 'StructTreeRoot') {
                $namespaces = $this->resolved($graph->entry($dictionary, 'Namespaces'), $graph);
                if ($namespaces !== null) {
                    $version->require(PdfVersion::PDF20, 'StructureTreeRoot.Namespaces');
                    if (!$namespaces instanceof ArrayType) {
                        throw new \InvalidArgumentException('StructureTreeRoot.Namespaces must be an array.');
                    }
                    foreach ($namespaces as $namespaceValue) {
                        $this->namespaceReference($namespaceValue, $graph);
                    }
                }
            }
            if ($type instanceof NameType && $type->value === 'StructElem' && $graph->entry($dictionary, 'Ref') !== null) {
                $version->require(PdfVersion::PDF20, 'Structure-element Ref associations');
                $references = $this->resolved($graph->entry($dictionary, 'Ref'), $graph);
                if (!$references instanceof ArrayType) {
                    throw new \InvalidArgumentException('A structure-element Ref entry must be an array.');
                }
                foreach ($references as $reference) {
                    $target = $graph->dictionary($reference);
                    $role = $target === null ? null : $this->resolved($graph->entry($target, 'S'), $graph);
                    if (!$reference instanceof IndirectReference || !$role instanceof NameType || !$graph->entry($target, 'P') instanceof IndirectReference) {
                        throw new \InvalidArgumentException('A structure-element Ref target must be an indirect structure-element dictionary.');
                    }
                }
            }
            foreach ($dictionary->entries() as $entry) {
                $pending[] = $entry->value;
            }
        }
    }

    private function namespaceReference(BasicType $reference, DocumentGraphContext $graph): void
    {
        $dictionary = $graph->dictionary($reference);
        $name = $dictionary === null ? null : $this->resolved($graph->entry($dictionary, 'NS'), $graph);
        $type = $dictionary === null ? null : $this->resolved($graph->entry($dictionary, 'Type'), $graph);
        if (!$reference instanceof IndirectReference || !$name instanceof StringType || $name->value === '' || ($type !== null && (!$type instanceof NameType || $type->value !== 'Namespace'))) {
            throw new \InvalidArgumentException('A namespace reference must indirectly identify a namespace dictionary with a text-string NS.');
        }
        $this->roleMap($dictionary, $graph);
    }

    private function roleMap(DictionaryType $namespace, DocumentGraphContext $graph): void
    {
        $mapValue = $graph->entry($namespace, 'RoleMapNS');
        if ($mapValue === null) {
            return;
        }
        $map = $graph->dictionary($mapValue);
        if ($map === null) {
            throw new \InvalidArgumentException('Namespace RoleMapNS must be a dictionary.');
        }
        foreach ($map->entries() as $entry) {
            $target = $graph->resolve($entry->value);
            if ($target instanceof NameType) {
                continue;
            }
            if (!$target instanceof ArrayType || count($target) !== 2 || !$graph->resolve($target->items[0]) instanceof NameType || !$target->items[1] instanceof IndirectReference) {
                throw new \InvalidArgumentException('A RoleMapNS target must be a default name or [name indirect-namespace].');
            }
            $targetDictionary = $graph->dictionary($target->items[1]);
            $name = $targetDictionary === null ? null : $this->resolved($graph->entry($targetDictionary, 'NS'), $graph);
            if (!$name instanceof StringType || $name->value === '') {
                throw new \InvalidArgumentException('A RoleMapNS target must reference a namespace dictionary.');
            }
        }
    }

    private function resolved(?BasicType $value, DocumentGraphContext $graph): ?BasicType
    {
        return $value === null ? null : $graph->resolve($value);
    }
}
