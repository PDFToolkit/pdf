<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Syntax\Object\NumericType;
use PDF\Document\DocumentGraphContext;
use PDF\Document\ResourceDictionary;
use PDF\Document\Files as F;
use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssociatedFilesTest extends TestCase
{
    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function external(?F\AssociatedFileRelationship $relationship = null): F\FileSpecification
    {
        return new F\FileSpecification(new LiteralString('not-loaded.csv'), relationship: $relationship ?? new F\AssociatedFileRelationship(F\AFRelationship::Data));
    }

    private function embedded(): F\FileSpecification
    {
        $stream = new F\EmbeddedFileStream("column\n42\n", new NameType('text/csv'), modificationDate: new Date(new \DateTimeImmutable('2026-10-14T12:00:00+02:00')));
        return new F\FileSpecification(new LiteralString('values.csv'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $stream))), new F\AssociatedFileRelationship(F\AFRelationship::Data));
    }

    private function file(DocumentCatalog $catalog, string $header = '2.0', CrossReferenceFormat $format = CrossReferenceFormat::Table): FileStructure
    {
        return new FileStructure(new FileHeader($header), new FileBody($catalog), new IndirectReference($catalog), crossReferenceFormat: $format);
    }

    private function validate(DictionaryType $owner, string $version = '2.0', ?FileBody $body = null, ?F\AssociatedFileOwner $ownerContext = null): void
    {
        $context = new SerializationContext();
        $context->prepareFile($body ?? new FileBody($owner), new IndirectReference($owner), null);
        $graph = new DocumentGraphContext($context, $owner);
        (new F\AssociatedFilesGraphValidation())->validate($graph->catalog, $graph, new EffectivePdfVersion(new FileHeader($version), $graph->catalog), $ownerContext);
    }

    /** @return iterable<string, array{F\AFRelationship}> */
    public static function relationships(): iterable
    {
        foreach (F\AFRelationship::cases() as $relationship) {
            yield $relationship->value => [$relationship];
        }
    }

    #[DataProvider('relationships')]
    public function testEveryStandardRelationshipIsAnExplicitName(F\AFRelationship $type): void
    {
        $relationship = new F\AssociatedFileRelationship($type);
        $file = $this->external($relationship);
        self::assertSame($relationship, $file->relationship);
        self::assertSame($type->value, $relationship->name->value);
        self::assertSame($relationship->name, $file->get('AFRelationship'));
    }

    public function testDeveloperDefinedRelationshipDoesNotRequireAnEnumAlias(): void
    {
        $name = new NameType('TOOL_CustomRelation');
        self::assertSame($name, (new F\AssociatedFileRelationship($name))->name);
    }

    public function testAdditionalFilenamePositionalArgumentsRetainTheirExistingSignature(): void
    {
        $name = new F\FileSpecificationName(F\EmbeddedFileKey::UnicodeFile, new TextString('données.csv'));
        $options = new F\FileSpecificationOptions(null, null, null, null, null, null, null, $name);
        $file = new F\FileSpecification(new LiteralString('data.csv'), $options, new F\AssociatedFileRelationship(F\AFRelationship::Source));
        self::assertSame($name->value, $file->get('UF'));
        self::assertSame([$name], $options->additionalNames);
    }

    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testEmbeddedAndExternalFilesCanBeAssociatedWithoutAnAttachmentTree(CrossReferenceFormat $format): void
    {
        $embedded = $this->embedded();
        $external = $this->external();
        $files = new F\AssociatedFiles(new IndirectReference($embedded), $external);
        $catalog = new DocumentCatalog($this->nonemptyPages(), new F\AssociatedFilesEntry($files));
        $revision = $this->file($catalog, format: $format)->toRevision();
        self::assertStringStartsWith('%PDF-2.0', $revision->bytes);
        self::assertStringContainsString('/AF [', $revision->bytes);
        self::assertStringContainsString('/AFRelationship /Data', $revision->bytes);
        self::assertStringContainsString('/Subtype /text#2Fcsv', $revision->bytes);
        self::assertFalse($catalog->has('Names'));
        self::assertCount(2, $files);
        self::assertSame($external, $files->items[1]);
    }

    public function testStandaloneRelationshipRetainsPrePdf20ExtensionUses(): void
    {
        $file = $this->external();
        $catalog = new DocumentCatalog($this->nonemptyPages(), $this->entry('PrivateFile', new IndirectReference($file)));
        self::assertStringStartsWith('%PDF-1.7', $this->file($catalog, '1.7')->toPdf());
    }

    public function testNativeAssociationRequiresPdf20(): void
    {
        $catalog = new DocumentCatalog($this->nonemptyPages(), new F\AssociatedFilesEntry(new F\AssociatedFiles($this->external())));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires PDF 2.0');
        $this->file($catalog, '1.7')->toPdf();
    }

    public function testCatalogVersionCanUpgradeTheHeaderForAnAssociation(): void
    {
        $catalog = new DocumentCatalog($this->nonemptyPages(), $this->entry('Version', new NameType('2.0')), new F\AssociatedFilesEntry(new F\AssociatedFiles($this->external())));
        self::assertStringContainsString('/Version /2.0', $this->file($catalog, '1.7')->toPdf());
    }

    public function testEmbeddedFileNeedsAnExplicitIndirectSpecification(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an indirect reference');
        new F\AssociatedFiles($this->embedded());
    }

    public function testEmptyNativeAssociationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new F\AssociatedFiles();
    }

    public function testNativeFilesRequireAnExplicitRelationship(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AFRelationship');
        new F\AssociatedFiles(new F\FileSpecification(new LiteralString('file.csv')));
    }

    /** @return iterable<string, array{string, ?string, ?int}> */
    public static function owners(): iterable
    {
        foreach (['Catalog', 'Page', 'StructElem', 'DPart', 'Annot', 'Metadata'] as $type) {
            yield $type => [$type, null, null];
        }
        yield 'image XObject' => ['XObject', 'Image', null];
        yield 'form default Type1' => ['XObject', 'Form', null];
        yield 'form explicit Type1' => ['XObject', 'Form', 1];
    }

    #[DataProvider('owners')]
    public function testEveryNormativeDictionaryOwnerChecksTheActualAssociation(string $type, ?string $subtype, ?int $formType): void
    {
        $entries = [$this->entry('Type', new NameType($type)), $this->entry('AF', new ArrayType($this->external()))];
        if ($subtype !== null) {
            $entries[] = $this->entry('Subtype', new NameType($subtype));
        }
        if ($formType !== null) {
            $entries[] = $this->entry('FormType', new IntegerType($formType));
        }
        $this->validate(new DictionaryType(...$entries));
        $entries[1] = $this->entry('AF', new ArrayType(new IntegerType(3)));
        $this->expectException(\InvalidArgumentException::class);
        $this->validate(new DictionaryType(...$entries));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        foreach (['non-array', 'empty-array', 'null-item', 'numeric-item', 'wrong-type', 'missing-filename', 'missing-relationship', 'numeric-relationship', 'empty-relationship'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('malformed')]
    public function testGenericMalformedAssociationsAreRejectedBeforeWriting(string $kind): void
    {
        $file = new DictionaryType($this->entry('Type', new NameType('Filespec')), $this->entry('F', new LiteralString('file.csv')), $this->entry('AFRelationship', new NameType('Data')));
        $array = match ($kind) {
            'non-array' => new NameType('invalid'),
            'empty-array' => new ArrayType(),
            'null-item' => new ArrayType(new NullType()),
            'numeric-item' => new ArrayType(new IntegerType(3)),
            'wrong-type' => new ArrayType($file->with($this->entry('Type', new NameType('Other')))),
            'missing-filename' => new ArrayType($file->without('F')),
            'missing-relationship' => new ArrayType($file->without('AFRelationship')),
            'numeric-relationship' => new ArrayType($file->with($this->entry('AFRelationship', new IntegerType(3)))),
            'empty-relationship' => new ArrayType($file->with($this->entry('AFRelationship', new NameType('')))),
            default => throw new \LogicException('Unknown fixture.'),
        };
        $catalog = new DocumentCatalog($this->nonemptyPages(), $this->entry('AF', $array));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testGenericExternalFilespecMayBeDirectAndOmitItsOptionalType(): void
    {
        $file = new DictionaryType($this->entry('F', new LiteralString('external.csv')), $this->entry('AFRelationship', new NameType('Data')));
        $owner = new DictionaryType($this->entry('Type', new NameType('Catalog')), $this->entry('AF', new ArrayType($file)));
        $this->validate($owner);
        self::assertFalse($file->has('EF'));
    }

    public function testIndirectRelationshipNameAndAfArrayAreResolved(): void
    {
        $file = new DictionaryType($this->entry('F', new LiteralString('external.csv')), $this->entry('AFRelationship', new IndirectReference(new NameType('Source'))));
        $owner = new DictionaryType($this->entry('Type', new NameType('Catalog')), $this->entry('AF', new IndirectReference(new ArrayType($file))));
        $this->validate($owner);
        self::assertFalse($file->has('EF'));
    }

    public function testDirectEmbeddedFilespecIsRejectedEvenWhenItHasABodyIdentity(): void
    {
        $file = $this->embedded();
        $owner = new DictionaryType($this->entry('Type', new NameType('Catalog')), $this->entry('AF', new ArrayType($file)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an indirect reference');
        $this->validate($owner, body: new FileBody($owner, $file));
    }

    /** @return iterable<string, array{string, string}> */
    public static function badEmbeddedMetadata(): iterable
    {
        yield 'missing MIME' => ['missing-mime', 'MIME Subtype'];
        yield 'MIME is not a name' => ['numeric-mime', 'MIME Subtype'];
        yield 'invalid MIME syntax' => ['invalid-mime', 'MIME Subtype'];
        yield 'invalid Params' => ['numeric-params', 'Params must be a dictionary'];
        yield 'Params lacks ModDate' => ['missing-date', 'source ModDate'];
        yield 'ModDate is not a string' => ['numeric-date', 'source ModDate'];
        yield 'ModDate text is not a date' => ['invalid-date', 'ModDate requires a valid PDF date'];
        yield 'ModDate is impossible' => ['impossible-date', 'ModDate requires a valid PDF date'];
    }

    #[DataProvider('badEmbeddedMetadata')]
    public function testActualEmbeddedAssociationMetadataIsValidated(string $kind, string $message): void
    {
        $entries = [$this->entry('Type', new NameType('EmbeddedFile'))];
        if ($kind !== 'missing-mime') {
            $entries[] = $this->entry('Subtype', match ($kind) {
                'numeric-mime' => new IntegerType(3),
                'invalid-mime' => new NameType('not-a-media-type'),
                default => new NameType('application/octet-stream'),
            });
        }
        $entries[] = $this->entry('Params', match ($kind) {
            'numeric-params' => new IntegerType(3),
            'missing-date' => new DictionaryType(),
            'numeric-date' => new DictionaryType($this->entry('ModDate', new IntegerType(3))),
            'invalid-date' => new DictionaryType($this->entry('ModDate', new LiteralString('not-a-date'))),
            'impossible-date' => new DictionaryType($this->entry('ModDate', new LiteralString('D:20260230'))),
            default => new DictionaryType($this->entry('ModDate', new Date(new \DateTimeImmutable('2026-10-14T12:00:00Z')))),
        });
        $stream = new StreamObject('original bytes', new DictionaryType(...$entries));
        $file = new DictionaryType($this->entry('Type', new NameType('Filespec')), $this->entry('F', new LiteralString('data.bin')), $this->entry('AFRelationship', new NameType('Source')), $this->entry('EF', new DictionaryType($this->entry('F', new IndirectReference($stream)))));
        $catalog = new DocumentCatalog($this->nonemptyPages(), $this->entry('AF', new ArrayType(new IndirectReference($file))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->file($catalog)->toPdf();
    }

    public function testOptionalParamsCanBeAbsentForAnAssociatedEmbeddedStream(): void
    {
        $stream = new StreamObject('original bytes', new DictionaryType($this->entry('Type', new NameType('EmbeddedFile')), $this->entry('Subtype', new NameType('application/octet-stream'))));
        $file = new DictionaryType($this->entry('Type', new NameType('Filespec')), $this->entry('F', new LiteralString('data.bin')), $this->entry('AFRelationship', new NameType('Source')), $this->entry('EF', new DictionaryType($this->entry('F', new IndirectReference($stream)))));
        $catalog = new DocumentCatalog($this->nonemptyPages(), $this->entry('AF', new ArrayType(new IndirectReference($file))));
        self::assertStringContainsString('/application#2Foctet-stream', $this->file($catalog)->toPdf());
    }

    public function testPriorEditionAfDoesNotAcquirePdf20Semantics(): void
    {
        $private = new DictionaryType($this->entry('AF', new IntegerType(3)));
        $owner = new DictionaryType($this->entry('Type', new NameType('Catalog')), $this->entry('AF', new IntegerType(3)));
        $this->validate($private, '1.7');
        $this->validate($owner, '1.7');
        self::assertCount(1, $private);
    }

    public function testIncrementalReplacementUsesTheEffectiveFilespecRatherThanTheFormerNativeObject(): void
    {
        $file = $this->embedded();
        $catalog = new DocumentCatalog($this->nonemptyPages(), new F\AssociatedFilesEntry(new F\AssociatedFiles(new IndirectReference($file))));
        $previous = $this->file($catalog)->toRevision();
        $replacement = new DictionaryType(...iterator_to_array($file->entries(), false));
        $replacement = $replacement->without('AFRelationship');
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($file)->identifier)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AFRelationship');
        $update->toRevision();
    }


    public function testOwnerContextSupportsTheOptionalAnnotationType(): void
    {
        $annotation = new DictionaryType($this->entry('Subtype', new NameType('Link')), $this->entry('Rect', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(20), new IntegerType(20))), $this->entry('AF', new ArrayType($this->external())));
        $this->validate($annotation, ownerContext: F\AssociatedFileOwner::Annotation);
        $this->expectException(\InvalidArgumentException::class);
        $this->validate($annotation->with($this->entry('AF', new ArrayType(new IntegerType(3)))), ownerContext: F\AssociatedFileOwner::Annotation);
    }

    public function testGenericAnnotationWithoutTypeIsValidatedThroughActualPageAnnots(): void
    {
        $annotation = new DictionaryType($this->entry('Subtype', new NameType('Link')), $this->entry('Rect', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(20), new IntegerType(20))), $this->entry('AF', new ArrayType(new IntegerType(3))));
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, $this->entry('Annots', new ArrayType(new IndirectReference($annotation))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('file specification dictionary');
        $this->file($catalog)->toPdf();
    }

    public function testEffectiveEmbeddedMimeCannotBeRemovedInAnIncrementalReplacement(): void
    {
        $file = $this->embedded();
        $bindings = $file->get('EF');
        self::assertInstanceOf(DictionaryType::class, $bindings);
        $reference = $bindings->get('F');
        self::assertInstanceOf(IndirectReference::class, $reference);
        $stream = $reference->target();
        self::assertInstanceOf(StreamObject::class, $stream);
        $catalog = new DocumentCatalog($this->nonemptyPages(), new F\AssociatedFilesEntry(new F\AssociatedFiles(new IndirectReference($file))));
        $previous = $this->file($catalog)->toRevision();
        $replacement = new StreamObject('replacement bytes', $stream->dictionary()->without('Subtype'));
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($stream)->identifier)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MIME Subtype');
        $update->toRevision();
    }


    public function testEffectiveModificationDateIsValidatedAfterAStreamReplacement(): void
    {
        $file = $this->embedded();
        $bindings = $file->get('EF');
        self::assertInstanceOf(DictionaryType::class, $bindings);
        $reference = $bindings->get('F');
        self::assertInstanceOf(IndirectReference::class, $reference);
        $stream = $reference->target();
        self::assertInstanceOf(StreamObject::class, $stream);
        $catalog = new DocumentCatalog($this->nonemptyPages(), new F\AssociatedFilesEntry(new F\AssociatedFiles(new IndirectReference($file))));
        $previous = $this->file($catalog)->toRevision();
        $dictionary = $stream->dictionary()->with($this->entry('Params', new DictionaryType($this->entry('ModDate', new LiteralString('D:20260230')))));
        $replacement = new StreamObject('replacement bytes', $dictionary);
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($stream)->identifier)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ModDate requires a valid PDF date');
        $update->toRevision();
    }

    public function testIncrementalCatalogUpgradeCanIntroduceAnAssociatedFile(): void
    {
        $catalog = new DocumentCatalog($this->nonemptyPages());
        $previous = $this->file($catalog, '1.7')->toRevision();
        $updated = $catalog->with($this->entry('Version', new NameType('2.0')))->with(new F\AssociatedFilesEntry(new F\AssociatedFiles($this->external())));
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($updated, $previous->binding($catalog)->identifier)));
        $revision = $update->toRevision();
        self::assertStringStartsWith($previous->bytes, $revision->bytes);
        self::assertStringContainsString('/Version /2.0', substr($revision->bytes, strlen($previous->bytes)));
    }
    private function nonemptyPages(): PageTreeNode
    {
        $box = new \PDF\Syntax\DataStructure\Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100));
        return (new PageTreeNode())->addKid(new \PDF\Syntax\DocumentStructure\PageTree\PageObject($box, new \PDF\Document\ResourceDictionary()));
    }

}
