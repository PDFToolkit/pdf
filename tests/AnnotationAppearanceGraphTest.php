<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Annotations\AnnotationAppearanceGraphValidation;
use PDF\Document\Annotations\AnnotationPageIndex;
use PDF\Document\DocumentGraphContext;
use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Navigation\NavigationLimits;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Specification\Iso32000_2\Definition\AnnotationDictionaryTable166Definition;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
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
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ISO2 Table166/170 pp483/490–491 and exact EC3 #22/#124. */
final class AnnotationAppearanceGraphTest extends TestCase
{
    private function e(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    /** @param list<int> $coordinates */
    private function box(array $coordinates = [0, 0, 20, 20]): Rectangle
    {
        return new Rectangle(...array_map(static fn (int $value): NumericType => new NumericType($value), $coordinates));
    }

    private function form(): FormAppearanceStream
    {
        return new FormAppearanceStream(new ContentStreams(), $this->box(), new DictionaryType());
    }

    private function ap(?BasicType $normal = null, Entry ...$entries): DictionaryType
    {
        return new DictionaryType($this->e('N', $normal ?? new IndirectReference($this->form())), ...$entries);
    }

    private function annotation(Entry ...$entries): DictionaryType
    {
        return new DictionaryType($this->e('Subtype', new NameType('Square')), $this->e('Rect', $this->box()), ...$entries);
    }

    private function catalog(DictionaryType ...$annotations): DocumentCatalog
    {
        $page = new PageObject($this->box([0, 0, 100, 100]), new ResourceDictionary(), null, $this->e('Annots', new ArrayType(...array_map(static fn (DictionaryType $annotation): IndirectReference => new IndirectReference($annotation), $annotations))));
        return new DocumentCatalog((new PageTreeNode())->addKid($page));
    }

    private function file(DictionaryType $annotation, string $version = '2.0', CrossReferenceFormat $format = CrossReferenceFormat::Table): FileStructure
    {
        return new FileStructure(new FileHeader($version), new FileBody($this->catalog($annotation)), crossReferenceFormat: $format);
    }

    /** @return iterable<string,array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testPdf20RequiresAnAppearanceWherePdf17StillAllowsItsAbsence(CrossReferenceFormat $format): void
    {
        self::assertStringContainsString('/Subtype /Square', $this->file($this->annotation(), '1.7', $format)->toPdf());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PDF 2.0 annotations require AP');
        $this->file($this->annotation(), format: $format)->toRevision();
    }

    public function testSourceMetadataRetainsThePdf20ConditionalApRequirement(): void
    {
        $entry = (new AnnotationDictionaryTable166Definition())->entry('AP');
        self::assertTrue($entry->requirement->required);
        self::assertTrue($entry->requirement->conditional);
        self::assertFalse($entry->requirement->unconditional());
        self::assertStringContainsString('PDF 2.0', $entry->requirement->wording ?? '');
    }

    /** @return iterable<string,array{list<int>,bool}> */
    public static function rectangles(): iterable
    {
        yield 'origin point' => [[0, 0, 0, 0], true];
        yield 'translated negative point' => [[-20, 7, -20, 7], true];
        yield 'vertical line' => [[4, 1, 4, 20], false];
        yield 'reversed vertical line' => [[4, 20, 4, 1], false];
        yield 'horizontal line' => [[1, 4, 20, 4], false];
        yield 'reversed horizontal line' => [[20, 4, 1, 4], false];
        yield 'positive area' => [[0, 0, 20, 30], false];
        yield 'reversed corners' => [[20, 30, -5, -10], false];
    }

    /** @param list<int> $coordinates */
    #[DataProvider('rectangles')]
    public function testOnlyBothCollapsedDimensionsGiveThePointException(array $coordinates, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('require AP');
        }
        $bytes = $this->file($this->annotation()->with($this->e('Rect', $this->box($coordinates))))->toPdf();
        self::assertStringNotContainsString('/AP ', $bytes);
    }

    /** @return iterable<string,array{string}> */
    public static function exemptTypes(): iterable
    {
        yield 'link' => ['Link'];
        yield 'projection' => ['Projection'];
    }

