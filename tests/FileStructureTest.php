<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\TestCase;

final class FileStructureTest extends TestCase
{
    private function catalog(): DictionaryType
    {
        $pages = new DictionaryType(
            new Entry(new NameType('Type'), new NameType('Pages')),
            new Entry(new NameType('Kids'), new ArrayType()),
            new Entry(new NameType('Count'), new IntegerType(0)),
        );
        return new DictionaryType(new Entry(new NameType('Type'), new NameType('Catalog')), new Entry(new NameType('Pages'), new IndirectReference($pages)));
    }

    public function testTraditionalCrossReferencesUseActualByteOffsets(): void
    {
        $catalog = $this->catalog();
        $file = new FileStructure(new FileHeader(), new FileBody($catalog, new StreamObject("a\0b\nendstream\xff")));
        $pdf = $file->toPdf();
        self::assertStringStartsWith("%PDF-1.7\n%\xe2\xe3\xcf\xd3\n", $pdf);
        preg_match('/startxref\n([0-9]+)\n%%EOF\n$/', $pdf, $match);
        self::assertSame("xref\n", substr($pdf, (int) $match[1], 5));
        preg_match_all('/^(\d{10}) (\d{5}) n $/m', $pdf, $entries);
        self::assertCount(3, $entries[0]);
        foreach ($entries[1] as $index => $offset) {
            self::assertMatchesRegularExpression('/^\d+ ' . (int) $entries[2][$index] . ' obj\n/', substr($pdf, (int) $offset));
        }
        self::assertStringContainsString('/Length 14', $pdf);
        self::assertSame($pdf, $file->toPdf());
        self::assertSame($pdf, (new FileStructure(new FileHeader(), new FileBody($this->catalog(), new StreamObject("a\0b\nendstream\xff"))))->toPdf());
    }

    public function testExplicitIdentifiersAreReservedBeforeAutomaticNumbers(): void
    {
        $catalog = $this->catalog();
        $id = new ObjectIdentifier(new ObjectNumber(1), new GenerationNumber(0));
        $stream = new StreamObject('abc');
        $catalog = $catalog->with(new Entry(new NameType('Metadata'), new IndirectReference($stream, $id)));
        $pdf = (new FileStructure(new FileHeader(), new FileBody($catalog)))->toPdf();
        self::assertStringContainsString('1 0 obj', $pdf);
        self::assertStringContainsString('/Metadata 1 0 R', $pdf);
        self::assertStringContainsString('/Root 2 0 R', $pdf);
    }

    public function testConflictingObjectNumbersFailBeforeWriting(): void
    {
        $id = new ObjectIdentifier(new ObjectNumber(1));
        $this->expectException(\LogicException::class);
        (new FileStructure(new FileHeader(), new FileBody(new IndirectObject($this->catalog(), $id), new IndirectObject(new StreamObject(''), $id))))->toPdf();
    }

    public function testStreamsCannotBeDirectDictionaryValues(): void
    {
        $this->expectException(\LogicException::class);
        (new SerializationContext())->serialize(new DictionaryType(new Entry(new NameType('Stream'), new StreamObject(''))));
    }

    public function testIndependentContextsDoNotShareNumbers(): void
    {
        $reference = new IndirectReference(new IntegerType(7));
        $one = new SerializationContext();
        $one->register(new IntegerType(8));
        self::assertSame('2 0 R', $one->serialize($reference));
        self::assertSame('1 0 R', (new SerializationContext())->serialize($reference));
    }

    public function testExplicitReferencesInDecodeParametersAreReserved(): void
    {
        $globals = new StreamObject('external globals');
        $parameters = new DictionaryType(new Entry(
            new NameType('JBIG2Globals'),
            new IndirectReference($globals, new ObjectIdentifier(new ObjectNumber(1))),
        ));
        $image = new StreamObject('external image', null, new \PDF\Syntax\Filters\EncodedData(
            \PDF\Syntax\Filters\FilterName::JBIG2Decode,
            $parameters,
        ));
        $pdf = (new FileStructure(new FileHeader(), new FileBody($this->catalog(), $image)))->toPdf();
        self::assertStringContainsString('/JBIG2Globals 1 0 R', $pdf);
        self::assertStringContainsString('/Root 2 0 R', $pdf);
    }

    public function testMissingCatalogFailsExplicitly(): void
    {
        $this->expectException(\LogicException::class);
        (new FileStructure(new FileHeader(), new FileBody()))->toPdf();
    }
}
