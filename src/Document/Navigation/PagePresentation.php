<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Distinct page display duration, incoming transition and sub-page steps. */
final readonly class PagePresentation
{
    public function __construct(public ?PresentationDuration $displayDuration = null, public ?Transition $transition = null, public ?PresentationSequence $steps = null)
    {
    }

    /** @return list<Entry> */
    public function entries(?NavigationNode $primary = null): array
    {
        $entries = [];
        if ($this->displayDuration !== null) {
            $entries[] = new Entry(new NameType('Dur'), $this->displayDuration);
        }
        if ($this->transition !== null) {
            $entries[] = new Entry(new NameType('Trans'), $this->transition);
        }
        if ($this->steps !== null) {
            $entries[] = $this->steps->pageEntry($primary);
        } elseif ($primary !== null) {
            throw new \InvalidArgumentException('A primary node requires an explicit presentation sequence.');
        }
        return $entries;
    }

    /** Incoming transition completes before the page's display timer (Figure56, p387). */
    public function automaticInterval(): ?PresentationDuration
    {
        if ($this->displayDuration === null) {
            return null;
        }
        $transition = $this->transition === null ? 0 : $this->transition->effectDuration()->value;
        return new PresentationDuration(new NumericType($transition + $this->displayDuration->value));
    }
}
