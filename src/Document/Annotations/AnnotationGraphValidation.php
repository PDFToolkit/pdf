<?php

declare(strict_types=1);

namespace PDF\Document\Annotations;

use PDF\Document\DocumentGraphContext;
use PDF\Document\Navigation\NavigationLimits;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** ISO1 12.5.6.2/.14 pages399–401/413–414; ISO2 pages494–495/510–511. */
final readonly class AnnotationGraphValidation
{
    public function __construct(private NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function validate(DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $index = new AnnotationPageIndex($graph, $this->limits);
        /** @var array<string,list<PageAnnotation>> $popupOwners */
        $popupOwners = [];
        /** @var array<string,array{DictionaryType,DocumentGraphContext}> $popups */
        $popups = [];
        foreach ($index->annotations as $item) {
            $subtype = $this->value($item, 'Subtype');
            if (!$subtype instanceof NameType) {
                throw new \InvalidArgumentException('A page annotation has its Subtype name.');
            }
            if ($subtype->value === 'Popup') {
                $popups[$item->identity] = [$item->dictionary, $item->graph];
            }
            if (!in_array($subtype->value, ['Text', 'FreeText', 'Line', 'Square', 'Circle', 'Polygon', 'PolyLine', 'Highlight', 'Underline', 'Squiggly', 'StrikeOut', 'Stamp', 'Caret', 'Ink', 'FileAttachment', 'Sound', 'Redact'], true)) {
                continue; // Custom subtypes and unrelated private keys are not guessed to be markup.
            }
            $reply = $item->graph->entry($item->dictionary, 'IRT');
            $relation = $this->value($item, 'RT');
            if ($relation !== null) {
                $version->require(PdfVersion::PDF16, 'Annotation reply types');
                if (!$relation instanceof NameType || !in_array($relation->value, ['R', 'Group'], true) || $reply === null) {
                    throw new \InvalidArgumentException('Markup RT is R or Group and requires its IRT annotation.');
                }
            }
            if ($reply !== null) {
                $version->require(PdfVersion::PDF15, 'Annotation replies');
                $target = $index->find($reply);
                if ($target === null || $target->pageIdentity !== $item->pageIdentity) {
                    throw new \InvalidArgumentException('Markup IRT identifies an annotation on the same effective page.');
                }
                if ($relation instanceof NameType && $relation->value === 'Group' && $target->graph->entry($target->dictionary, 'IRT') !== null) {
                    throw new \InvalidArgumentException('A primary annotation in a Group has no IRT entry.');
                }
            }
            if ($relation instanceof NameType && $relation->value === 'Group') {
                continue; // Group attributes, including Popup, belong to the primary annotation.
            }
            $popup = $item->graph->entry($item->dictionary, 'Popup');
            if ($popup === null) {
                continue;
            }
            $version->require(PdfVersion::PDF13, 'Popup annotations');
            $dictionary = $popup instanceof IndirectReference ? $graph->dictionary($popup) : null;
            $kind = $dictionary === null ? null : $graph->entry($dictionary, 'Subtype');
            $kind = $kind === null ? null : $graph->resolve($kind);
            if ($dictionary === null || !$kind instanceof NameType || $kind->value !== 'Popup') {
                throw new \InvalidArgumentException('Markup Popup is an indirect reference to an actual Popup annotation.');
            }
            $key = (string) ($graph->identifier($popup) ?? throw new \LogicException('A popup reference has its native identity.'));
            $listed = $index->find($popup);
            if ($listed !== null && $listed->pageIdentity !== $item->pageIdentity) {
                throw new \InvalidArgumentException('A popup and its markup parent are associated with the same page.');
            }
            $popups[$key] = [$dictionary, $graph];
            $popupOwners[$key][] = $item;
        }
        foreach ($popups as $key => [$dictionary, $scope]) {
            $owners = $popupOwners[$key] ?? [];
            if ($owners === []) {
                throw new \InvalidArgumentException('A Popup annotation cannot appear alone; a markup parent identifies it through Popup.');
            }
            foreach (['AP', 'A', 'AA'] as $entry) {
                if ($scope->entry($dictionary, $entry) !== null) {
                    throw new \InvalidArgumentException('A Popup annotation has no appearance stream or associated actions of its own.');
                }
            }
            $parent = $scope->entry($dictionary, 'Parent');
            if ($parent !== null) {
                if (!$parent instanceof IndirectReference) {
                    throw new \InvalidArgumentException('Popup Parent, when present, is an indirect annotation reference.');
                }
                foreach ($owners as $owner) {
                    if (!$graph->sameObject($parent, $owner->reference)) {
                        throw new \InvalidArgumentException('Popup Parent agrees with the markup annotation identifying that popup.');
                    }
                }
            }
        }
        (new AnnotationAppearanceGraphValidation($this->limits))->validate($index, $version);
    }

    private function value(PageAnnotation $item, string $key): ?\PDF\Syntax\FileStructure\BasicType
    {
        $entry = $item->graph->entry($item->dictionary, $key);
        return $entry === null ? null : $item->graph->resolve($entry);
    }
}
