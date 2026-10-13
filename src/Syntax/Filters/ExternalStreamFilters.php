<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Table5/page28: declare the external decoding pipeline; no encoder or I/O is executed. */
final readonly class ExternalStreamFilters
{
    /** @var list<Filter> */
    public array $filters;

    public function __construct(Filter ...$filters)
    {
        foreach ($filters as $index => $filter) {
            if ($filter->name()->value === 'Crypt' && $index !== 0) {
                throw new \InvalidArgumentException('A declared Crypt filter must be the first external decoding filter.');
            }
        }
        $this->filters = array_values($filters);
    }

    /** @return iterable<Entry> */
    public function entries(): iterable
    {
        if ($this->filters === []) {
            return;
        }
        $names = array_map(static fn (Filter $filter): NameType => $filter->name(), $this->filters);
        yield new Entry(new NameType('FFilter'), count($names) === 1 ? $names[0] : new ArrayType(...$names));
        $parameters = array_map(static fn (Filter $filter): BasicType => $filter->parameters() ?? new NullType(), $this->filters);
        if (array_filter($parameters, static fn (BasicType $value): bool => !$value instanceof NullType) !== []) {
            yield new Entry(new NameType('FDecodeParms'), count($parameters) === 1 ? $parameters[0] : new ArrayType(...$parameters));
        }
    }
}
