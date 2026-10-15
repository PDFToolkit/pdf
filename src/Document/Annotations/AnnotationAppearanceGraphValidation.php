<?php

declare(strict_types=1);

namespace PDF\Document\Annotations;

use PDF\Document\DocumentGraphContext;
use PDF\Document\Navigation\NavigationLimits;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/** ISO2 Table166/170, physical pp483/490–491; EC3 #22/#124 and Form Table93 p287. */
final readonly class AnnotationAppearanceGraphValidation
{
    public function __construct(private NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function validate(AnnotationPageIndex $index, EffectivePdfVersion $version): void
    {
        if ($version->value !== PdfVersion::PDF20) {
            return;
        }
        $remaining = $this->limits->maxNodes;
        foreach ($index->annotations as $item) {
            $this->annotation($item, $remaining);
        }
    }

    private function annotation(PageAnnotation $item, int &$remaining): void
    {
        $graph = $item->graph;
        $rectangle = $this->numbers($item->dictionary, 'Rect', 4, $graph);
        $subtype = $this->value($item->dictionary, 'Subtype', $graph);
        $state = $this->value($item->dictionary, 'AS', $graph);
        if ($state !== null && !$state instanceof NameType) {
            throw new \InvalidArgumentException('Annotation AS is an appearance-state name.');
        }
        $appearance = $graph->entry($item->dictionary, 'AP');
        if ($appearance === null) {
            // A point, not every zero-area line: corrected zero-based indices #124.
            $point = $rectangle[0] == $rectangle[2] && $rectangle[1] == $rectangle[3];
            if (!$point && !($subtype instanceof NameType && in_array($subtype->value, ['Popup', 'Projection', 'Link'], true))) {
                throw new \InvalidArgumentException('PDF 2.0 annotations require AP except for point rectangles, Popup, Projection and Link.');
            }
            return;
        }
        $scope = $appearance instanceof IndirectReference ? $graph : $graph->forDirectValue($appearance);
        if (!$scope->resolve($appearance) instanceof DictionaryType || ($dictionary = $scope->dictionary($appearance)) === null) {
            throw new \InvalidArgumentException('Annotation AP is an appearance dictionary, not a stream.');
        }
        $this->charge($remaining, 1);
        foreach (['N', 'R', 'D'] as $key) {
            $entry = $scope->entry($dictionary, $key);
            if ($entry === null) {
                if ($key === 'N') {
                    throw new \InvalidArgumentException('Annotation AP requires its normal N appearance.');
                }
                continue;
            }
            $value = $scope->resolve($entry);
            if ($value instanceof StreamObject) {
                $this->form($entry, $scope, $remaining);
                continue;
            }
            if (!$value instanceof DictionaryType) {
                throw new \InvalidArgumentException('Each annotation N/R/D appearance is a Form stream or a state dictionary.');
            }
            if ($state === null) {
                throw new \InvalidArgumentException('An annotation appearance-state dictionary in N, R or D requires AS.');
            }
            $states = $entry instanceof IndirectReference ? $scope : $scope->forDirectValue($entry);
            $alternatives = $states->dictionary($entry) ?? throw new \LogicException('A resolved appearance state dictionary retains its values.');
            $this->charge($remaining, count($alternatives));
            foreach ($alternatives->entries() as $alternative) {
                if (!$states->resolve($alternative->value) instanceof NullType) {
                    $this->form($alternative->value, $states, $remaining);
                }
            }
        }
        // ISO2 p491 requires a consumer fallback for an AS without an appearance.
        // Do not invent a writer prohibition or silently change the requested AS.
    }

    private function form(BasicType $reference, DocumentGraphContext $graph, int &$remaining): void
    {
        $this->charge($remaining, 1);
        if (!$graph->resolve($reference) instanceof StreamObject || ($dictionary = $graph->dictionary($reference)) === null) {
            throw new \InvalidArgumentException('An annotation appearance state references an actual Form XObject stream.');
        }
        $type = $this->value($dictionary, 'Type', $graph);
        $subtype = $this->value($dictionary, 'Subtype', $graph);
        $formType = $this->value($dictionary, 'FormType', $graph);
        if (($type !== null && (!$type instanceof NameType || $type->value !== 'XObject'))
            || !$subtype instanceof NameType || $subtype->value !== 'Form'
            || ($formType !== null && (!$formType instanceof IntegerType || $formType->integer() !== 1))) {
            throw new \InvalidArgumentException('Annotation appearances are Type1 Form XObjects (Type XObject is optional).');
        }
        $this->numbers($dictionary, 'BBox', 4, $graph);
        if ($graph->entry($dictionary, 'Matrix') !== null) {
            $this->numbers($dictionary, 'Matrix', 6, $graph);
        }
    }

    /** @return list<int|float> */
    private function numbers(DictionaryType $dictionary, string $key, int $count, DocumentGraphContext $graph): array
    {
        $value = $this->value($dictionary, $key, $graph);
        if (!$value instanceof ArrayType || count($value) !== $count) {
            throw new \InvalidArgumentException('Annotation appearance /' . $key . ' requires ' . $count . ' numbers.');
        }
        $numbers = [];
        foreach ($value as $item) {
            $number = $graph->resolve($item);
            if (!$number instanceof NumericType || !is_finite((float) $number->value)) {
                throw new \InvalidArgumentException('Annotation appearance /' . $key . ' contains finite numbers.');
            }
            $numbers[] = $number->value;
        }
        return $numbers;
    }

    private function value(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?BasicType
    {
        $value = $graph->entry($dictionary, $key);
        return $value === null ? null : $graph->resolve($value);
    }

    private function charge(int &$remaining, int $count): void
    {
        if ($count > $remaining) {
            throw new \LengthException('Annotation appearances exceed the explicit document construction budget.');
        }
        $remaining -= $count;
    }
}
