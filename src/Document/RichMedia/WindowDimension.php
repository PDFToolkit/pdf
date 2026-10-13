<?php

declare(strict_types=1);

namespace PDF\Document\RichMedia;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Explicit Default/Min/Max dimensions in default user space (Adobe supplement, p.84). */
final class WindowDimension extends DictionaryType
{
    public function __construct(NumericType $default, NumericType $minimum, NumericType $maximum)
    {
        parent::__construct(
            new Entry(new NameType('Default'), $default),
            new Entry(new NameType('Min'), $minimum),
            new Entry(new NameType('Max'), $maximum),
        );
        $this->validate();
    }

    public function with(Entry $entry): static
    {
        $copy = parent::with($entry);
        $copy->validate();

        return $copy;
    }

    public function without(NameType|string $key): static
    {
        if (in_array($key instanceof NameType ? $key->value : $key, ['Default', 'Min', 'Max'], true)) {
            throw new \InvalidArgumentException('A resolved window dimension requires Default, Min and Max.');
        }

        return parent::without($key);
    }

    private function validate(): void
    {
        $default = $this->get('Default');
        $minimum = $this->get('Min');
        $maximum = $this->get('Max');
        if (!$default instanceof NumericType || !$minimum instanceof NumericType || !$maximum instanceof NumericType) {
            throw new \InvalidArgumentException('Window dimensions require direct PDF numbers.');
        }
        if ($minimum->value < 0 || $minimum->value > $default->value || $default->value > $maximum->value) {
            throw new \InvalidArgumentException('Window dimensions require 0 <= Min <= Default <= Max.');
        }
    }
}
