<?php

declare(strict_types=1);

namespace PDF\Document\Forms\Fdf;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\CrossReferenceEntry;
use PDF\Syntax\FileStructure\CrossReferenceTable;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\CrossReferenceStream;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Native FDF serialization (ISO 12.7.7.2, pp.464–466), without a fake PDF page tree. */
final readonly class FdfWriter
{
    public function __construct(private FdfCrossReference $crossReference = FdfCrossReference::Omitted)
    {
    }

    public function serialize(FdfCatalog $catalog, ?DictionaryType $trailerEntries = null): string
    {
        foreach (['Root', 'Size', 'Prev', 'XRefStm', 'Encrypt'] as $managed) {
            if ($trailerEntries?->has($managed)) {
                throw new \InvalidArgumentException('FDF trailer entry is managed or forbidden: ' . $managed);
            }
        }
        $catalog->validate();
        $context = new SerializationContext();
        $root = new IndirectReference($catalog);
        $context->prepareFile(new FileBody($catalog), $root, $trailerEntries);
        $bytes = "%FDF-1.2\n%\xe2\xe3\xcf\xd3\n";
        $entries = [new CrossReferenceEntry(0, new ByteOffset(0), new GenerationNumber(65535), false)];
        $written = [];
        do {
            foreach (array_diff_key($context->objects(), $written) as $number => $object) {
                $identifier = $context->identifier($object);
                if ($identifier->generationNumber->value !== 0 || $object instanceof ObjectStream || $object instanceof CrossReferenceStream) {
                    throw new \InvalidArgumentException('FDF objects use generation zero and ordinary indirect objects.');
                }
                $entries[] = new CrossReferenceEntry($number, new ByteOffset(strlen($bytes)), $identifier->generationNumber, true);
                $bytes .= (new IndirectObject($object, $identifier))->toPdf($context);
                $written[$number] = true;
            }
        } while (array_diff_key($context->objects(), $written) !== []);
        $offset = strlen($bytes);
        $trailer = new DictionaryType(new Entry(new NameType('Root'), $root), ...iterator_to_array($trailerEntries?->entries() ?? [], false));
        if ($this->crossReference === FdfCrossReference::Traditional) {
            $numbers = array_keys($context->objects());
            if ($numbers === []) {
                throw new \LogicException('An FDF file needs its indirect catalogue object.');
            }
            $maximum = max($numbers);
            if ($maximum === PHP_INT_MAX) {
                throw new \OverflowException('FDF object number exceeds trailer Size.');
            }
            $trailer = $trailer->with(new Entry(new NameType('Size'), new IntegerType($maximum + 1)));
            $bytes .= new CrossReferenceTable(...$entries);
        }
        $bytes .= "trailer\n" . $context->serialize($trailer) . "\n";
        if ($this->crossReference === FdfCrossReference::Traditional) {
            $bytes .= "startxref\n" . $offset . "\n";
        }
        return $bytes . "%%EOF\n";
    }
}
