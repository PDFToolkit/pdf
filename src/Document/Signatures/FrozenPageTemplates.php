<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Bounded named-template lookup in the actual frozen catalog, without external template loading. */
final readonly class FrozenPageTemplates
{
    /** @var array<string, RevisionObject> */
    private array $templates;

    public function __construct(FrozenSignatureGraph $graph)
    {
        $names = $graph->resolve($graph->catalog->get('Names'));
        $root = $names instanceof DictionaryType ? $names->get('Templates') : null;
        $pending = $root === null ? [] : [$root];
        $seen = [];
        $templates = [];
        $previous = null;
        $nodes = 0;
        while ($pending !== []) {
            $entry = array_shift($pending);
            if (++$nodes > 4096) {
                throw new \LengthException('Protected template names exceed their node limit.');
            }
            if ($entry instanceof IndirectReference) {
                $id = (string) $graph->record($entry)->identifier;
                if (isset($seen[$id])) {
                    throw new \LogicException('Template name trees have unique node ownership.');
                }
                $seen[$id] = true;
            }
            $node = $graph->resolve($entry);
            if (!$node instanceof DictionaryType) {
                throw new \LogicException('A template name-tree node is a dictionary.');
            }
            $kids = $graph->resolve($node->get('Kids'));
            $pairs = $graph->resolve($node->get('Names'));
            if ($kids instanceof ArrayType && $pairs === null) {
                if (count($kids->items) > 4096 - $nodes - count($pending)) {
                    throw new \LengthException('Protected template names exceed their pending-node limit.');
                }
                array_unshift($pending, ...$kids->items);
                continue;
            }
            if (!$pairs instanceof ArrayType || $kids !== null || count($pairs->items) % 2 !== 0) {
                throw new \LogicException('Template leaves contain ordered name/value pairs.');
            }
            for ($index = 0; $index < count($pairs->items); $index += 2) {
                $key = $pairs->items[$index];
                $reference = $pairs->items[$index + 1];
                if (!$key instanceof StringType || !$reference instanceof IndirectReference
                    || ($previous !== null && strcmp($previous, $key->value) >= 0) || count($templates) >= 4096) {
                    throw new \LogicException('Template names are distinct ordered strings with indirect page identities.');
                }
                $record = $graph->record($reference);
                $type = $record->dictionary?->get('Type');
                if (!$type instanceof NameType || $type->value !== 'Template'
                    || $record->dictionary->has('Parent') || $record->dictionary->has('B')) {
                    throw new \LogicException('Protected spawning uses invisible Template pages without Parent or B.');
                }
                $previous = $key->value;
                $templates['name:' . $key->value] = $record;
            }
        }
        $this->templates = $templates;
    }

    public function named(NameType $name): RevisionObject
    {
        return $this->templates['name:' . $name->value] ?? throw new \OutOfBoundsException('The signed catalog has no template with this exact name.');
    }
}
