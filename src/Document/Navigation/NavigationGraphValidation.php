<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** Active catalogue relationships, also when every native declaration has become a snapshot. */
final readonly class NavigationGraphValidation
{
    public function __construct(private NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function validate(DocumentGraphContext $graph, ?EffectivePdfVersion $version = null): void
    {
        $pages = new NavigationPageIndex($graph, $this->limits);
        $destinations = new NamedDestinationIndex($graph, $pages, $this->limits);
        if ($destinations->hasLegacy) {
            $version?->require(PdfVersion::PDF11, 'Legacy destinations');
        }
        if ($destinations->hasNameTree) {
            $version?->require(PdfVersion::PDF12, 'Named destinations name tree');
        }
        if ($destinations->hasStructure) {
            $version?->require(PdfVersion::PDF20, 'Named structure destinations');
        }
        $this->labels($graph, $pages, $version);
        $this->articles($graph, $pages, $version);
        $this->presentation($graph, $pages, $destinations, $version);
        $this->declaredDestinations($graph, $pages, $destinations, $version);
    }

    public function destination(DocumentGraphContext $graph, ArrayType $destination): void
    {
        $this->destinationArray(new NavigationPageIndex($graph, $this->limits), $destination);
    }

    public function named(DocumentGraphContext $graph, NameType|StringType $name): ArrayType
    {
        return (new NamedDestinationIndex($graph, new NavigationPageIndex($graph, $this->limits), $this->limits))->find($name);
    }

    private function destinationArray(NavigationPageIndex $pages, ArrayType $value, ?EffectivePdfVersion $version = null): void
    {
        $parameters = new ExplicitDestinationParameters($value);
        $pages->index($parameters->reference);
        if (in_array($parameters->fit->value, ['FitB', 'FitBH', 'FitBV'], true)) {
            $version?->require(PdfVersion::PDF11, 'Bounding-box destinations');
        }
    }

    private function labels(DocumentGraphContext $graph, NavigationPageIndex $pages, ?EffectivePdfVersion $version): void
    {
        $root = $graph->entry($graph->catalog, 'PageLabels');
        if ($root === null) {
            return;
        }
        $version?->require(PdfVersion::PDF13, 'Page labels');
        $seen = [];
        $ranges = [];
        $this->labelNode($graph, $pages, $root, 0, $seen, $ranges);
        if (!isset($ranges[0])) {
            throw new \InvalidArgumentException('The effective PageLabels must define index zero.');
        }
    }

    /**
     * @param array<string, bool> $seen
     * @param array<int, bool> $ranges
     * @return array{int, int}
     */
    private function labelNode(DocumentGraphContext $graph, NavigationPageIndex $pages, BasicType $reference, int $depth, array &$seen, array &$ranges): array
    {
        $key = $pages->key($reference);
        if (isset($seen[$key]) || count($seen) >= $this->limits->maxNodes || $depth > $this->limits->maxDepth) {
            throw new \InvalidArgumentException('Effective PageLabels repeats a node or exceeds its budget.');
        }
        $seen[$key] = true;
        $dictionary = $graph->dictionary($reference);
        if ($dictionary === null) {
            throw new \InvalidArgumentException('A PageLabels number-tree node is a dictionary.');
        }
        $numsValue = $graph->entry($dictionary, 'Nums');
        $kidsValue = $graph->entry($dictionary, 'Kids');
        if (($numsValue === null) === ($kidsValue === null)) {
            throw new \InvalidArgumentException('PageLabels nodes have exactly one of Nums or Kids.');
        }
        $minimum = null;
        $maximum = -1;
        if ($numsValue !== null) {
            $nums = $graph->resolve($numsValue);
            if (!$nums instanceof ArrayType || count($nums) === 0 || count($nums) % 2 !== 0) {
                throw new \InvalidArgumentException('PageLabels Nums contains nonempty index/dictionary pairs.');
            }
            for ($i = 0; $i < count($nums); $i += 2) {
                $index = $nums->items[$i];
                if (!$index instanceof IntegerType || $index->integer() <= $maximum || $index->integer() >= count($pages->pages) || isset($ranges[$index->integer()])) {
                    throw new \InvalidArgumentException('Page label indices are unique, increasing, nonnegative actual page indices.');
                }
                $label = $graph->dictionary($nums->items[$i + 1]);
                if ($label === null) {
                    throw new \InvalidArgumentException('A page label value must resolve to its dictionary.');
                }
                $style = $graph->entry($label, 'S');
                $startValue = $graph->entry($label, 'St');
                $start = $startValue === null ? new IntegerType(1) : $graph->resolve($startValue);
                $prefixValue = $graph->entry($label, 'P');
                $prefix = $prefixValue === null ? null : $graph->resolve($prefixValue);
                $type = $graph->entry($label, 'Type');
                if (!$start instanceof IntegerType || $start->value < 1
                    || ($style !== null && (!$style instanceof NameType || LabelNumbering::tryFrom($style->value) === null))
                    || ($prefix !== null && !$prefix instanceof StringType)
                    || ($type !== null && (!$type instanceof NameType || $type->value !== 'PageLabel'))) {
                    throw new \InvalidArgumentException('Page label dictionaries have valid Type/S/P and positive integer St.');
                }
                $minimum ??= $index->integer();
                $maximum = $index->integer();
                $ranges[$maximum] = true;
            }
        } else {
            $kids = $graph->resolve($kidsValue ?? throw new \LogicException('Missing PageLabels Kids.'));
            if (!$kids instanceof ArrayType || count($kids) === 0) {
                throw new \InvalidArgumentException('A PageLabels branch has nonempty Kids.');
            }
            foreach ($kids->items as $kid) {
                if (!$kid instanceof IndirectReference) {
                    throw new \InvalidArgumentException('PageLabels children must be indirect references.');
                }
                [$low, $high] = $this->labelNode($graph, $pages, $kid, $depth + 1, $seen, $ranges);
                if ($low <= $maximum) {
                    throw new \InvalidArgumentException('Number-tree child limits are ordered and nonoverlapping.');
                }
                $minimum ??= $low;
                $maximum = $high;
            }
        }
        $boundsValue = $graph->entry($dictionary, 'Limits');
        $bounds = $boundsValue === null ? null : $graph->resolve($boundsValue);
        if ($depth === 0 && $bounds !== null) {
            throw new \InvalidArgumentException('Number-tree Limits occurs only in intermediate and leaf nodes, never the root.');
        }
        if ($depth !== 0 && (!$bounds instanceof ArrayType || count($bounds) !== 2
            || !$bounds->items[0] instanceof IntegerType || !$bounds->items[1] instanceof IntegerType
            || $bounds->items[0]->integer() !== $minimum || $bounds->items[1]->integer() !== $maximum)) {
            throw new \InvalidArgumentException('Nonroot number-tree nodes need accurate Limits.');
        }
        return [$minimum ?? throw new \LogicException('A label node must contain a range.'), $maximum];
    }

    private function articles(DocumentGraphContext $graph, NavigationPageIndex $pages, ?EffectivePdfVersion $version): void
    {
        $value = $graph->entry($graph->catalog, 'Threads');
        $all = [];
        $threadsSeen = [];
        if ($value !== null) {
            $version?->require(PdfVersion::PDF11, 'Article threads');
            if (!$value instanceof IndirectReference || !($threads = $graph->resolve($value)) instanceof ArrayType) {
                throw new \InvalidArgumentException('Effective catalogue Threads is an indirect array.');
            }
            foreach ($threads->items as $threadRef) {
                $threadKey = $pages->key($threadRef);
                $thread = $graph->dictionary($threadRef);
                $first = $thread?->get('F');
                if (!$threadRef instanceof IndirectReference || isset($threadsSeen[$threadKey]) || !$first instanceof IndirectReference) {
                    throw new \InvalidArgumentException('An article has one canonical thread identity and an indirect first bead.');
                }
                if ($thread === null) {
                    throw new \InvalidArgumentException('An article thread resolves to a dictionary.');
                }
                $this->objectType($graph, $thread, 'Thread');
                $information = $graph->entry($thread, 'I');
                if ($information !== null && $graph->dictionary($information) === null) {
                    throw new \InvalidArgumentException('Article information must resolve to a dictionary.');
                }
                $threadsSeen[$threadKey] = true;
                $cursor = $first;
                $firstKey = $pages->key($first);
                do {
                    $key = $pages->key($cursor);
                    if (isset($all[$key]) || count($all) >= $this->limits->maxNodes) {
                        throw new \InvalidArgumentException('Article rings repeat a bead or exceed their budget.');
                    }
                    $bead = $graph->dictionary($cursor);
                    if ($bead === null) {
                        throw new \InvalidArgumentException('An article bead resolves to a dictionary.');
                    }
                    $this->objectType($graph, $bead, 'Bead');
                    $next = $bead->get('N');
                    $previous = $bead->get('V');
                    $page = $bead->get('P');
                    $owner = $graph->entry($bead, 'T');
                    $rectangle = $bead->get('R');
                    if (!$next instanceof IndirectReference || !$previous instanceof IndirectReference || !$page instanceof IndirectReference || !$rectangle instanceof ArrayType || count($rectangle) !== 4) {
                        throw new \InvalidArgumentException('Effective beads require indirect N/V/P and four rectangle numbers.');
                    }
                    foreach ($rectangle->items as $coordinate) {
                        if (!$coordinate instanceof NumericType) {
                            throw new \InvalidArgumentException('Bead rectangles contain four numbers.');
                        }
                    }
                    if (($key === $firstKey && $owner === null) || ($owner !== null && (!$owner instanceof IndirectReference || !$graph->sameObject($owner, $threadRef)))) {
                        throw new \InvalidArgumentException('First bead T is required and any bead T must identify its actual thread.');
                    }
                    if ($key !== $firstKey && $owner !== null) {
                        $version?->require(PdfVersion::PDF12, 'Nonfirst bead T');
                    }
                    $back = $graph->dictionary($next)?->get('V');
                    $forward = $graph->dictionary($previous)?->get('N');
                    if ($back === null || $forward === null || !$graph->sameObject($back, $cursor) || !$graph->sameObject($forward, $cursor)) {
                        throw new \InvalidArgumentException('Article N/V must form a reciprocal circular list.');
                    }
                    $all[$key] = [$pages->index($page), 0];
                    $cursor = $next;
                } while ($pages->key($cursor) !== $firstKey);
            }
        }
        foreach ($pages->pages as $index => [$pageRef, $dictionary]) {
            $beadsValue = $graph->entry($dictionary, 'B');
            if ($beadsValue === null) {
                continue;
            }
            $beads = $graph->resolve($beadsValue);
            if (!$beads instanceof ArrayType) {
                throw new \InvalidArgumentException('An effective page B must be an array.');
            }
            foreach ($beads->items as $bead) {
                $key = $pages->key($bead);
                if (!$bead instanceof IndirectReference || !isset($all[$key]) || $all[$key][0] !== $index || ++$all[$key][1] !== 1) {
                    throw new \InvalidArgumentException('Page B must list each bead once, with its actual page P and registered article.');
                }
            }
        }
        foreach ($all as [$index, $count]) {
            if ($count !== 1) {
                throw new \InvalidArgumentException('Every active article bead must occur in its actual page B.');
            }
        }
    }

    private function presentation(DocumentGraphContext $graph, NavigationPageIndex $pages, NamedDestinationIndex $destinations, ?EffectivePdfVersion $version): void
    {
        $owners = [];
        foreach ($pages->pages as $index => [$pageRef, $page]) {
            $this->pagePresentation($graph, $page, $version);
            $primary = $graph->entry($page, 'PresSteps');
            if ($primary === null) {
                continue;
            }
            $version?->require(PdfVersion::PDF15, 'Sub-page presentation');
            $cursor = $primary;
            $backSeen = [];
            while (($node = $graph->dictionary($cursor)) !== null && ($previous = $graph->entry($node, 'Prev')) !== null) {
                $key = $pages->key($cursor);
                if (isset($backSeen[$key]) || count($backSeen) >= $this->limits->maxNodes) {
                    throw new \InvalidArgumentException('Presentation nodes form a finite list, not a circular ring.');
                }
                $backSeen[$key] = true;
                $cursor = $previous;
            }
            $previous = null;
            $visited = [];
            while ($cursor !== null) {
                $key = $pages->key($cursor);
                if (isset($visited[$key]) || isset($owners[$key]) || count($visited) >= $this->limits->maxNodes) {
                    throw new \InvalidArgumentException('Presentation nodes belong to one finite page list.');
                }
                $visited[$key] = true;
                $owners[$key] = $index;
                $node = $graph->dictionary($cursor);
                if ($node === null) {
                    throw new \InvalidArgumentException('An effective presentation node is a dictionary.');
                }
                $this->objectType($graph, $node, 'NavNode');
                $back = $graph->entry($node, 'Prev');
                if (($previous === null) !== ($back === null) || ($previous !== null && $back !== null && !$graph->sameObject($previous, $back))) {
                    throw new \InvalidArgumentException('Presentation Next/Prev links must be reciprocal.');
                }
                $next = $graph->entry($node, 'Next');
                $this->duration($graph, $node, 'Dur');
                foreach (['NA' => $next, 'PA' => $previous] as $trigger => $successor) {
                    $action = $graph->entry($node, $trigger);
                    if ($action !== null && $this->actionLeaves($graph, $pages, $destinations, $action, $index) && $successor !== null) {
                        throw new \InvalidArgumentException('An action leaving the page should not also continue its presentation list.');
                    }
                }
                $previous = $cursor;
                $cursor = $next;
            }
            if (!isset($visited[$pages->key($primary)])) {
                throw new \InvalidArgumentException('PresSteps must belong to the validated reciprocal list.');
            }
        }
    }

    private function duration(DocumentGraphContext $graph, DictionaryType $dictionary, string $key): ?PresentationDuration
    {
        $reference = $graph->entry($dictionary, $key);
        if ($reference === null) {
            return null;
        }
        $value = $graph->resolve($reference);
        if (!$value instanceof NumericType) {
            throw new \InvalidArgumentException('A presentation duration is a number of seconds.');
        }
        return new PresentationDuration($value);
    }

    private function objectType(DocumentGraphContext $graph, DictionaryType $dictionary, string $expected): void
    {
        $reference = $graph->entry($dictionary, 'Type');
        $type = $reference === null ? null : $graph->resolve($reference);
        if ($type !== null && (!$type instanceof NameType || $type->value !== $expected)) {
            throw new \InvalidArgumentException('The effective dictionary Type must be ' . $expected . ' when present.');
        }
    }

    private function pagePresentation(DocumentGraphContext $graph, DictionaryType $page, ?EffectivePdfVersion $version): void
    {
        if ($this->duration($graph, $page, 'Dur') !== null) {
            $version?->require(PdfVersion::PDF11, 'Page display duration');
        }
        $reference = $graph->entry($page, 'Trans');
        if ($reference === null) {
            return;
        }
        $dictionary = $graph->dictionary($reference);
        if ($dictionary === null) {
            throw new \InvalidArgumentException('Page Trans resolves to a transition dictionary.');
        }
        $options = [];
        foreach (['Type', 'S', 'Dm', 'M', 'Di', 'SS', 'B'] as $key) {
            $value = $graph->entry($dictionary, $key);
            $options[$key] = $value === null ? null : $graph->resolve($value);
        }
        if ($options['Type'] !== null && (!$options['Type'] instanceof NameType || $options['Type']->value !== 'Trans')) {
            throw new \InvalidArgumentException('A transition Type, when present, is Trans.');
        }
        $style = $options['S'] === null ? TransitionStyle::Replace : ($options['S'] instanceof NameType ? TransitionStyle::tryFrom($options['S']->value) : null);
        $dimension = $options['Dm'] instanceof NameType ? TransitionDimension::tryFrom($options['Dm']->value) : null;
        $motion = $options['M'] instanceof NameType ? TransitionMotion::tryFrom($options['M']->value) : null;
        $direction = null;
        foreach (TransitionDirection::cases() as $case) {
            $encoded = $case->toObject();
            if (($options['Di'] instanceof IntegerType && $encoded instanceof IntegerType && $options['Di']->value === $encoded->value)
                || ($options['Di'] instanceof NameType && $encoded instanceof NameType && $options['Di']->value === $encoded->value)) {
                $direction = $case;
            }
        }
        if ($style === null || ($options['Dm'] !== null && $dimension === null) || ($options['M'] !== null && $motion === null)
            || ($options['Di'] !== null && $direction === null) || ($options['SS'] !== null && !$options['SS'] instanceof NumericType)
            || ($options['B'] !== null && !$options['B'] instanceof BooleanType)) {
            throw new \InvalidArgumentException('An effective transition contains an invalid style or parameter type.');
        }
        $transition = new Transition($style, $this->duration($graph, $dictionary, 'D'), $dimension, $motion, $direction, $options['SS'], $options['B']);
        if ($version !== null) {
            $transition->validateVersion($version);
        }
    }

    private function actionLeaves(DocumentGraphContext $graph, NavigationPageIndex $pages, NamedDestinationIndex $destinations, BasicType $root, int $pageIndex): bool
    {
        $pending = [$root];
        $seen = [];
        $structureResolver = null;
        while ($pending !== []) {
            $reference = array_pop($pending);
            $key = $pages->key($reference);
            if (isset($seen[$key])) {
                continue;
            }
            if (count($seen) >= $this->limits->maxNodes) {
                throw new \LengthException('The effective action scan exceeds its budget.');
            }
            $seen[$key] = true;
            $action = $graph->dictionary($reference);
            $subtype = $action?->get('S');
            if (!$action instanceof DictionaryType || !$subtype instanceof NameType) {
                throw new \InvalidArgumentException('A presentation action requires its dictionary and S name.');
            }
            if (in_array($subtype->value, ['GoToR', 'GoToE'], true)) {
                return true;
            }
            if ($subtype->value === 'GoTo') {
                $value = $action->get('D');
                $destination = $value === null ? null : $graph->resolve($value);
                $structureValue = $graph->entry($action, 'SD');
                $structure = $structureValue === null ? null : $graph->resolve($structureValue);
                if ($destination instanceof NameType || $destination instanceof StringType) {
                    $structure ??= $destinations->structure($destination);
                    $destination = $destinations->find($destination);
                }
                if (!$destination instanceof ArrayType) {
                    throw new \InvalidArgumentException('An effective GoTo requires its local destination.');
                }
                $this->destinationArray($pages, $destination);
                $page = $structure instanceof ArrayType ? ($structureResolver ??= new StructureDestinationResolver($graph, $this->limits))->page($structure) : $destination->items[0];
                if ($pages->index($page) !== $pageIndex) {
                    return true;
                }
            }
            if ($subtype->value === 'Named' && ($name = $action->get('N')) instanceof NameType) {
                $target = match ($name->value) {
                    'FirstPage' => 0,
                    'LastPage' => count($pages->pages) - 1,
                    'NextPage' => min($pageIndex + 1, count($pages->pages) - 1),
                    'PrevPage' => max(0, $pageIndex - 1),
                    default => $pageIndex,
                };
                if ($target !== $pageIndex) {
                    return true;
                }
            }
            $nextValue = $graph->entry($action, 'Next');
            $next = $nextValue === null ? null : $graph->resolve($nextValue);
            if ($next instanceof DictionaryType) {
                $pending[] = $nextValue ?? throw new \LogicException('Missing Next action.');
            } elseif ($next instanceof ArrayType) {
                array_push($pending, ...$next->items);
            } elseif ($next !== null) {
                throw new \InvalidArgumentException('An effective action Next is a dictionary or array.');
            }
        }
        return false;
    }

    private function declaredDestinations(DocumentGraphContext $graph, NavigationPageIndex $pages, NamedDestinationIndex $destinations, ?EffectivePdfVersion $version): void
    {
        $pending = [[$graph->catalog, 'other']];
        $seen = [];
        $structureResolver = null;
        while ($pending !== []) {
            [$value, $kind] = array_pop($pending);
            $key = $pages->key($value) . ':' . $kind;
            if (isset($seen[$key])) {
                continue;
            }
            if (count($seen) >= $this->limits->maxNodes) {
                throw new \LengthException('The active navigation declaration graph exceeds its budget.');
            }
            $seen[$key] = true;
            $dictionary = $graph->dictionary($value);
            if ($dictionary !== null) {
                $type = $dictionary->get('Type');
                $annotationType = $dictionary->get('Subtype');
                $catalog = $graph->sameObject($value, $graph->catalog);
                $page = $type instanceof NameType && $type->value === 'Page';
                $annotation = $kind === 'annotation' || ($type instanceof NameType && $type->value === 'Annot');
                $outline = $this->isOutlineItem($graph, $pages, $dictionary);
                $node = $kind === 'node' || ($type instanceof NameType && $type->value === 'NavNode');
                $action = in_array($kind, ['action', 'open'], true) || ($type instanceof NameType && $type->value === 'Action');
                $field = $dictionary->get('FT') instanceof NameType;
                $targets = [];
                if ($action) {
                    $this->objectType($graph, $dictionary, 'Action');
                    if ($graph->entry($dictionary, 'Next') !== null) {
                        $version?->require(PdfVersion::PDF12, 'Next action chain');
                    }
                    $actionType = $dictionary->get('S');
                    if (!$actionType instanceof NameType) {
                        throw new \InvalidArgumentException('An active action requires its S name.');
                    }
                    (new RemoteActionGraphValidation($this->limits))->validate($dictionary, $actionType->value, $graph, $version);
                    if ($actionType->value === 'GoTo') {
                        $target = $graph->entry($dictionary, 'D');
                        if ($target === null) {
                            throw new \InvalidArgumentException('A GoTo action requires its D destination.');
                        }
                        $targets[] = $target;
                        $version?->require(PdfVersion::PDF11, 'Local GoTo action');
                    }
                    if (in_array($actionType->value, ['GoTo', 'GoToR', 'GoToE'], true)) {
                        $sdValue = $graph->entry($dictionary, 'SD');
                        if ($sdValue !== null) {
                            $version?->require(PdfVersion::PDF20, 'Structure destination action SD');
                            $sd = $graph->resolve($sdValue);
                            if (!$sd instanceof ArrayType) {
                                throw new \InvalidArgumentException('An action SD requires an explicit structure destination array.');
                            }
                            if ($actionType->value === 'GoTo') {
                                ($structureResolver ??= new StructureDestinationResolver($graph, $this->limits))->page($sd);
                            } else {
                                StructureDestinationResolver::remote($sd);
                            }
                        }
                    }
                }
                if (($annotation && $annotationType instanceof NameType && $annotationType->value === 'Link') || $outline) {
                    $targets[] = $graph->entry($dictionary, 'Dest');
                }
                if ($catalog) {
                    $open = $graph->entry($dictionary, 'OpenAction');
                    if ($open !== null && $graph->resolve($open) instanceof ArrayType) {
                        $targets[] = $open;
                    }
                }
                foreach ($targets as $target) {
                    if ($target === null) {
                        continue;
                    }
                    $destination = $graph->resolve($target);
                    if ($destination instanceof NameType || $destination instanceof StringType) {
                        $version?->require($destination instanceof NameType ? PdfVersion::PDF11 : PdfVersion::PDF12, 'Named destination reference');
                        $destinations->find($destination);
                    } elseif ($destination instanceof ArrayType) {
                        $this->destinationArray($pages, $destination, $version);
                    } else {
                        throw new \InvalidArgumentException('An active local navigation declaration has an invalid destination.');
                    }
                }
                foreach ($dictionary->entries() as $entry) {
                    $childKind = match (true) {
                        $kind === 'additional' => 'action',
                        $catalog && $entry->key->value === 'OpenAction' => 'open',
                        ($catalog || $page || $annotation || $field) && $entry->key->value === 'AA' => 'additional',
                        ($annotation || $outline || $field) && $entry->key->value === 'A' => 'action',
                        $action && $entry->key->value === 'Next' => 'action',
                        $node && in_array($entry->key->value, ['NA', 'PA'], true) => 'action',
                        ($page && $entry->key->value === 'PresSteps') || ($node && in_array($entry->key->value, ['Next', 'Prev'], true)) => 'node',
                        $page && $entry->key->value === 'Annots' => 'annotations',
                        default => 'other',
                    };
                    if (!$graph->resolve($entry->value) instanceof NullType) {
                        $pending[] = [$entry->value, $childKind];
                    }
                }
            } elseif (($array = $graph->resolve($value)) instanceof ArrayType) {
                $childKind = match ($kind) {
                    'annotations' => 'annotation',
                    'action' => 'action',
                    default => 'other',
                };
                foreach ($array->items as $item) {
                    $pending[] = [$item, $childKind];
                }
            } elseif (in_array($kind, ['action', 'additional', 'node'], true)) {
                throw new \InvalidArgumentException('An active navigation relationship requires its dictionary.');
            }
        }
    }

    private function isOutlineItem(DocumentGraphContext $graph, NavigationPageIndex $pages, DictionaryType $dictionary): bool
    {
        $root = $graph->entry($graph->catalog, 'Outlines');
        if ($root === null || !$dictionary->has('Title')) {
            return false;
        }
        $parent = $graph->entry($dictionary, 'Parent');
        $seen = [];
        while ($parent !== null) {
            if ($graph->sameObject($parent, $root)) {
                return true;
            }
            $key = $pages->key($parent);
            if (isset($seen[$key]) || count($seen) >= $this->limits->maxDepth) {
                return false;
            }
            $seen[$key] = true;
            $node = $graph->dictionary($parent);
            $parent = $node === null ? null : $graph->entry($node, 'Parent');
        }
        return false;
    }
}
