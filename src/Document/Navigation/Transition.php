<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\TransitionDictionaryTable162Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Source-prescribed transition combinations, ISO12.4.4.1/Table162, pp386–387. */
final class Transition extends SchemaDictionary implements VersionedComponent
{
    public function __construct(public readonly TransitionStyle $style = TransitionStyle::Replace, public readonly ?PresentationDuration $duration = null, public readonly ?TransitionDimension $dimension = null, public readonly ?TransitionMotion $motion = null, public readonly ?TransitionDirection $direction = null, public readonly ?NumericType $scale = null, public readonly ?BooleanType $rectangular = null)
    {
        if ($dimension !== null && !in_array($style, [TransitionStyle::Split, TransitionStyle::Blinds], true)) {
            throw new \InvalidArgumentException('Transition Dm applies only to Split or Blinds.');
        }
        if ($motion !== null && !in_array($style, [TransitionStyle::Split, TransitionStyle::Box, TransitionStyle::Fly], true)) {
            throw new \InvalidArgumentException('Transition M applies only to Split, Box or Fly.');
        }
        if ($direction !== null && !$style->hasDirection()) {
            throw new \InvalidArgumentException('This transition style has no Di parameter.');
        }
        if (($direction === TransitionDirection::BottomToTop || $direction === TransitionDirection::RightToLeft) && $style !== TransitionStyle::Wipe) {
            throw new \InvalidArgumentException('Directions 90 and 180 apply only to Wipe.');
        }
        if ($direction === TransitionDirection::TopLeftToBottomRight && $style !== TransitionStyle::Glitter) {
            throw new \InvalidArgumentException('Direction 315 applies only to Glitter.');
        }
        if (($scale !== null || $rectangular !== null) && $style !== TransitionStyle::Fly) {
            throw new \InvalidArgumentException('Transition SS and B apply only to Fly.');
        }
        if ($direction === TransitionDirection::None && ($style !== TransitionStyle::Fly || $scale === null || $scale->value == 1)) {
            throw new \InvalidArgumentException('Direction None is relevant only for Fly at a nonunit scale.');
        }
        $entries = [new Entry(new NameType('Type'), new NameType('Trans')), new Entry(new NameType('S'), new NameType($style->value))];
        foreach (['D' => $duration, 'Dm' => $dimension === null ? null : new NameType($dimension->value), 'M' => $motion === null ? null : new NameType($motion->value), 'Di' => $direction?->toObject(), 'SS' => $scale, 'B' => $rectangular] as $key => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($key), $value);
            }
        }
        parent::__construct(new TransitionDictionaryTable162Definition(), ...$entries);
    }

    public function effectDuration(): PresentationDuration
    {
        return $this->style === TransitionStyle::Replace ? new PresentationDuration(new NumericType(0)) : ($this->duration ?? new PresentationDuration(new NumericType(1)));
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require($this->style->introducedIn15() ? PdfVersion::PDF15 : PdfVersion::PDF11, 'Page transition');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Transition options are coupled to its explicitly selected style.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Transition options are coupled to its explicitly selected style.');
    }
}
