<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/**
 * Unsigned, big-endian cross-reference records with explicit sparse Index.
 *
 * ISO 32000-1, 7.5.8. Records are sorted by object number. Widths are computed
 * from the largest field values; no floating point or platform-endian packing.
 */
final class CrossReferenceStream extends StreamObject implements CrossReferenceStreamObject
{
    /** @var list<CrossReferenceStreamEntry> */
    private readonly array $records;
    /** @var array{int, int, int} */
    private readonly array $widths;
    public readonly IntegerType $size;
    public readonly IndirectReference $root;
    public readonly ?DictionaryType $trailer;

    public function __construct(IntegerType $size, IndirectReference $root, ?DictionaryType $trailer = null, CrossReferenceStreamEntry|Filter ...$parts)
    {
        if ($size->value < 1) {
            throw new \InvalidArgumentException('The cross-reference Size must be positive.');
        }
        foreach (['Type', 'Size', 'Root', 'Index', 'W', 'Length', 'Filter', 'DecodeParms', 'XRefStm'] as $managed) {
            if ($trailer?->has($managed)) {
                throw new \InvalidArgumentException('Managed cross-reference-stream entry: ' . $managed);
            }
        }
        if ($trailer?->has('Prev')) {
            $previous = $trailer->get('Prev');
            if (!$previous instanceof IntegerType || $previous->value < 0) {
                throw new \InvalidArgumentException('Cross-reference Prev must be a direct non-negative integer.');
            }
        }
        foreach (['Info', 'Encrypt'] as $referenceKey) {
            if ($trailer?->has($referenceKey) && !$trailer->get($referenceKey) instanceof IndirectReference) {
                throw new \InvalidArgumentException('Trailer ' . $referenceKey . ' must be an indirect reference.');
            }
        }
        if ($trailer?->has('ID')) {
            $id = $trailer->get('ID');
            if (!$id instanceof ArrayType || count($id->items) !== 2 || !$id->items[0] instanceof StringType || !$id->items[1] instanceof StringType) {
                throw new \InvalidArgumentException('Trailer ID must contain exactly two byte strings.');
            }
        }
        $records = [];
        $filters = [];
        $numbers = [];
        foreach ($parts as $part) {
            if ($part instanceof Filter) {
                if ($part instanceof EncodedData || $part->name()->value === 'Crypt') {
                    throw new \InvalidArgumentException('Cross-reference streams cannot be encrypted or marked as pre-encoded.');
                }
                $filters[] = $part;
                continue;
            }
            $number = $part->number();
            if (isset($numbers[$number]) || $number >= $size->value) {
                throw new \InvalidArgumentException('Cross-reference record numbers must be unique and smaller than Size.');
            }
            $fields = $part->fields();
            if (($part instanceof CrossReferenceStreamFreeEntry || $part instanceof CrossReferenceStreamCompressedEntry) && $fields[1] >= $size->value) {
                throw new \InvalidArgumentException('A free-list or object-stream number must be smaller than Size.');
            }
            $numbers[$number] = true;
            $records[] = $part;
        }
        if ($records === []) {
            throw new \InvalidArgumentException('A cross-reference stream requires at least one record.');
        }
        usort($records, static fn (CrossReferenceStreamEntry $left, CrossReferenceStreamEntry $right): int => $left->number() <=> $right->number());
        $maxima = [2, 0, 0];
        foreach ($records as $record) {
            foreach ($record->fields() as $column => $field) {
                $maxima[$column] = max($maxima[$column], $field);
            }
        }
        $widths = [1, self::unsignedWidth($maxima[1]), self::unsignedWidth($maxima[2])];
        $index = [];
        $start = $records[0]->number();
        $length = 0;
        $previous = $start - 1;
        $bytes = '';
        foreach ($records as $record) {
            $number = $record->number();
            if ($number !== $previous + 1) {
                $index[] = new IntegerType($start);
                $index[] = new IntegerType($length);
                $start = $number;
                $length = 0;
            }
            ++$length;
            $previous = $number;
            foreach ($record->fields() as $column => $field) {
                $bytes .= self::unsignedBytes($field, $widths[$column]);
            }
        }
        $index[] = new IntegerType($start);
        $index[] = new IntegerType($length);
        $dictionary = $trailer ?? new DictionaryType();
        foreach ([
            new Entry(new NameType('Type'), new NameType('XRef')),
            new Entry(new NameType('Size'), $size),
            new Entry(new NameType('Root'), $root),
            new Entry(new NameType('W'), new ArrayType(...array_map(static fn (int $width): IntegerType => new IntegerType($width), $widths))),
            new Entry(new NameType('Index'), new ArrayType(...$index)),
        ] as $entry) {
            $dictionary = $dictionary->with($entry);
        }
        $this->records = $records;
        $this->widths = $widths;
        $this->size = $size;
        $this->root = $root;
        $this->trailer = $trailer;
        parent::__construct($bytes, $dictionary, ...$filters);
    }

    /** @return list<CrossReferenceStreamEntry> */
    public function entries(): array
    {
        return $this->records;
    }

    /** @return array{int, int, int} */
    public function fieldWidths(): array
    {
        return $this->widths;
    }

    public function decodedBytes(): string
    {
        return is_string($this->data) ? $this->data : throw new \LogicException('Cross-reference data must be binary.');
    }

    #[\NoDiscard]
    public function withFilters(Filter ...$filters): self
    {
        return new self($this->size, $this->root, $this->trailer, ...[...$this->records, ...$filters]);
    }

    private static function unsignedWidth(int $value): int
    {
        $width = 1;
        while ($value > 255) {
            $value >>= 8;
            ++$width;
        }
        return $width;
    }

    private static function unsignedBytes(int $value, int $width): string
    {
        $bytes = '';
        for ($position = 0; $position < $width; ++$position) {
            $bytes = chr($value & 255) . $bytes;
            $value >>= 8;
        }
        return $bytes;
    }
}
