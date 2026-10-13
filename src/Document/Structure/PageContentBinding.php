<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** One document-local graph attachment; initialized once by the complete page-content builder. */
final class PageContentBinding
{
    private ?PageContentSequence $sequence = null;

    public function attachTo(PageContentSequence $sequence): void
    {
        if ($this->sequence !== null) {
            throw new \InvalidArgumentException('A page content fragment can belong to only one complete sequence.');
        }
        $this->sequence = $sequence;
    }

    public function sequence(): PageContentSequence
    {
        return $this->sequence ?? throw new \LogicException('A page fragment must belong to a complete content sequence before serialization.');
    }
}
