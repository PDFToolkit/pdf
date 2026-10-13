<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\CrossReferenceStreamCompressedEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Annex F.3.1/F.3.11, one uncompressed xref stream with fixed W=[1 4 2]. */
final class LinearizationCrossReferenceStream extends StreamObject implements CrossReferenceStreamObject
{
    public function __construct(IntegerType $firstObject, IntegerType $size, ?IndirectReference $root, ?DictionaryType $extras, ?FixedWidthOffset $previous, ByteOffset|CrossReferenceStreamCompressedEntry ...$offsets)
    {
        $first = $firstObject->integer();
        $count = count($offsets) + ($first === 0 ? 1 : 0);
        if ($first < 0 || $size->integer() <= 0 || $count === 0 || $first + $count > $size->integer()
            || ($first === 0 && ($root !== null || $extras !== null || $previous !== null))) {
            throw new \InvalidArgumentException('Linearized xref streams require a consecutive index and a minimal main trailer.');
        }
        $bytes = $first === 0 ? "\0\0\0\0\0\xff\xff" : '';
        foreach (array_values($offsets) as $index => $offset) {
            $type = $offset instanceof CrossReferenceStreamCompressedEntry ? 2 : 1;
            $location = $offset instanceof CrossReferenceStreamCompressedEntry ? $offset->objectStreamNumber->value : $offset->value;
            if ($offset instanceof CrossReferenceStreamCompressedEntry && $offset->number() !== $first + $index + ($first === 0 ? 1 : 0)) {
                throw new \InvalidArgumentException('A compressed record must occupy its consecutive object-number index.');
            }
            if ($location > 4_294_967_295) {
                throw new \InvalidArgumentException('An xref offset exceeds the explicit four-byte layout field.');
            }
            $memberIndex = $offset instanceof CrossReferenceStreamCompressedEntry ? $offset->index->integer() : 0;
            if ($memberIndex > 65535) {
                throw new \InvalidArgumentException('The fixed xref member-index field has two bytes.');
            }
            $bytes .= chr($type) . pack('Nn', $location, $memberIndex);
        }
        $dictionary = $extras ?? new DictionaryType();
        foreach (['Type', 'Size', 'Root', 'Prev', 'Index', 'W'] as $managed) {
            if ($dictionary->has($managed)) {
                throw new \InvalidArgumentException('The linearized xref stream manages /' . $managed . '.');
            }
        }
        $dictionary = $dictionary->with(new Entry(new NameType('Type'), new NameType('XRef')))
            ->with(new Entry(new NameType('Size'), $size))
            ->with(new Entry(new NameType('Index'), new ArrayType($firstObject, new IntegerType($count))))
            ->with(new Entry(new NameType('W'), new ArrayType(new IntegerType(1), new IntegerType(4), new IntegerType(2))));
        if ($root !== null) {
            $dictionary = $dictionary->with(new Entry(new NameType('Root'), $root));
        }
        if ($previous !== null) {
            $dictionary = $dictionary->with(new Entry(new NameType('Prev'), $previous));
        }
        parent::__construct($bytes, $dictionary);
    }
}
