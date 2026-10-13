<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\IntegerType;

/** A typed fragment can cross scopes only while attached to its complete, validated page sequence. */
final readonly class PageContentFragment extends ContentStreams
{
    private PageContentBinding $binding;

    public function __construct(Operator ...$operators)
    {
        parent::__construct(...$operators);
        $this->binding = new PageContentBinding();
    }

    /** @internal Attach through PageContentSequence. */
    public function attachTo(PageContentSequence $sequence, IntegerType $part): void
    {
        if ($sequence->fragmentOperators($part) !== $this->operators) {
            throw new \InvalidArgumentException('A page fragment must match its owning content sequence and can be attached only once.');
        }
        $this->binding->attachTo($sequence);
    }

    public function validate(): void
    {
        $this->binding->sequence()->validate();
    }

    public function with(Operator ...$operators): static
    {
        throw new \LogicException('Build a new complete page-content sequence to change its fragments.');
    }
}
