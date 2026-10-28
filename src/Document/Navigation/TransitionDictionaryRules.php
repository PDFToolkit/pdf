<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** The same transition dictionary contract applies to page Trans and transition actions. */
final readonly class TransitionDictionaryRules
{
    public function validate(DictionaryType $dictionary, DocumentGraphContext $graph, ?EffectivePdfVersion $version): void
    {
        $value = $graph->entry($dictionary, 'D');
        $value = $value === null ? null : $graph->resolve($value);
        if ($value !== null && !$value instanceof NumericType) {
            throw new \InvalidArgumentException('A transition duration is a number of seconds.');
        }
        $duration = $value instanceof NumericType ? new PresentationDuration($value) : null;
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
        $transition = new Transition($style, $duration, $dimension, $motion, $direction, $options['SS'], $options['B']);
        if ($version !== null) {
            $transition->validateVersion($version);
        }
    }
}
