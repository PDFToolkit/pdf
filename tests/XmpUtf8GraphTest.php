<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Metadata\XmpEncodingEvidence;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Filters\ASCII85Decode;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Filters\FilterName;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class XmpUtf8GraphTest extends TestCase
{
    #[DataProvider('invalidBytes')]
    public function testInvalidRawMetadataIsRejectedInPdf20(string $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UTF-8');
        $this->file($this->catalog($this->stream($bytes)), '2.0')->toRevision();
    }

    #[DataProvider('invalidBytes')]
    public function testHistoricalBytesAreRejectedWhenRetainedThroughACatalogUpgrade(string $bytes): void
    {
        $catalog = $this->catalog($this->stream($bytes, new FlateDecode()));
        $revision = $this->file($catalog, '1.7')->toRevision();
        $upgrade = $catalog->with(new Entry(new NameType('Version'), new NameType('2.0')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UTF-8');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($upgrade, $revision->binding($catalog)->identifier))))->toRevision();
    }

    public static function invalidBytes(): iterable
    {
        yield 'latin1' => ["<xml>\xe9</xml>"];
        yield 'utf16' => ["\xfe\xff\x00<\x00x\x00/\x00>"];
        yield 'overlong' => ["<xml>\xc0\xaf</xml>"];
        yield 'other-declaration' => ['<?xml version="1.0" encoding="ISO-8859-1"?><xml/>'];
    }

    #[DataProvider('formats')]
    public function testFilteredMetadataKeepsItsEncodingEvidenceAcrossRetainedRevisions(CrossReferenceFormat $format): void
    {
        $stream = $this->stream('<?xml version="1.0" encoding="UTF-8"?><xml>Révisé</xml>', new ASCII85Decode(), new FlateDecode());
        $catalog = $this->catalog($stream);
        $revision = $this->file($catalog, '2.0', $format)->toRevision();
        $retained = (new IncrementalUpdate($revision, new FileBody(new IndirectObject($catalog, $revision->binding($catalog)->identifier))))->toRevision();
        self::assertStringStartsWith($revision->bytes, $retained->bytes);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UTF-8');
        (new IncrementalUpdate($retained, new FileBody(new IndirectObject($this->stream("<xml>\xe9</xml>"), $revision->binding($stream)->identifier))))->toRevision();
    }

    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield [$format];
        }
    }

    public function testOpaquePreencodedContentCannotInventUtf8SourceEvidence(): void
    {
        $stream = $this->stream('4162>', new EncodedData(FilterName::ASCIIHexDecode));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('opaque pre-encoded');
        $this->file($this->catalog($stream), '2.0')->toRevision();
    }

    public function testEmissionEvidenceCannotBeReusedForDifferentEncodedBytes(): void
    {
        $evidence = new XmpEncodingEvidence('<xml/>', 'original encoded bytes');
        $this->expectException(\InvalidArgumentException::class);
        $evidence->validate('other encoded bytes');
    }

    public function testRetainedPreencodedMetadataCannotInventSourceEvidenceOnUpgrade(): void
    {
        $stream = $this->stream('3C786D6C2F3E>', new EncodedData(FilterName::ASCIIHexDecode));
        $catalog = $this->catalog($stream);
        $revision = $this->file($catalog, '1.7')->toRevision();
        $upgrade = $catalog->with(new Entry(new NameType('Version'), new NameType('2.0')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('emission evidence');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($upgrade, $revision->binding($catalog)->identifier))))->toRevision();
    }

    public function testReplacingHistoricalBytesWithUtf8AllowsTheSameCatalogUpgrade(): void
    {
        $stream = $this->stream("<xml>\xe9</xml>", new FlateDecode());
        $catalog = $this->catalog($stream);
        $revision = $this->file($catalog, '1.7')->toRevision();
        $upgrade = $catalog->with(new Entry(new NameType('Version'), new NameType('2.0')));
        $result = (new IncrementalUpdate($revision, new FileBody(
            new IndirectObject($upgrade, $revision->binding($catalog)->identifier),
            new IndirectObject($this->stream('<xml>Révisé</xml>', new FlateDecode()), $revision->binding($stream)->identifier),
        )))->toRevision();
        self::assertStringStartsWith($revision->bytes, $result->bytes);
    }

    public function testRetainedEvidenceDoesNotReinspectOrReencodeAnOldStreamBuilder(): void
    {
        $stream = new class ('<xml>Révisé</xml>', $this->stream('')->dictionary(), new FlateDecode()) extends StreamObject {
            public bool $emitted = false;

            public function dictionary(): DictionaryType
            {
                if ($this->emitted) {
                    throw new \LogicException('The historical builder must not be inspected.');
                }
                return parent::dictionary();
            }

            public function bytes(SerializationContext $context): string
            {
                if ($this->emitted) {
                    throw new \LogicException('The historical builder must not be encoded again.');
                }
                return parent::bytes($context);
            }
        };
        $catalog = $this->catalog($stream);
        $revision = $this->file($catalog, '1.7')->toRevision();
        $stream->emitted = true;
        $upgrade = $catalog->with(new Entry(new NameType('Version'), new NameType('2.0')));
        $result = (new IncrementalUpdate($revision, new FileBody(new IndirectObject($upgrade, $revision->binding($catalog)->identifier))))->toRevision();
        self::assertStringStartsWith($revision->bytes, $result->bytes);
    }

    private function stream(string $bytes, Filter ...$filters): StreamObject
    {
        return new StreamObject($bytes, new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML'))), ...$filters);
    }

    private function catalog(StreamObject $metadata): DocumentCatalog
    {
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(10), new IntegerType(10)), new ResourceDictionary());
        return new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('Metadata'), new IndirectReference($metadata)));
    }

    private function file(DictionaryType $catalog, string $version, CrossReferenceFormat $format = CrossReferenceFormat::Table): FileStructure
    {
        return new FileStructure(new FileHeader($version), new FileBody($catalog), crossReferenceFormat: $format);
    }
}
