<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Named indirect normal/down/rollover appearances; state selection is explicit. */
final class AppearanceStates extends DictionaryType
{
    public function __construct(AppearanceState ...$states)
    {
        if ($states === []) {
            throw new \InvalidArgumentException('An appearance-state dictionary cannot be empty.');
        }
        parent::__construct(...array_map(static fn (AppearanceState $state) => new Entry($state->name, new IndirectReference($state->stream)), $states));
    }

    public function with(Entry $entry): static
    {
        if (!$entry->value instanceof IndirectReference || !$entry->value->target() instanceof FormAppearanceStream) {
            throw new \InvalidArgumentException('Appearance states require indirect appearance streams.');
        }
        return parent::with($entry);
    }

    public function without(NameType|string $key): static
    {
        $copy = parent::without($key);
        if (count($copy) === 0) {
            throw new \InvalidArgumentException('An appearance-state dictionary cannot become empty.');
        }
        return $copy;
    }
}
