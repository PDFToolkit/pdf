<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Graphics\GraphicsState\ColourFunctionName;
use PDF\Graphics\GraphicsState\ScalarColourFunction;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

final readonly class HalftoneOptions
{
    public function __construct(public ?StringType $name = null, public ScalarColourFunction|ColourFunctionName|null $transfer = null)
    {
        if ($transfer === ColourFunctionName::Default) {
            throw new \InvalidArgumentException('Halftone TransferFunction may use Identity, but not Default.');
        }
    }

    /** @return list<Entry> */
    public function entries(): array
    {
        $entries = [];
        if ($this->name !== null) {
            $entries[] = new Entry(new NameType('HalftoneName'), $this->name);
        }
        if ($this->transfer !== null) {
            $entries[] = new Entry(new NameType('TransferFunction'), $this->transfer instanceof ScalarColourFunction ? $this->transfer->value() : new NameType($this->transfer->value));
        }
        return $entries;
    }
}
