<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NumericType;

/** Named ink coverage values in [0,1], including the optional Default entry (table 73, page 171). */
final class InkSolidities extends DictionaryType
{
    public function __construct(Entry ...$inks)
    {
        foreach ($inks as $ink) {
            $this->validateInk($ink);
        }
        parent::__construct(...$inks);
    }

    public function with(Entry $entry): static
    {
        $this->validateInk($entry);
        return parent::with($entry);
    }

    private function validateInk(Entry $entry): void
    {
        if (!$entry->value instanceof NumericType || $entry->value->value < 0 || $entry->value->value > 1) {
            throw new \InvalidArgumentException('Ink solidity must be a number between zero and one.');
        }
    }
}
