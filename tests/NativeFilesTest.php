<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Collections as C;
use PDF\Document\DocumentGraphContext;
use PDF\Document\Files as F;
use PDF\FileStructure;
use PDF\Security as Sec;
use PDF\Serialization\FileWriter;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\NameDictionary;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\Filters\ExternalStreamFilters;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\ExternalStreamObject;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeFilesTest extends TestCase
{
    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    private function entry(string $name, \PDF\Syntax\FileStructure\BasicType $value): Entry
    {
        return new Entry(new NameType($name), $value);
    }

    private function file(DocumentCatalog $catalog, string $version = '1.7', CrossReferenceFormat $format = CrossReferenceFormat::Table): FileStructure
    {
        return new FileStructure(new FileHeader($version), new FileBody($catalog), new IndirectReference($catalog), crossReferenceFormat: $format);
    }

    private function path(F\FilePathKind $kind, string ...$parts): F\FileSpecificationString
    {
        return new F\FileSpecificationString($kind, ...array_map(static fn (string $part): F\FileNameComponent => new F\FileNameComponent(new LiteralString($part)), $parts));
    }

    public function testFilenameComponentsPreserveBinaryBytesAndEscapeLiteralSeparators(): void
    {
        $path = $this->path(F\FilePathKind::Absolute, 'Disk', 'in/out', 'back\\slash', "\x81\xff", '');
        self::assertSame("/Disk/in\\/out/back\\\\slash/\x81\xff/", $path->value);
        self::assertStringContainsString('in\\\\/out', $path->toPdf(new SerializationContext()));
    }

    /** @return iterable<string, array{list<string>, list<string>, string}> */
    public static function relativePaths(): iterable
    {
        yield 'same directory' => [['HD', 'docs', 'container.pdf'], ['attached.txt'], '/HD/docs/attached.txt'];
        yield 'parent directory' => [['HD', 'docs', 'container.pdf'], ['..', 'attached.txt'], '/HD/attached.txt'];
        yield 'successive parents' => [['HD', 'a', 'b', 'c.pdf'], ['..', '..', 'd.pdf'], '/HD/d.pdf'];
        yield 'empty components are retained' => [['HD', '', 'c.pdf'], ['d.pdf'], '/HD//d.pdf'];
        yield 'unmatched parents remain' => [['HD', 'c.pdf'], ['..', '..', 'd.pdf'], '/../d.pdf'];
    }

    /**
     * @param list<string> $base
     * @param list<string> $relative
     */
    #[DataProvider('relativePaths')]
    public function testRelativeResolutionUsesContainingDirectoryAndCancelsPrecedingComponents(array $base, array $relative, string $expected): void
    {
        self::assertSame($expected, $this->path(F\FilePathKind::Relative, ...$relative)->resolvedAgainst($this->path(F\FilePathKind::Absolute, ...$base))->value);
    }

    public function testAbsoluteFileDoesNotDependOnContainingFile(): void
    {
        $absolute = $this->path(F\FilePathKind::Absolute, 'HD', 'file.pdf');
        self::assertSame($absolute, $absolute->resolvedAgainst($this->path(F\FilePathKind::Relative, 'ignored.pdf')));
    }

    public function testMacCodesUseThePublishedBigEndianExample(): void
    {
        self::assertSame(1128354383, (new F\MacFileCode(new LiteralString('CARO')))->number->integer());
        $this->expectException(\InvalidArgumentException::class);
        new F\MacFileCode(new LiteralString("A\xffBC"));
    }

    #[DataProvider('formats')]
    public function testEmbeddedParametersDescribeUncompressedPayloadAndFrozenSnapshotDescribesEncoding(CrossReferenceFormat $format): void
    {
        $bytes = "plain\0binary\xff\n";
        $stream = new F\EmbeddedFileStream($bytes, new NameType('application/octet-stream'), filters: new FlateDecode());
        $specification = new F\FileSpecification(new LiteralString('data.bin'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $stream))));
        $tree = new F\EmbeddedFiles(new F\EmbeddedFileAttachment(new LiteralString('data'), $specification));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('Names', new NameDictionary($tree->nameDictionaryEntry())));
        $revision = $this->file($catalog, format: $format)->toRevision();
        $snapshot = $revision->binding($stream)->snapshot;
        self::assertInstanceOf(RevisionStreamSnapshot::class, $snapshot);
        self::assertSame($bytes, gzuncompress($snapshot->encodedBytes));
        $size = $stream->parameters->get('Size');
        $checksum = $stream->parameters->get('CheckSum');
        $filter = $snapshot->dictionary()->get('Filter');
        $length = $snapshot->dictionary()->get('Length');
        self::assertInstanceOf(IntegerType::class, $size);
        self::assertInstanceOf(\PDF\Syntax\Object\StringType::class, $checksum);
        self::assertInstanceOf(NameType::class, $filter);
        self::assertInstanceOf(IntegerType::class, $length);
        self::assertSame(strlen($bytes), $size->integer());
        self::assertSame(md5($bytes, true), $checksum->value);
        self::assertSame('FlateDecode', $filter->value);
        self::assertSame(strlen($snapshot->encodedBytes), $length->integer());
    }

    public function testUnicodeEmbeddedBindingRequiresItsCorrespondingFilename(): void
    {
        $stream = new F\EmbeddedFileStream('data');
        $this->expectException(\InvalidArgumentException::class);
        new F\FileSpecification(new LiteralString('data.bin'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::UnicodeFile, $stream))));
    }

    public function testRelatedFilesPreservePairedOrderAndRequireTheirEfScope(): void
    {
        $one = new F\EmbeddedFileStream('main');
        $two = new F\EmbeddedFileStream('companion');
        $ef = new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $one));
        $pairs = new F\RelatedFiles(new F\RelatedFile(new LiteralString('main.txt'), $one), new F\RelatedFile(new LiteralString('companion.txt'), $two));
        $file = new F\FileSpecification(new LiteralString('main.txt'), new F\FileSpecificationOptions($ef, new F\RelatedFileBindings($ef, new F\RelatedFileBinding(F\EmbeddedFileKey::File, $pairs))));
        self::assertCount(4, $pairs);
        self::assertInstanceOf(IndirectReference::class, $pairs->items[3]);
        self::assertSame($two, $pairs->items[3]->target());
        self::assertTrue($file->has('RF'));
        $this->expectException(\InvalidArgumentException::class);
        new F\RelatedFileBindings($ef, new F\RelatedFileBinding(F\EmbeddedFileKey::Mac, $pairs));
    }

    public function testGenericMalformedRelatedFilesAreRejectedBeforeWriting(): void
    {
        $stream = new F\EmbeddedFileStream('data');
        $specification = new DictionaryType(
            $this->entry('Type', new NameType('Filespec')),
            $this->entry('F', new LiteralString('data.txt')),
            $this->entry('EF', new DictionaryType($this->entry('F', new IndirectReference($stream)))),
            $this->entry('RF', new DictionaryType($this->entry('F', new ArrayType(new LiteralString('missing pair')))))
        );
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('PrivateFile', new IndirectReference($specification)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filename/embedded-stream pairs');
        $this->file($catalog)->toPdf();
    }

    public function testEmbeddedFileSpecificationCannotBeUsedDirectlyEvenIfAlsoRegisteredInBody(): void
    {
        $stream = new F\EmbeddedFileStream('data');
        $file = new F\FileSpecification(new LiteralString('data.txt'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $stream))));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('PrivateFile', $file));
        $document = new FileStructure(new FileHeader(), new FileBody($catalog, $file));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('indirectly referenced');
        $document->toPdf();
    }

    public function testExternalFiltersAreDeclarationsAndNeverExecuteAnEncoder(): void
    {
        $filter = new class () implements Filter {
            public function name(): NameType
            {
                return new NameType('FlateDecode');
            }
            public function parameters(): DictionaryType
            {
                return new DictionaryType(new Entry(new NameType('Predictor'), new IntegerType(1)));
            }
            public function encode(string $bytes): string
            {
                throw new \LogicException('External bytes must not be loaded or encoded.');
            }
        };
        $stream = new ExternalStreamObject(new LiteralString('file-that-does-not-exist.bin'), new ExternalStreamFilters($filter), "ignored\0bytes");
        $serialized = (new SerializationContext())->serializeIndirect($stream);
        self::assertStringContainsString('/FFilter /FlateDecode', $serialized);
        self::assertStringContainsString('/FDecodeParms', $serialized);
        self::assertStringContainsString('/Length 13', $serialized);
        self::assertStringContainsString("stream\nignored\0bytes\nendstream", $serialized);
        self::assertStringNotContainsString('/Filter ', $serialized);
    }

    public function testExternalIgnoredBytesStayClearWhileFilenameStringsAreEncrypted(): void
    {
        $encryption = (new Sec\HistoricalRevision5SecurityHandler(new Sec\OpenSslAes256Backend(), new Sec\SystemRandomSource()))->create(new Sec\PreparedPassword('user'), new Sec\PreparedPassword('owner'), new Sec\Revision5Permissions(-4), new Sec\EncryptMetadata(true));
        $context = new SerializationContext(new \PDF\Syntax\Object\IndirectObject\ObjectNumber(), $encryption);
        $stream = new ExternalStreamObject(new LiteralString('private-external-name.bin'), ignoredBytes: 'local ignored bytes');
        $serialized = $context->serializeIndirect($stream);
        self::assertStringContainsString("stream\nlocal ignored bytes\nendstream", $serialized);
        self::assertStringNotContainsString('private-external-name.bin', $serialized);
        self::assertStringContainsString('/Length 19', $serialized);
    }

    private function schema(C\CollectionFieldType $type = C\CollectionFieldType::Number): C\CollectionSchema
    {
        return new C\CollectionSchema(new C\CollectionSchemaField(new NameType('Amount'), new C\CollectionField($type, new TextString('Amount'))), new C\CollectionSchemaField(new NameType('Title'), new C\CollectionField(C\CollectionFieldType::Text, new TextString('Title'))));
    }

    public function testCollectionPrefixesDoNotBecomeNumericDataAndAnAbsentDataValueIsPermitted(): void
    {
        $item = new C\CollectionItem($this->schema(), new C\CollectionItemValue(new NameType('Amount'), new C\CollectionSubitem(prefix: new TextString('USD '))));
        $subitem = $item->get('Amount');
        self::assertInstanceOf(DictionaryType::class, $subitem);
        self::assertFalse($subitem->has('D'));
        $this->expectException(\InvalidArgumentException::class);
        new C\CollectionItem($this->schema(), new C\CollectionItemValue(new NameType('Amount'), new C\CollectionSubitem(new TextString('12'), new TextString('USD '))));
    }

    public function testSortBroadcastMissingAndExtraDirectionsFollowTable158(): void
    {
        $fields = new C\CollectionSortFields(new NameType('Amount'), new NameType('Title'));
        $broadcast = new C\CollectionSort($this->schema(), $fields, new BooleanType(false));
        self::assertFalse($broadcast->ascendingAt(0));
        self::assertFalse($broadcast->ascendingAt(1));
        $missing = new C\CollectionSort($this->schema(), $fields, new C\CollectionSortDirections(new BooleanType(false)));
        self::assertFalse($missing->ascendingAt(0));
        self::assertTrue($missing->ascendingAt(1));
        $extra = new C\CollectionSort($this->schema(), new NameType('Amount'), new C\CollectionSortDirections(new BooleanType(false), new BooleanType(true)));
        self::assertFalse($extra->ascendingAt(0));
    }

    /** @return array{DocumentCatalog, C\Collection, F\FileSpecification, C\CollectionItem} */
    private function collectionDocument(?string $initial = 'data'): array
    {
        $schema = $this->schema();
        $item = new C\CollectionItem($schema, new C\CollectionItemValue(new NameType('Amount'), new NumericType(12.5)));
        $file = new F\FileSpecification(new LiteralString('data.txt'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, new F\EmbeddedFileStream('data'))), collectionItem: $item));
        $attachments = new F\EmbeddedFiles(new F\EmbeddedFileAttachment(new LiteralString('data'), $file));
        $collection = new C\Collection($schema, initialDocument: $initial === null ? null : new LiteralString($initial));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('Collection', new IndirectReference($collection)), $this->entry('Names', new NameDictionary($attachments->nameDictionaryEntry())));
        return [$catalog, $collection, $file, $item];
    }

    public function testInitialCollectionFileUsesActualNameTreeAndUnknownKeyFallsBackToContainer(): void
    {
        foreach (['data', 'missing', null] as $key) {
            [$catalog, $collection, $file] = $this->collectionDocument($key);
            $context = new SerializationContext();
            $document = $this->file($catalog);
            self::assertInstanceOf(IndirectReference::class, $document->root);
            $context->prepareFile($document->body, $document->root);
            $initial = $collection->initialFile(new DocumentGraphContext($context, $catalog));
            if ($key === 'data') {
                self::assertInstanceOf(IndirectReference::class, $initial);
                self::assertSame($file, $initial->target());
            } else {
                self::assertNull($initial);
            }
            self::assertStringContainsString('/Collection', $document->toPdf());
        }
    }

    public function testHistoricalCollectionItemIsValidatedAgainstReplacementSchema(): void
    {
        [$catalog, $collection] = $this->collectionDocument();
        $revision = $this->file($catalog)->toRevision();
        $changed = new C\Collection($this->schema(C\CollectionFieldType::Text));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('wrong type');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($changed, $revision->binding($collection)->identifier))))->toRevision();
    }

    public function testReplacementCollectionItemCannotHaveWrongPrefixType(): void
    {
        [$catalog, , , $item] = $this->collectionDocument();
        $revision = $this->file($catalog)->toRevision();
        $changed = new DictionaryType($this->entry('Type', new NameType('CollectionItem')), $this->entry('Amount', new DictionaryType($this->entry('P', new IntegerType(4)))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prefix');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($changed, $revision->binding($item)->identifier))))->toRevision();
    }

    public function testCollectionRemovalAllowsUnreachableFormerNativeRoot(): void
    {
        [$catalog] = $this->collectionDocument();
        $revision = $this->file($catalog)->toRevision();
        $root = $revision->binding($catalog)->snapshot;
        self::assertInstanceOf(DictionaryType::class, $root);
        $removed = $root->without('Collection')->without('Names');
        $next = (new IncrementalUpdate($revision, new FileBody(new IndirectObject($removed, $revision->binding($catalog)->identifier))))->toRevision();
        self::assertSame($revision, $next->previous);
    }

    public function testOriginalFileSpecificationFIsAvailableInPdf11(): void
    {
        $file = new F\FileSpecification(new LiteralString('data.txt'));
        self::assertStringContainsString('/F (data.txt)', $this->file(new DocumentCatalog(new PageTreeNode(), $this->entry('PrivateFile', $file)), '1.1')->toPdf());
    }

    public function testUnicodeFilenameRequiresPdf17EvenForGenericSpecification(): void
    {
        $file = new DictionaryType($this->entry('Type', new NameType('Filespec')), $this->entry('F', new LiteralString('data.txt')), $this->entry('UF', new TextString('données.txt')));
        $this->expectException(\LogicException::class);
        $this->file(new DocumentCatalog(new PageTreeNode(), $this->entry('PrivateFile', $file)), '1.6')->toPdf();
    }

    public function testGenericDirectCollectionItemViolatesSourceIndirectRequirement(): void
    {
        $file = new DictionaryType($this->entry('Type', new NameType('Filespec')), $this->entry('F', new LiteralString('data.txt')), $this->entry('CI', new DictionaryType()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CI');
        $this->file(new DocumentCatalog(new PageTreeNode(), $this->entry('PrivateFile', $file)))->toPdf();
    }

    public function testNullEfAndRfAreOmittedRatherThanTriggeringEmbeddingConstraints(): void
    {
        $file = new DictionaryType($this->entry('Type', new NameType('Filespec')), $this->entry('F', new LiteralString('data.txt')), $this->entry('EF', new NullType()), $this->entry('RF', new NullType()));
        self::assertStringContainsString('/EF null', $this->file(new DocumentCatalog(new PageTreeNode(), $this->entry('PrivateFile', $file)), '1.1')->toPdf());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAttachmentTrees(): iterable
    {
        foreach (['unsorted keys', 'root limits', 'missing child limits', 'wrong leaf limits', 'overlapping child ranges'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('malformedAttachmentTrees')]
    public function testGenericAttachmentNameTreesRespectSourceOrderingAndLimits(string $kind): void
    {
        $file = new F\FileSpecification(new LiteralString('data.txt'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, new F\EmbeddedFileStream('data')))));
        $pairs = new ArrayType(new LiteralString('a'), new IndirectReference($file));
        $limits = new ArrayType(new LiteralString('a'), new LiteralString('a'));
        $leaf = new DictionaryType($this->entry('Names', $pairs), $this->entry('Limits', $limits));
        $tree = match ($kind) {
            'unsorted keys' => new DictionaryType($this->entry('Names', new ArrayType(new LiteralString('b'), new IndirectReference($file), new LiteralString('a'), new IndirectReference($file)))),
            'root limits' => $leaf,
            'missing child limits' => new DictionaryType($this->entry('Kids', new ArrayType(new IndirectReference($leaf->without('Limits'))))),
            'wrong leaf limits' => new DictionaryType($this->entry('Kids', new ArrayType(new IndirectReference($leaf->with($this->entry('Limits', new ArrayType(new LiteralString('b'), new LiteralString('b')))))))),
            default => new DictionaryType($this->entry('Kids', new ArrayType(new IndirectReference($leaf), new IndirectReference(clone $leaf)))),
        };
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('Names', new DictionaryType($this->entry('EmbeddedFiles', new IndirectReference($tree)))));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }
}
