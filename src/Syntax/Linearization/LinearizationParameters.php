<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Definition\LinearizationParameterDictionaryTableF_1Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Table F.1, physical PDF page 688. Fixed-width offsets allow exact two-pass layout. */
final class LinearizationParameters extends SchemaDictionary
{
    public function __construct(
        public readonly IntegerType $fileLength,
        public readonly IntegerType $hintOffset,
        public readonly IntegerType $hintLength,
        public readonly IntegerType $firstPageObject,
        public readonly IntegerType $firstPageEnd,
        public readonly IntegerType $pageCount,
        public readonly IntegerType $mainXrefEntryOffset,
        public readonly IntegerType $firstPageIndex = new IntegerType(0),
        public readonly ?HintStreamExtent $overflow = null,
    ) {
        foreach ([$fileLength, $hintOffset, $hintLength, $firstPageObject, $firstPageEnd, $pageCount, $mainXrefEntryOffset, $firstPageIndex] as $value) {
            if ($value->integer() < 0 || $value->integer() > 4_294_967_295) {
                throw new \InvalidArgumentException('Linearization values must fit the unsigned 32-bit hint address space.');
            }
        }
        if ($firstPageObject->integer() < 1 || $pageCount->integer() < 1 || $firstPageIndex->integer() >= $pageCount->integer()) {
            throw new \InvalidArgumentException('Linearization requires a nonempty document and an existing initial page.');
        }
        parent::__construct(
            new LinearizationParameterDictionaryTableF_1Definition(),
            new Entry(new NameType('Linearized'), new IntegerType(1)),
            new Entry(new NameType('L'), $fileLength),
            new Entry(new NameType('H'), new ArrayType($hintOffset, $hintLength, ...($overflow === null ? [] : [new IntegerType($overflow->offset->value), $overflow->length]))),
            new Entry(new NameType('O'), $firstPageObject),
            new Entry(new NameType('E'), $firstPageEnd),
            new Entry(new NameType('N'), $pageCount),
            new Entry(new NameType('T'), $mainXrefEntryOffset),
            new Entry(new NameType('P'), $firstPageIndex),
        );
    }

    public function toPdf(SerializationContext $context): string
    {
        $this->validate();
        $hint = sprintf('[%010d %010d', $this->hintOffset->integer(), $this->hintLength->integer());
        if ($this->overflow !== null) {
            $hint .= sprintf(' %010d %010d', $this->overflow->offset->value, $this->overflow->length->integer());
        }
        $hint .= ']';
        return sprintf(
            '<< /Linearized 1 /L %010d /H %s /O %010d /E %010d /N %010d /T %010d /P %010d >>',
            $this->fileLength->integer(),
            $hint,
            $this->firstPageObject->integer(),
            $this->firstPageEnd->integer(),
            $this->pageCount->integer(),
            $this->mainXrefEntryOffset->integer(),
            $this->firstPageIndex->integer()
        );
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Linearization parameters are constructed from one completed layout.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Linearization parameters cannot discard layout fields.');
    }
}
