<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Active outline links and presentation attributes: ISO1 pp376–377; ISO2 pp460–461. */
final readonly class OutlineGraphValidation
{
    public function __construct(private NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function validate(DocumentGraphContext $graph, ?EffectivePdfVersion $version = null): void
    {
        $root = $graph->entry($graph->catalog, 'Outlines');
        if ($root === null) {
            return;
        }
        $dictionary = $this->dictionary($root, $graph);
        $type = $this->value($dictionary, 'Type', $graph);
        if ($type !== null && (!$type instanceof NameType || $type->value !== 'Outlines')) {
            throw new \InvalidArgumentException('An outline root Type, when present, must be Outlines.');
        }
        $seen = [];
        [$visible, $hasOpenChildren] = $this->children($root, $dictionary, $graph, $version, 0, $seen);
        $count = $this->value($dictionary, 'Count', $graph);
        if (($count !== null && (!$count instanceof IntegerType || $count->integer() <= 0 || $count->integer() !== $visible))
            || ($count === null && $hasOpenChildren)) {
            throw new \InvalidArgumentException('An outline root Count must equal its visible items and is required when descendant branches are open.');
        }
    }

    /**
     * @param array<string,true> $seen
     * @return array{int,bool}
     */
    private function children(BasicType $parent, DictionaryType $dictionary, DocumentGraphContext $graph, ?EffectivePdfVersion $version, int $depth, array &$seen): array
    {
        $first = $graph->entry($dictionary, 'First');
        $last = $graph->entry($dictionary, 'Last');
        if ($first === null && $last === null) {
            return [0, false];
        }
        if (!$first instanceof IndirectReference || !$last instanceof IndirectReference) {
            throw new \InvalidArgumentException('An outline parent requires paired indirect First and Last references.');
        }
        if ($depth >= $this->limits->maxDepth) {
            throw new \LengthException('The active outline exceeds its explicit depth budget.');
        }
        $cursor = $first;
        $previous = null;
        $visible = 0;
        $hasOpen = false;
        while (true) {
            $identity = (string) ($graph->identifier($cursor) ?? throw new \LogicException('Outline items are indirect.'));
            if (isset($seen[$identity])) {
                throw new \InvalidArgumentException('An active outline cannot cycle or share an item between parents.');
            }
            if (count($seen) >= $this->limits->maxNodes) {
                throw new \LengthException('The active outline exceeds its explicit item budget.');
            }
            $seen[$identity] = true;
            $item = $this->dictionary($cursor, $graph);
            $owner = $graph->entry($item, 'Parent');
            $back = $graph->entry($item, 'Prev');
            if (!$owner instanceof IndirectReference || !$graph->sameObject($owner, $parent)
                || ($previous === null ? $back !== null : (!$back instanceof IndirectReference || !$graph->sameObject($back, $previous)))) {
                throw new \InvalidArgumentException('Outline Parent and Prev references must match their actual hierarchy and sibling order.');
            }
            $this->attributes($item, $graph, $version);
            [$descendants] = $this->children($cursor, $item, $graph, $version, $depth + 1, $seen);
            $count = $this->value($item, 'Count', $graph);
            if (($count !== null && (!$count instanceof IntegerType || abs($count->integer()) !== $descendants))
                || ($count === null && $descendants !== 0)) {
                throw new \InvalidArgumentException('An outline item Count must encode the signed number of descendants visible when opened.');
            }
            $open = $count instanceof IntegerType && $count->integer() > 0;
            $visible += 1 + ($open ? $descendants : 0);
            $hasOpen = $hasOpen || $open;
            $next = $graph->entry($item, 'Next');
            if ($next === null) {
                if (!$graph->sameObject($cursor, $last)) {
                    throw new \InvalidArgumentException('An outline Last reference must identify the final sibling.');
                }
                return [$visible, $hasOpen];
            }
            if (!$next instanceof IndirectReference) {
                throw new \InvalidArgumentException('An outline Next entry must be an indirect item reference.');
            }
            $previous = $cursor;
            $cursor = $next;
        }
    }

    private function attributes(DictionaryType $item, DocumentGraphContext $graph, ?EffectivePdfVersion $version): void
    {
        if (!$this->value($item, 'Title', $graph) instanceof StringType) {
            throw new \InvalidArgumentException('An outline item requires a Title text string.');
        }
        $destination = $graph->entry($item, 'Dest');
        $action = $graph->entry($item, 'A');
        if ($destination !== null && $action !== null) {
            throw new \InvalidArgumentException('Outline Dest and A entries are mutually exclusive.');
        }
        if ($action !== null) {
            $version?->require(PdfVersion::PDF11, 'Outline action');
            $this->dictionary($action, $graph);
        }
        $structure = $graph->entry($item, 'SE');
        if ($structure !== null) {
            $version?->require(PdfVersion::PDF13, 'Outline structure element');
            if (!$structure instanceof IndirectReference) {
                throw new \InvalidArgumentException('Outline SE must reference its structure element indirectly.');
            }
            $this->dictionary($structure, $graph);
        }
        $colour = $this->value($item, 'C', $graph);
        if ($colour !== null) {
            $version?->require(PdfVersion::PDF14, 'Outline text colour');
            if (!$colour instanceof ArrayType || count($colour) !== 3) {
                throw new \InvalidArgumentException('Outline C requires three DeviceRGB numbers in the range zero to one.');
            }
            foreach ($colour as $component) {
                $number = $graph->resolve($component);
                if (!$number instanceof NumericType || $number->value < 0 || $number->value > 1) {
                    throw new \InvalidArgumentException('Outline C requires three DeviceRGB numbers in the range zero to one.');
                }
            }
        }
        $flags = $this->value($item, 'F', $graph);
        if ($flags !== null) {
            $version?->require(PdfVersion::PDF14, 'Outline text style');
            if (!$flags instanceof IntegerType || $flags->integer() < 0 || $flags->integer() > 3) {
                throw new \InvalidArgumentException('Outline F permits only the integer italic and bold bits.');
            }
        }
    }

    private function dictionary(BasicType $value, DocumentGraphContext $graph): DictionaryType
    {
        $dictionary = $graph->dictionary($value);
        if ($dictionary === null || $graph->resolve($value) instanceof StreamObject) {
            throw new \InvalidArgumentException('An outline relationship requires a dictionary, not a stream or scalar.');
        }
        return $dictionary;
    }

    private function value(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?BasicType
    {
        $value = $graph->entry($dictionary, $key);
        return $value === null ? null : $graph->resolve($value);
    }
}