    #[DataProvider('exemptTypes')]
    public function testSubtypeExceptionsDoNotRequireAnAppearance(string $subtype): void
    {
        self::assertStringNotContainsString('/AP ', $this->file($this->annotation()->with($this->e('Subtype', new NameType($subtype))))->toPdf());
    }

    /** @return iterable<string,array{list<int>}> */
    public static function collapsedProjections(): iterable
    {
        yield 'width zero' => [[4, 1, 4, 20]];
        yield 'height zero' => [[1, 4, 20, 4]];
        yield 'both zero' => [[4, 1, 4, 1]];
        yield 'reversed width zero' => [[4, 20, 4, 1]];
        yield 'reversed height zero' => [[20, 4, 1, 4]];
    }

    /** @param list<int> $coordinates */
    #[DataProvider('collapsedProjections')]
    public function testProjectionWithEitherCollapsedDimensionForbidsEvenAValidAp(array $coordinates): void
    {
        $annotation = $this->annotation($this->e('AP', $this->ap()))->with($this->e('Subtype', new NameType('Projection')))->with($this->e('Rect', $this->box($coordinates)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zero width or height shall not have AP');
        $this->file($annotation)->toRevision();
    }

    public function testNondegenerateProjectionMaySupplyAnAppearance(): void
    {
        self::assertStringContainsString('/AP ', $this->file($this->annotation($this->e('AP', $this->ap()))->with($this->e('Subtype', new NameType('Projection'))))->toPdf());
    }

    public function testAnAssociatedPopupHasNoAppearanceWhileItsMarkupParentHasOne(): void
    {
        $popup = $this->annotation()->with($this->e('Subtype', new NameType('Popup')));
        $parent = $this->annotation($this->e('AP', $this->ap()), $this->e('Popup', new IndirectReference($popup)));
        $catalog = $this->catalog($parent, $popup);
        self::assertStringContainsString('/Subtype /Popup', (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf());
    }

    /** @return iterable<string,array{string}> */
    public static function stateSlots(): iterable
    {
        yield 'normal' => ['N'];
        yield 'rollover' => ['R'];
        yield 'down' => ['D'];
    }

    #[DataProvider('stateSlots')]
    public function testAnyAppearanceStateDictionaryRequiresAS(string $slot): void
    {
        $ap = $this->ap()->with($this->e($slot, new DictionaryType($this->e('On', new IndirectReference($this->form())))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires AS');
        $this->file($this->annotation($this->e('AP', $ap)))->toRevision();
    }

    public function testUnmatchedStateRemainsLegalAndIsNotRewrittenToAnAvailableState(): void
    {
        $ap = $this->ap(new DictionaryType($this->e('On', new IndirectReference($this->form()))));
        $bytes = $this->file($this->annotation($this->e('AP', new IndirectReference($ap)), $this->e('AS', new IndirectReference(new NameType('Missing')))))->toPdf();
        self::assertStringContainsString('/Missing', $bytes);
        self::assertStringContainsString('/On ', $bytes);
    }

    public function testEmptyOrNullStateDefinitionsRetainTheSourceConsumerFallback(): void
    {
        $ap = $this->ap(new DictionaryType($this->e('Off', new NullType())));
        self::assertStringContainsString('/AS /Missing', $this->file($this->annotation($this->e('AP', $ap), $this->e('AS', new NameType('Missing'))))->toPdf());
    }

    public function testOptionalRolloverAndDownNullsAreAbsentAndPrivateApKeysAreNotAppearanceSlots(): void
    {
        $ap = $this->ap(null, $this->e('R', new NullType()), $this->e('D', new NullType()), $this->e('Private', new IntegerType(42)));
        self::assertStringContainsString('/Private 42', $this->file($this->annotation($this->e('AP', $ap)))->toPdf());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidAppearances(): iterable
    {
        foreach (['AP scalar', 'AP stream', 'N absent', 'N null', 'N number', 'state value dictionary', 'AS number', 'Form Type', 'Form Subtype', 'Form BBox', 'Form Matrix', 'FormType'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidAppearances')]
    public function testMalformedActualAppearanceGraphsAreRejected(string $case): void
    {
        $form = $this->form();
        $ap = $this->ap(new IndirectReference($form));
        $annotation = $this->annotation($this->e('AP', $ap));
        if (str_starts_with($case, 'Form')) {
            $dictionary = $form->dictionary();
            $dictionary = match ($case) {
                'Form Type' => $dictionary->with($this->e('Type', new NameType('Image'))),
                'Form Subtype' => $dictionary->with($this->e('Subtype', new NameType('Image'))),
                'Form BBox' => $dictionary->with($this->e('BBox', new ArrayType(new IntegerType(0)))),
                'Form Matrix' => $dictionary->with($this->e('Matrix', new ArrayType(new IntegerType(1)))),
                default => $dictionary->with($this->e('FormType', new IntegerType(2))),
            };
            $ap = $this->ap(new IndirectReference(new StreamObject('', $dictionary)));
        } else {
            $ap = match ($case) {
                'AP scalar' => new NameType('NotDictionary'),
                'AP stream' => new IndirectReference($form),
                'N absent' => new DictionaryType(),
                'N null' => $this->ap(new NullType()),
                'N number' => $this->ap(new IntegerType(1)),
                'state value dictionary' => $this->ap(new DictionaryType($this->e('On', new DictionaryType()))),
                default => $ap,
            };
        }
        $annotation = $annotation->with($this->e('AP', $ap));
        if ($case === 'state value dictionary' || $case === 'AS number') {
            $annotation = $annotation->with($this->e('AS', $case === 'AS number' ? new IntegerType(1) : new NameType('On')));
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->file($annotation)->toRevision();
    }

    public function testAFormMayOmitItsOptionalTypeAndUseAValidSingularMatrix(): void
    {
        $dictionary = $this->form()->dictionary()->without('Type')->with($this->e('Matrix', new ArrayType(...array_fill(0, 6, new IntegerType(0)))));
        self::assertStringContainsString('/Matrix [0 0 0 0 0 0]', $this->file($this->annotation($this->e('AP', $this->ap(new IndirectReference(new StreamObject('', $dictionary))))))->toPdf());
    }

    #[DataProvider('formats')]
    public function testAHeader17CatalogUpgradeChecksRetainedAnnotations(CrossReferenceFormat $format): void
    {
        $catalog = $this->catalog($this->annotation());
        $base = (new FileStructure(new FileHeader('1.7'), new FileBody($catalog), crossReferenceFormat: $format))->toRevision();
        $snapshot = $base->binding($catalog)->dictionary ?? throw new \LogicException();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require AP');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($snapshot->with($this->e('Version', new NameType('2.0'))), $base->binding($catalog)->identifier))))->toRevision();
    }

    #[DataProvider('formats')]
    public function testRetainedAppearancesUseTheEffectiveReplacementStream(CrossReferenceFormat $format): void
    {
        $form = $this->form();
        $base = $this->file($this->annotation($this->e('AP', $this->ap(new IndirectReference($form)))), format: $format)->toRevision();
        $invalid = new StreamObject('', $form->dictionary()->without('BBox'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BBox');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($invalid, $base->binding($form)->identifier))))->toRevision();
    }

    public function testPrivateAnnotationShapedDataIsNotAnActualPageAnnotation(): void
    {
        $catalog = $this->catalog()->with($this->e('Private', $this->annotation($this->e('AP', new IntegerType(3)))));
        self::assertStringContainsString('/AP 3', (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf());
    }

    public function testAppearanceTraversalHasAnExplicitCumulativeBudget(): void
    {
        $form = $this->form();
        $catalog = $this->catalog($this->annotation($this->e('AP', $this->ap(new DictionaryType($this->e('One', new IndirectReference($form)), $this->e('Two', new IndirectReference($form))))), $this->e('AS', new NameType('One'))));
        $context = new SerializationContext();
        $context->prepare($catalog);
        $graph = new DocumentGraphContext($context, $catalog);
        $this->expectException(\LengthException::class);
        (new AnnotationAppearanceGraphValidation(new NavigationLimits(maxNodes: 3)))->validate(new AnnotationPageIndex($graph), new EffectivePdfVersion(new FileHeader('2.0'), $catalog));
    }
}
