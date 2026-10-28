<?php

declare(strict_types=1);

namespace PDF\Document\RichMedia;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Local writer contracts for actual RichMedia dictionaries, ISO2 Tables334–343, pp716–726. */
final readonly class RichMediaDictionaryRules
{
    public function validate(DictionaryType $dictionary, string $kind, DocumentGraphContext $graph): void
    {
        $type = $this->value($dictionary, 'Type', $graph);
        if ($type !== null && (!$type instanceof NameType || $type->value !== 'RichMedia' . $kind)) {
            throw new \InvalidArgumentException('RichMedia ' . $kind . ' has an invalid Type.');
        }
        if (in_array($kind, ['Configuration', 'Instance'], true)) {
            $this->name($dictionary, 'Subtype', ['3D', 'Sound', 'Video'], $graph, $kind === 'Instance');
        }
        if ($kind === 'Configuration') {
            $name = $this->value($dictionary, 'Name', $graph);
            if ($name !== null && !$name instanceof StringType) {
                throw new \InvalidArgumentException('RichMedia configuration Name must be a text string.');
            }
        }
        if ($kind === 'Activation') {
            $this->name($dictionary, 'Condition', ['XA', 'PO', 'PV'], $graph);
        }
        if ($kind === 'Deactivation') {
            $this->name($dictionary, 'Condition', ['XD', 'PC', 'PI'], $graph);
        }
        if ($kind === 'Animation') {
            // An unknown named style has the explicit None fallback, Table337.
            $style = $this->value($dictionary, 'Subtype', $graph);
            $count = $this->value($dictionary, 'PlayCount', $graph);
            $speed = $this->value($dictionary, 'Speed', $graph);
            if (($style !== null && !$style instanceof NameType) || ($count !== null && !$count instanceof IntegerType)
                || ($speed !== null && (!$speed instanceof NumericType || $speed->value <= 0))) {
                throw new \InvalidArgumentException('RichMedia animation requires a name, integer PlayCount and positive numeric Speed.');
            }
        }
        if ($kind === 'Presentation') {
            $this->name($dictionary, 'Style', ['Embedded', 'Windowed'], $graph);
            foreach (['Transparent', 'NavigationPane', 'Toolbar', 'PassContextClick'] as $key) {
                $value = $this->value($dictionary, $key, $graph);
                if ($value !== null && !$value instanceof BooleanType) {
                    throw new \InvalidArgumentException('RichMedia presentation ' . $key . ' must be boolean.');
                }
            }
        }
        if ($kind === 'Position') {
            foreach (['HAlign', 'VAlign'] as $key) {
                $this->name($dictionary, $key, ['Near', 'Center', 'Far'], $graph);
            }
            foreach (['HOffset', 'VOffset'] as $key) {
                $value = $this->value($dictionary, $key, $graph);
                if ($value !== null && !$value instanceof NumericType) {
                    throw new \InvalidArgumentException('RichMedia position offsets must be numbers.');
                }
            }
        }
        if ($kind === 'Window') {
            foreach (['Width' => [288, 72, 576], 'Height' => [216, 72, 432]] as $key => $defaults) {
                $dimension = $this->child($dictionary, $key, $graph);
                if ($dimension === null) {
                    continue;
                }
                $numbers = [];
                foreach (['Default', 'Min', 'Max'] as $index => $name) {
                    $number = $this->value($dimension, $name, $graph) ?? new NumericType($defaults[$index]);
                    if (!$number instanceof NumericType) {
                        throw new \InvalidArgumentException('RichMedia window dimensions require numeric Default/Min/Max.');
                    }
                    $numbers[] = $number;
                }
                new WindowDimension(...$numbers);
            }
        }
        $children = match ($kind) {
            'Settings' => ['Activation', 'Deactivation'],
            'Activation' => ['Animation', 'Presentation'],
            'Presentation' => ['Window'],
            'Window' => ['Position'],
            default => [],
        };
        foreach ($children as $childKind) {
            $child = $this->child($dictionary, $childKind, $graph);
            if ($child !== null) {
                $this->validate($child, $childKind, $graph);
            }
        }
    }

    /** @param non-empty-list<string> $values */
    private function name(DictionaryType $dictionary, string $key, array $values, DocumentGraphContext $graph, bool $required = false): void
    {
        $value = $this->value($dictionary, $key, $graph);
        if (($value === null && $required) || ($value !== null && (!$value instanceof NameType || !in_array($value->value, $values, true)))) {
            throw new \InvalidArgumentException('RichMedia ' . $key . ' requires one of: ' . implode(', ', $values) . '.');
        }
    }

    private function child(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?DictionaryType
    {
        $entry = $graph->entry($dictionary, $key);
        if ($entry === null) {
            return null;
        }
        $child = $graph->dictionary($entry);
        if ($child === null || $graph->resolve($entry) instanceof StreamObject) {
            throw new \InvalidArgumentException('RichMedia ' . $key . ' must be a dictionary, not a stream or scalar.');
        }
        return $child;
    }

    private function value(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?BasicType
    {
        $entry = $graph->entry($dictionary, $key);
        return $entry === null ? null : $graph->resolve($entry);
    }
}
