<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\Definition\AppearanceDictionaryTable168Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class WidgetAppearance extends SchemaDictionary
{
    public function __construct(public readonly FormAppearanceStream|AppearanceStates $normal, FormAppearanceStream|AppearanceStates|null $rollover = null, FormAppearanceStream|AppearanceStates|null $down = null)
    {
        $entries = [new Entry(new NameType('N'), $normal instanceof FormAppearanceStream ? new IndirectReference($normal) : $normal)];
        foreach (['R' => $rollover, 'D' => $down] as $key => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($key), $value instanceof FormAppearanceStream ? new IndirectReference($value) : $value);
            }
        }
        parent::__construct(new AppearanceDictionaryTable168Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Supply appearance alternatives in the constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Supply appearance alternatives in the constructor.');
    }
}
