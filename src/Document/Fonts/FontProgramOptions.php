<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Typed composition of actual encoding filters and the optional font metadata reference. */
final readonly class FontProgramOptions
{
    /** @var list<Filter> */
    public array $filters;
    public ?FontProgramMetadata $metadata;
    public function __construct(Filter|FontProgramMetadata ...$options)
    {
        $filters = [];
        $metadata = null;
        foreach ($options as $option) {
            if ($option instanceof FontProgramMetadata) {
                if ($metadata !== null) {
                    throw new \InvalidArgumentException('A font program has one Metadata stream.');
                }
                $metadata = $option;
            } else {
                $filters[] = $option;
            }
        }
        $this->filters = $filters;
        $this->metadata = $metadata;
    }
    public function dictionary(DictionaryType $base): DictionaryType
    {
        return $this->metadata === null ? $base : $base->with(new Entry(new NameType('Metadata'), new IndirectReference($this->metadata->stream)));
    }
}
