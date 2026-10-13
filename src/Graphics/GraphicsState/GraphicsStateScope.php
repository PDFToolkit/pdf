<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

/** Explicit ExtGState parameter application/save/restore scope; no geometry or pixels are rendered. */
final class GraphicsStateScope
{
    private ParameterState $current;
    /** @var list<ParameterState> */
    private array $stack = [];

    public function __construct(private readonly ParameterState $pageInitial)
    {
        $this->current = $pageInitial;
    }

    public function current(): ParameterState
    {
        return $this->current;
    }

    public function apply(GraphicsStateParameters $parameters): void
    {
        $this->current = $this->current->apply($parameters, $this->pageInitial);
    }

    public function save(): void
    {
        $this->stack[] = $this->current;
    }

    public function restore(): void
    {
        $state = array_pop($this->stack);
        if ($state === null) {
            throw new \LogicException('Graphics parameter restore has no matching save.');
        }
        $this->current = $state;
    }
}
