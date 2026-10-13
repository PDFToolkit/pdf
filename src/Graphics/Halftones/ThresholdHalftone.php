<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Specification\TableDefinition;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

abstract class ThresholdHalftone extends StreamObject implements HalftoneObject, \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require($this->type === 16 ? \PDF\Syntax\FileStructure\PdfVersion::PDF13 : \PDF\Syntax\FileStructure\PdfVersion::PDF12, 'Threshold halftone stream');
    }

    /** @param list<Entry> $entries */
    protected function __construct(private readonly int $type, string $bytes, TableDefinition $definition, public readonly HalftoneOptions $options, array $entries, Filter ...$filters)
    {
        foreach ($filters as $filter) {
            if ($filter instanceof EncodedData) {
                throw new \InvalidArgumentException('Constructed threshold bytes require real PDF encoders.');
            }
        }
        parent::__construct($bytes, new ThresholdHalftoneDictionary($definition, new Entry(new NameType('Type'), new NameType('Halftone')), new Entry(new NameType('HalftoneType'), new IntegerType($type)), ...[...$entries, ...$options->entries()]), ...$filters);
    }

    public function halftoneType(): int
    {
        return $this->type;
    }

    public function hasTransferFunction(): bool
    {
        return $this->options->transfer !== null;
    }
}
