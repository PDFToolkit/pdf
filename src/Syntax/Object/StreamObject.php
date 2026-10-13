<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\StreamObject\StreamExtent;

/** 7.3.8. Length describes encoded bytes, excluding the delimiter newline. */
class StreamObject implements BasicType
{
    use SerializesPdf;
    /** @var list<Filter> */
    public readonly array $filters;
    private readonly DictionaryType $streamDictionary;

    public function __construct(public readonly string|ContentStreams $data, ?DictionaryType $dictionary = null, Filter ...$filters)
    {
        $this->streamDictionary = $dictionary ?? new DictionaryType();
        foreach (['Length', 'Filter', 'DecodeParms', 'F', 'FFilter', 'FDecodeParms'] as $key) {
            if ($this->streamDictionary->has($key)) {
                throw new \InvalidArgumentException('Managed or external stream entry: ' . $key);
            }
        }
        foreach ($filters as $index => $filter) {
            if ($filter instanceof EncodedData && ($index !== count($filters) - 1 || $data instanceof ContentStreams)) {
                throw new \InvalidArgumentException('EncodedData must be the last decoding filter, on binary input.');
            }
        }
        $this->filters = array_values($filters);
    }

    public function dictionary(): DictionaryType
    {
        return $this->streamDictionary;
    }
    public function bytes(SerializationContext $context): string
    {
        $bytes = is_string($this->data) ? $this->data : $context->serializeStreamValue($this->data);
        // Filter array is in decoding order, so encoders run in reverse.
        foreach (array_reverse($this->filters) as $filter) {
            $bytes = $filter->encode($bytes);
        }
        return $bytes;
    }

    public function toPdf(SerializationContext $context): string
    {
        $encoded = $this->bytes($context);
        $bytes = $context->encryptStream($this, $encoded);
        $dictionary = $this->dictionary()->with(new Entry(new NameType('Length'), new StreamExtent($bytes)));
        $filters = $context->streamFilters($this);
        if ($filters !== []) {
            $names = array_map(static fn (Filter $filter): NameType => $filter->name(), $filters);
            $dictionary = $dictionary->with(new Entry(new NameType('Filter'), count($names) === 1 ? $names[0] : new ArrayType(...$names)));
            $parameters = array_map(static fn (Filter $filter): BasicType => $filter->parameters() ?? new NullType(), $filters);
            if (array_filter($parameters, static fn (BasicType $value): bool => !$value instanceof NullType) !== []) {
                $dictionary = $dictionary->with(new Entry(new NameType('DecodeParms'), count($parameters) === 1 ? $parameters[0] : new ArrayType(...$parameters)));
            }
        }
        $context->recordStreamEmission($this, $dictionary, $encoded);
        return $context->serialize($dictionary) . "\nstream\n" . $bytes . "\nendstream";
    }
}
