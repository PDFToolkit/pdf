<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** A re-numbered member's container; headers use the current logical identity, never its old number. */
final class LinearizedObjectStream extends StreamObject
{
    public function __construct(public readonly BasicType $member, private readonly BasicType $serializedMember, Filter ...$filters)
    {
        parent::__construct('', new DictionaryType(new Entry(new NameType('Type'), new NameType('ObjStm')), new Entry(new NameType('N'), new IntegerType(1))), ...$filters);
    }

    public function toPdf(SerializationContext $context): string
    {
        [$bytes, $first] = $this->build($context);
        $dictionary = $this->dictionary()->with(new Entry(new NameType('First'), new IntegerType($first)));
        return (new StreamObject($bytes, $dictionary, ...$this->filters))->toPdf($context);
    }

    public function bytes(SerializationContext $context): string
    {
        [$bytes] = $this->build($context);
        foreach (array_reverse($this->filters) as $filter) {
            $bytes = $filter->encode($bytes);
        }
        return $bytes;
    }

    public function decodedBytes(SerializationContext $context): string
    {
        return $this->build($context)[0];
    }

    /** @return array{string, int} */
    private function build(SerializationContext $context): array
    {
        $number = $context->identifier($this->member)->objectNumber->value;
        $header = $number . " 0\n";
        return [$header . $context->serializeStreamValue($this->serializedMember) . "\n", strlen($header)];
    }
}
