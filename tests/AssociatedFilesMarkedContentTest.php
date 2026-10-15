<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Syntax\Object\NumericType;
use PDF\Document\DocumentGraphContext;
use PDF\Document\Files as F;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Properties;
use PDF\FileStructure;
use PDF\Graphics\XObjects\FormXObject;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as O;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
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
use PHPUnit\Framework\TestCase;

/** ISO_32000-2_sponsored_EC3.pdf, 14.13.5, PDF pp.854–855 and EC3 issue374/Table409a p.1015. */
final class AssociatedFilesMarkedContentTest extends TestCase
{
    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function rectangle(): Rectangle
    {
        return new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200));
    }

    private function external(): F\FileSpecification
    {
        return new F\FileSpecification(new LiteralString('application-supplied.csv'), relationship: new F\AssociatedFileRelationship(F\AFRelationship::Data));
    }

    private function embedded(bool $mime = true, bool $date = true): F\FileSpecification
    {
        $stream = new F\EmbeddedFileStream("value\n42\n", $mime ? new NameType('text/csv') : null, modificationDate: $date ? new Date(new \DateTimeImmutable('2026-10-14T12:00:00+02:00')) : null);
        return new F\FileSpecification(new LiteralString('data.csv'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $stream))), new F\AssociatedFileRelationship(F\AFRelationship::Data));
    }

    private function list(?F\FileSpecification $file = null): F\AssociatedFilesPropertyList
    {
        return new F\AssociatedFilesPropertyList(new F\AssociatedFiles(new IndirectReference($file ?? $this->embedded())));
    }

    private function resources(BasicType $property): ResourceDictionary
    {
        return new ResourceDictionary(new Properties(new DictionaryType($this->entry('MF1', $property))));
    }

    private function content(): ContentStreams
    {
        return new ContentStreams(new F\BeginAssociatedFilesMarkedContent(new NameType('MF1')), new O\EndMarkedContent());
    }

    private function validate(ContentStreams $content, DictionaryType $resources, string $header = '2.0'): void
    {
        $catalog = new DocumentCatalog($this->nonemptyPages());
        $context = new SerializationContext();
        $context->prepareFile(new FileBody($catalog, $resources), new IndirectReference($catalog));
        $graph = new DocumentGraphContext($context, $catalog);
        (new F\AssociatedFilesMarkedContentGraphValidation())->validate($content, $graph->dictionary($resources) ?? throw new \LogicException('Missing resources.'), $graph, new EffectivePdfVersion(new FileHeader($header), $catalog));
    }

    private function file(ResourceDictionary $resources, ?ContentStreams $content = null, string $header = '2.0'): FileStructure
    {
        $pages = new PageTreeNode($this->entry('Resources', new IndirectReference($resources)));
        $pages->addKid(new PageObject($this->rectangle(), contents: new StreamObject($content ?? $this->content())));
        $catalog = new DocumentCatalog($pages);
        return new FileStructure(new FileHeader($header), new FileBody($catalog));
    }

    public function testNativePropertyListRetainsReviewedTable409aProvenance(): void
    {
        $list = $this->list($this->external());
        self::assertSame($list->files, $list->get('MCAF'));
        self::assertSame('409a', $list->definition()->source->table);
        self::assertSame(1015, $list->definition()->source->pdfPage);
        self::assertSame('ISO_32000-2_sponsored_EC3.pdf', $list->definition()->source->document);
        self::assertSame(374, $list->definition()->entry('MCAF')->errata?->issueNumber);
        self::assertSame('Completed', $list->definition()->entry('MCAF')->errata?->reviewStatus->value);
    }

    public function testNativeOperatorWritesANameInsteadOfInlineFileSpecifications(): void
    {
        self::assertSame('/AF /MF1 BDC', (string) new F\BeginAssociatedFilesMarkedContent(new NameType('MF1')));
        self::assertSame('/AF /data#20set BDC', (string) new F\BeginAssociatedFilesMarkedContent(new NameType('data set')));
        self::assertSame("/AF /MF1 BDC\nEMC\n", (string) $this->content());
    }

    public function testPropertyListExtensionsReturnIndependentObjects(): void
    {
        $original = $this->list($this->external());
        $changed = $original->with($this->entry('Custom', new IntegerType(7)));
        self::assertFalse($original->has('Custom'));
        self::assertSame(7, $changed->get('Custom')->value);
        self::assertSame($original->files, $changed->files);
        self::assertFalse($changed->without('Custom')->has('Custom'));
    }

    public function testPropertyListCannotRemoveItsAssociation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->list($this->external())->without('MCAF');
    }

    public function testPropertyListCannotReplaceItsConstructorControlledAssociation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->list($this->external())->with($this->entry('MCAF', new ArrayType()));
    }

    public function testPropertyListConstructorRejectsASecondMcafEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new F\AssociatedFilesPropertyList(new F\AssociatedFiles($this->external()), $this->entry('MCAF', new ArrayType()));
    }

    public function testNamedPropertyCanBeDirectOrIndirect(): void
    {
        $list = $this->list();
        foreach ([$list, new IndirectReference($list)] as $value) {
            $this->validate($this->content(), $this->resources($value));
        }
        $this->addToAssertionCount(2);
    }

    public function testPropertyResourceCategoryCanBeIndirect(): void
    {
        $resources = new ResourceDictionary(new Properties(new IndirectReference(new DictionaryType($this->entry('MF1', $this->list())))));
        $this->validate($this->content(), $resources);
        $this->addToAssertionCount(1);
    }

    public function testExternalSpecificationsCanStayDirectInTheNamedResource(): void
    {
        $list = new F\AssociatedFilesPropertyList(new F\AssociatedFiles($this->external()));
        $this->validate($this->content(), $this->resources($list));
        $this->addToAssertionCount(1);
    }

    public function testDeclaredAssociationRequiresPdf20(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires PDF 2.0');
        $this->validate($this->content(), $this->resources($this->list()), '1.7');
    }

    public function testNamedMcafInTheGenericBdcOperatorAlsoRequiresPdf20(): void
    {
        $content = new ContentStreams(new O\BeginMarkedContentWithProperties(new NameType('AF'), new NameType('MF1')), new O\EndMarkedContent());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires PDF 2.0');
        $this->validate($content, $this->resources($this->list()), '1.7');
    }

    public function testDeclaredAssociationRequiresItsActualResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing');
        $this->validate($this->content(), new ResourceDictionary());
    }

    public function testDeclaredAssociationCannotBindAnUnrelatedPropertyDictionary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires MCAF');
        $this->validate($this->content(), $this->resources(new DictionaryType($this->entry('Other', new IntegerType(1)))));
    }

    public function testNamedResourceMustResolveToADictionary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not resolve to a dictionary');
        $this->validate($this->content(), $this->resources(new IntegerType(3)));
    }

    public function testInlineMcafIsRefusedEvenForDirectExternalFiles(): void
    {
        $inline = new DictionaryType($this->entry('MCAF', new ArrayType($this->external())));
        $content = new ContentStreams(new O\BeginMarkedContentWithProperties(new NameType('AF'), $inline), new O\EndMarkedContent());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('named Properties resource');
        $this->validate($content, new ResourceDictionary());
    }

    public function testAnAssociationCannotUseAMarkedContentPoint(): void
    {
        $content = new ContentStreams(new O\MarkedContentPointWithProperties(new NameType('AF'), new NameType('MF1')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires BDC and EMC');
        $this->validate($content, $this->resources($this->list()));
    }

    public function testOtherMeaningsOfAfRemainAvailable(): void
    {
        $other = new DictionaryType($this->entry('Other', new IntegerType(7)), $this->entry('MCAF', new NullType()));
        foreach (['1.7', '2.0'] as $header) {
            $inline = new ContentStreams(new O\BeginMarkedContentWithProperties(new NameType('AF'), $other), new O\EndMarkedContent(), new O\BeginMarkedContent(new NameType('AF')), new O\EndMarkedContent(), new O\MarkedContentPoint(new NameType('AF')), new O\MarkedContentPointWithProperties(new NameType('AF'), $other));
            $named = new ContentStreams(new O\BeginMarkedContentWithProperties(new NameType('AF'), new NameType('MF1')), new O\EndMarkedContent(), new O\MarkedContentPointWithProperties(new NameType('AF'), new NameType('MF1')));
            $this->validate($inline, new ResourceDictionary(), $header);
            $this->validate($named, $this->resources($other), $header);
        }
        $this->addToAssertionCount(4);
    }

    public function testMcafResourceArrayMustBeNonempty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCAF requires a nonempty array');
        $this->validate($this->content(), $this->resources(new DictionaryType($this->entry('MCAF', new ArrayType()))));
    }

    public function testMcafDoesNotAcceptAStringInsteadOfAnArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCAF requires an array');
        $this->validate($this->content(), $this->resources(new DictionaryType($this->entry('MCAF', new LiteralString('file.csv')))));
    }

    public function testMcafItemsMustBeFileSpecifications(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Every MCAF item');
        $this->validate($this->content(), $this->resources(new DictionaryType($this->entry('MCAF', new ArrayType(new IntegerType(4))))));
    }

    public function testMcafRelationshipReportsCompletedIssue374(): void
    {
        $file = new DictionaryType($this->entry('Type', new NameType('Filespec')), $this->entry('F', new LiteralString('external.csv')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EC3 issue374, Completed');
        $this->validate($this->content(), $this->resources(new DictionaryType($this->entry('MCAF', new ArrayType($file)))));
    }

    public function testEmbeddedMcafFilesNeedIndirectSpecifications(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an indirect reference');
        $this->validate($this->content(), $this->resources(new DictionaryType($this->entry('MCAF', new ArrayType($this->embedded())))));
    }

    public function testEmbeddedMcafFilesNeedMimeSubtype(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MIME Subtype');
        $this->validate($this->content(), $this->resources($this->list($this->embedded(mime: false))));
    }

    public function testEmbeddedMcafParametersNeedModificationDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source ModDate');
        $this->validate($this->content(), $this->resources($this->list($this->embedded(date: false))));
    }

    public function testPageContentUsesInheritedNamedResourcesInTheWriter(): void
    {
        $resources = $this->resources($this->list());
        $pdf = $this->file($resources)->toPdf();
        self::assertStringContainsString('/MCAF [', $pdf);
        self::assertStringContainsString("/AF /MF1 BDC\nEMC", $pdf);
        self::assertStringNotContainsString('/AF [', $pdf);
    }

    public function testPageWriterRefusesANativeMissingResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing');
        $this->file(new ResourceDictionary())->toPdf();
    }

    public function testFormWriterChecksItsOwnNamedResources(): void
    {
        $form = new FormXObject($this->content(), $this->rectangle(), $this->resources($this->list()));
        $file = new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($this->nonemptyPages()), $form));
        self::assertStringContainsString("/AF /MF1 BDC\nEMC", $file->toPdf());
    }

    public function testFormWriterRefusesANativeMissingResource(): void
    {
        $form = new FormXObject($this->content(), $this->rectangle(), new ResourceDictionary());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing');
        (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($this->nonemptyPages()), $form)))->toPdf();
    }

    public function testOneContentStreamIsValidatedInEveryPageResourceContext(): void
    {
        $stream = new StreamObject($this->content());
        $pages = new PageTreeNode();
        $pages->addKid(new PageObject($this->rectangle(), $this->resources($this->list()), $stream));
        $pages->addKid(new PageObject($this->rectangle(), $this->resources(new DictionaryType($this->entry('MCAF', new ArrayType()))), $stream));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCAF requires a nonempty array');
        (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($pages))))->toPdf();
    }

    public function testReplacingResourcesRechecksUnchangedHistoricalPageContents(): void
    {
        $resources = $this->resources($this->list());
        $base = $this->file($resources)->toRevision();
        $replacement = $this->resources(new DictionaryType($this->entry('MCAF', new ArrayType())));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCAF requires a nonempty array');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($resources)->identifier))))->toRevision();
    }

    public function testReplacingTheFileSpecificationRechecksHistoricalMcafRelationships(): void
    {
        $file = $this->embedded();
        $base = $this->file($this->resources($this->list($file)))->toRevision();
        $replacement = $base->binding($file)->dictionary?->without('AFRelationship') ?? throw new \LogicException('Missing filespec.');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EC3 issue374, Completed');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($file)->identifier))))->toRevision();
    }

    public function testAnUnstagedMutablePropertyBuilderCannotChangeItsHistoricalAssociation(): void
    {
        $file = $this->external();
        $property = new class ($file) extends DictionaryType {
            public ArrayType $files;
            public function __construct(F\FileSpecification $file)
            {
                $this->files = new ArrayType($file);
                parent::__construct();
            }
            public function entries(): iterable
            {
                yield new Entry(new NameType('MCAF'), $this->files);
            }
        };
        $base = $this->file($this->resources(new IndirectReference($property)))->toRevision();
        $property->files = new ArrayType();
        $next = (new IncrementalUpdate($base, new FileBody(new IntegerType(7))))->toRevision();
        self::assertStringStartsWith($base->bytes, $next->bytes);
        self::assertCount(1, $base->binding($property)->dictionary?->get('MCAF'));
    }

    public function testGenericFormCannotDeclareAnAssociationWithoutIndependentResources(): void
    {
        $dictionary = new DictionaryType($this->entry('Type', new NameType('XObject')), $this->entry('Subtype', new NameType('Form')), $this->entry('BBox', $this->rectangle()));
        $form = new StreamObject($this->content(), $dictionary);
        $this->expectException(\InvalidArgumentException::class);
        (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($this->nonemptyPages()), $form)))->toPdf();
    }

    public function testGenericFormWithoutAnAssociationKeepsHistoricalResourceFallback(): void
    {
        $dictionary = new DictionaryType($this->entry('Type', new NameType('XObject')), $this->entry('Subtype', new NameType('Form')), $this->entry('BBox', $this->rectangle()));
        $content = new ContentStreams(new O\BeginMarkedContentWithProperties(new NameType('AF'), new NameType('Other')), new O\EndMarkedContent());
        $form = new StreamObject($content, $dictionary);
        self::assertStringStartsWith('%PDF-1.7', (new FileStructure(new FileHeader(), new FileBody(new DocumentCatalog($this->nonemptyPages()), $form)))->toPdf());
    }

    public function testReplacingTheEmbeddedStreamRechecksHistoricalMcafMime(): void
    {
        $file = $this->embedded();
        $stream = $file->get('EF')->get('F')->target();
        $base = $this->file($this->resources($this->list($file)))->toRevision();
        $dictionary = $base->binding($stream)->dictionary?->without('Length')->without('Subtype') ?? throw new \LogicException('Missing embedded stream.');
        $replacement = new StreamObject('replacement', $dictionary);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MIME Subtype');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($stream)->identifier))))->toRevision();
    }

    private function mutableOtherProperty(): DictionaryType
    {
        return new class ($this->external()) extends DictionaryType {
            public bool $associated = false;
            public function __construct(private readonly F\FileSpecification $file)
            {
                parent::__construct();
            }
            public function entries(): iterable
            {
                yield $this->associated
                    ? new Entry(new NameType('MCAF'), new ArrayType($this->file))
                    : new Entry(new NameType('Other'), new IntegerType(7));
            }
        };
    }

    public function testEmittedInlineOtherAfFactsIgnoreLaterUnstagedBuilderMutation(): void
    {
        $property = $this->mutableOtherProperty();
        $content = new ContentStreams(new O\BeginMarkedContentWithProperties(new NameType('AF'), $property), new O\EndMarkedContent());
        $file = $this->file(new ResourceDictionary(), $content);
        $base = $file->toRevision();
        $stream = $file->body->objects()[0]->get('Pages')->target()->kids()[0]->get('Contents')->target();
        $facts = $base->binding($stream)->snapshot->markedContentFacts;
        self::assertInstanceOf(F\AssociatedFilesMarkedContentFacts::class, $facts);
        self::assertFalse($facts->declaresAssociation());
        self::assertSame(7, $facts->uses[0]->properties->get('Other')->value);
        $property->associated = true;
        $next = (new IncrementalUpdate($base, new FileBody(new IntegerType(7))))->toRevision();
        self::assertStringStartsWith($base->bytes, $next->bytes);
        self::assertFalse($facts->uses[0]->properties->has('MCAF'));
    }

    public function testStagingMutatedInlineAfContentChecksItsNewAssociation(): void
    {
        $property = $this->mutableOtherProperty();
        $content = new ContentStreams(new O\BeginMarkedContentWithProperties(new NameType('AF'), $property), new O\EndMarkedContent());
        $file = $this->file(new ResourceDictionary(), $content);
        $base = $file->toRevision();
        $stream = $file->body->objects()[0]->get('Pages')->target()->kids()[0]->get('Contents')->target();
        $property->associated = true;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('named Properties resource');
        (new IncrementalUpdate($base, new FileBody($stream)))->toRevision();
    }

    public function testAFilterCallbackCannotChangeFactsForAlreadyEncodedNativeOperands(): void
    {
        $property = $this->mutableOtherProperty();
        $content = new ContentStreams(new O\BeginMarkedContentWithProperties(new NameType('AF'), $property), new O\EndMarkedContent());
        $filter = new class ($property) implements \PDF\Syntax\Filters\Filter {
            public function __construct(private readonly DictionaryType $property)
            {
            }
            public function name(): NameType
            {
                return new NameType('ASCIIHexDecode');
            }
            public function parameters(): ?DictionaryType
            {
                return null;
            }
            public function encode(string $bytes): string
            {
                $this->property->associated = true;
                return (new \PDF\Syntax\Filters\ASCIIHexDecode())->encode($bytes);
            }
        };
        $stream = new StreamObject($content, null, $filter);
        $pages = new PageTreeNode();
        $pages->addKid(new PageObject($this->rectangle(), new ResourceDictionary(), $stream));
        $base = (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($pages))))->toRevision();
        $snapshot = $base->binding($stream)->snapshot;
        self::assertInstanceOf(\PDF\Syntax\FileStructure\RevisionStreamSnapshot::class, $snapshot);
        self::assertSame("/AF << /Other 7 >> BDC\nEMC\n", hex2bin(substr($snapshot->encodedBytes, 0, -1)));
        self::assertFalse($snapshot->markedContentFacts->declaresAssociation());
        self::assertTrue($property->associated);
        self::assertStringStartsWith($base->bytes, (new IncrementalUpdate($base, new FileBody(new IntegerType(9))))->toRevision()->bytes);
    }

    public function testCallerDefinedContentBytesDoNotAcquireInventedNativeEmissionFacts(): void
    {
        $content = new readonly class (new F\BeginAssociatedFilesMarkedContent(new NameType('MF1')), new O\EndMarkedContent()) extends ContentStreams {
            public function toPdf(SerializationContext $context): string
            {
                return "/Other BMC\nEMC\n";
            }
        };
        $file = $this->file($this->resources($this->list()), $content);
        $revision = $file->toRevision();
        $stream = $file->body->objects()[0]->get('Pages')->target()->kids()[0]->get('Contents')->target();
        self::assertSame("/Other BMC\nEMC\n", $revision->binding($stream)->snapshot->encodedBytes);
        self::assertNull($revision->binding($stream)->snapshot->markedContentFacts);
    }

    public function testHistoricalFormUsesFrozenInlineAfFactsAfterBuilderMutation(): void
    {
        $property = $this->mutableOtherProperty();
        $content = new ContentStreams(new O\BeginMarkedContentWithProperties(new NameType('AF'), $property), new O\EndMarkedContent());
        $form = new FormXObject($content, $this->rectangle(), new ResourceDictionary());
        $base = (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($this->nonemptyPages()), $form)))->toRevision();
        $property->associated = true;
        $next = (new IncrementalUpdate($base, new FileBody(new IntegerType(7))))->toRevision();
        self::assertStringStartsWith($base->bytes, $next->bytes);
        self::assertFalse($base->binding($form)->snapshot->markedContentFacts->declaresAssociation());
    }

    public function testHistoricalFormChecksReplacedIndirectResources(): void
    {
        $resources = $this->resources($this->list());
        $dictionary = new DictionaryType($this->entry('Type', new NameType('XObject')), $this->entry('Subtype', new NameType('Form')), $this->entry('BBox', $this->rectangle()), $this->entry('Resources', new IndirectReference($resources)));
        $form = new StreamObject($this->content(), $dictionary);
        $base = (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($this->nonemptyPages()), $form)))->toRevision();
        $replacement = $this->resources(new DictionaryType($this->entry('MCAF', new ArrayType())));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCAF requires a nonempty array');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($resources)->identifier))))->toRevision();
    }

    public function testAnIncrementalFormCannotRemoveItsOwnPdf20Resources(): void
    {
        $form = new FormXObject($this->content(), $this->rectangle(), $this->resources($this->list()));
        $base = (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($this->nonemptyPages()), $form)))->toRevision();
        $dictionary = $base->binding($form)->dictionary?->without('Length')->without('Resources') ?? throw new \LogicException('Missing form.');
        $replacement = new StreamObject($form->data, $dictionary);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('own Resources');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($form)->identifier))))->toRevision();
    }
    private function nonemptyPages(): PageTreeNode
    {
        $box = new \PDF\Syntax\DataStructure\Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100));
        return (new PageTreeNode())->addKid(new \PDF\Syntax\DocumentStructure\PageTree\PageObject($box, new \PDF\Document\ResourceDictionary()));
    }

}
