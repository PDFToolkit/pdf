<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** Document-scoped subset identities; callers share this object, never global state. */
final class FontSubsetRegistry
{
    /** @var array<string, FontStream> */
    private array $programs = [];
    /** @var array<string, string> */
    private array $names = [];

    public function register(SubsetFontName $name, FontStream $program): void
    {
        $previous = $this->programs[$name->tag->value] ?? null;
        if ($previous !== null && ($previous !== $program || $this->names[$name->tag->value] !== $name->original->value)) {
            throw new \InvalidArgumentException('Different font subsets in one PDF have different tags.');
        }
        $this->programs[$name->tag->value] = $program;
        $this->names[$name->tag->value] = $name->original->value;
    }
}
