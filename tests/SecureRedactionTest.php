<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Redaction\RedactionAnnotation;
use PDF\Document\Redaction\RedactionBounds;
use PDF\Document\Redaction\RedactionFontGeometry;
use PDF\Document\Redaction\RedactionGlyphGeometry;
use PDF\Document\Redaction\RedactionLimits;
use PDF\Document\Redaction\RedactionPageRequest;
use PDF\Document\Redaction\RedactionPlan;
use PDF\Document\Redaction\SecureRedaction;
use PDF\Document\ResourceDictionary;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

final class SecureRedactionTest extends TestCase
{
    public function testRemovesSourceTextAndItsSemanticMetadataInsteadOfCoveringIt(): void
    {
        $content = new ContentStreams(...[
            ...$this->text('SECRET-TEXT', 20, 70),
            new BeginMarkedContentWithProperties(new NameType('Span'), $this->dictionary(['ActualText' => new TextString('SECRET-ACTUALTEXT')])),
            ...$this->text('PUBLIC-TEXT', 20, 20), new EndMarkedContent(),
            new AppendRectangle(new NumericType(22), new NumericType(68), new NumericType(3), new NumericType(5)), new FillPathNonzero(),
            new AppendRectangle(new NumericType(5), new NumericType(5), new NumericType(3), new NumericType(5)), new FillPathNonzero(),
        ]);
        $page = new PageObject($this->box(0, 0, 200, 120), $this->fontResources(), new StreamObject($content), new Entry(new NameType('Annots'), new ArrayType(new IndirectReference($this->dictionary(['Subtype' => new NameType('Text'), 'Contents' => new TextString('SECRET-ANNOTATION')])))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('Metadata'), new IndirectReference(new StreamObject('SECRET-XMP'))));
        // Fixture glyph outlines are explicitly known, independently of descriptor declarations.
        $geometry = new class () implements RedactionFontGeometry {
            public function glyph(DictionaryType $font, int $code): ?RedactionGlyphGeometry
            {
                return new RedactionGlyphGeometry(new RedactionBounds(0, -200, 1000, 800), 600);
            }
        };
        $result = (new SecureRedaction(fontGeometry: $geometry))->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
        $bytes = $result->toPdf();
        self::assertStringContainsString('PUBLIC-TEXT', $bytes);
        foreach (['SECRET', 'ActualText', '/Annots', '/Metadata', '/StructTreeRoot', '/AcroForm', '/Prev'] as $removed) {
            self::assertStringNotContainsString($removed, $bytes);
        }
        self::assertSame(2, $result->removedPaintObjects);
        self::assertSame(1, substr_count($bytes, 'startxref'));
        self::assertSame(1, substr_count($bytes, '%%EOF'));
        self::assertStringContainsString('22 68 3 5 re', (string) $content); // source graph remains immutable
        self::assertStringNotContainsString('22 68 3 5 re', $bytes);
    }

    public function testSharedAffectedImageIsRemovedEvenFromAnEarlierUntouchedPage(): void
    {
        $image = $this->image('SECRETIMG');
        $public = $this->image('PUBLICIMG');
        $resources = new ResourceDictionary(new Entry(new NameType('XObject'), $this->dictionary(['Secret' => new IndirectReference($image), 'Public' => new IndirectReference($public)])));
        $first = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(...$this->imagePaint('Secret', 20, 20))));
        $second = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(...[...$this->imagePaint('Secret', 20, 70), ...$this->imagePaint('Public', 100, 20)])));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($first)->addKid($second));
        $result = (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($second, $this->annotation())));
        self::assertStringNotContainsString('SECRETIMG', $result->toPdf());
        self::assertStringContainsString('PUBLICIMG', $result->toPdf());
        self::assertSame(1, $result->removedSharedResources);
        self::assertSame(2, $result->removedPaintObjects);
    }

    public function testTouchedFormAndItsReachableImageClosureCannotSurviveElsewhere(): void
    {
        $image = $this->image('SECRETIMG');
        $formResources = new ResourceDictionary(new Entry(new NameType('XObject'), $this->dictionary(['Secret' => new IndirectReference($image)])));
        $form = new StreamObject(new ContentStreams(new InvokeXObject(new NameType('Secret'))), $this->dictionary(['Type' => new NameType('XObject'), 'Subtype' => new NameType('Form'), 'BBox' => $this->box(0, 0, 10, 10), 'Resources' => $formResources]));
        $resources = new ResourceDictionary(new Entry(new NameType('XObject'), $this->dictionary(['Form' => new IndirectReference($form), 'Secret' => new IndirectReference($image)])));
        $first = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(...$this->imagePaint('Secret', 20, 20))));
        $second = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(new ConcatenateMatrix(new Matrix(e: new NumericType(20), f: new NumericType(68))), new InvokeXObject(new NameType('Form')))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($first)->addKid($second));
        $result = (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($second, $this->annotation())));
        self::assertStringNotContainsString('SECRETIMG', $result->toPdf());
        self::assertStringNotContainsString('/Subtype /Form', $result->toPdf());
        self::assertSame(2, $result->removedSharedResources);
    }

    public function testUnknownTextBoundsRemoveTheEntireTextObject(): void
    {
        $font = $this->dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType('Type1'), 'BaseFont' => new NameType('Helvetica')]);
        $resources = new ResourceDictionary(new Entry(new NameType('Font'), $this->dictionary(['F1' => new IndirectReference($font)])));
        $page = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(...$this->text('UNPROVABLE-TEXT', 150, 20))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $result = (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
        self::assertStringNotContainsString('UNPROVABLE', $result->toPdf());
        self::assertSame(1, $result->removedPaintObjects);
    }

    public function testDeclaredFontBoundsAloneNeverProveTheActualGlyphProgramGeometry(): void
    {
        $page = new PageObject($this->box(0, 0, 200, 120), $this->fontResources(), new StreamObject(new ContentStreams(...$this->text('DECLARED-BOUNDS-ARE-NOT-PROOF', 150, 20))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $result = (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
        self::assertStringNotContainsString('DECLARED-BOUNDS', $result->toPdf());
        self::assertSame(1, $result->removedPaintObjects);
    }

    public function testRoOverridesIgnoredAnnotationFieldsAndIsTranslatedToRectOrigin(): void
    {
        $overlay = new StreamObject(new ContentStreams(new AppendRectangle(new NumericType(0), new NumericType(0), new NumericType(2), new NumericType(2)), new FillPathNonzero()), $this->dictionary(['Type' => new NameType('XObject'), 'Subtype' => new NameType('Form'), 'BBox' => $this->box(0, 0, 2, 2), 'Resources' => new ResourceDictionary()]));
        $annotation = new RedactionAnnotation($this->dictionary(['Subtype' => new NameType('Redact'), 'Rect' => $this->box(19, 68, 45, 82), 'RO' => new IndirectReference($overlay), 'IC' => new NameType('ignored-invalid-color'), 'Q' => new IntegerType(999), 'OverlayText' => new IntegerType(9)]));
        $page = new PageObject($this->box(0, 0, 200, 120), new ResourceDictionary(), new StreamObject(new ContentStreams()));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $result = (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $annotation)));
        self::assertStringContainsString('1 0 0 1 19 68 cm', $result->toPdf());
        self::assertStringContainsString('/Subtype /Form', $result->toPdf());
        self::assertStringNotContainsString('ignored-invalid', $result->toPdf());
    }

    public function testOverlayTextUsesCanonicalFontWidthsQuaddingAndRepeat(): void
    {
        $annotation = new RedactionAnnotation($this->dictionary(['Subtype' => new NameType('Redact'), 'Rect' => $this->box(19, 68, 100, 100), 'OverlayText' => new TextString('X'), 'DA' => new \PDF\Document\Forms\DefaultAppearance(new SetTextFontAndSize(new NameType('F1'), new NumericType(10))), 'Repeat' => new \PDF\Syntax\Object\BooleanType(true), 'Q' => new IntegerType(1)]));
        $page = new PageObject($this->box(0, 0, 200, 120), $this->fontResources(), new StreamObject(new ContentStreams(...$this->text('SECRET', 20, 70))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $result = (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $annotation)));
        self::assertStringNotContainsString('SECRET', $result->toPdf());
        self::assertGreaterThan(1, substr_count($result->toPdf(), '<58> Tj'));
        self::assertStringContainsString('1 0 0 1 -2.5', $result->toPdf());
    }

    public function testQuadPointsTakePrecedenceOverRectAndReversedRectIsNormalized(): void
    {
        $annotation = new RedactionAnnotation($this->dictionary(['Subtype' => new NameType('Redact'), 'Rect' => $this->box(0, 0, 200, 120), 'QuadPoints' => new ArrayType(...array_map(static fn (int $v): NumericType => new NumericType($v), [10, 10, 20, 10, 20, 15, 10, 15]))]));
        self::assertSame(10.0, $annotation->regions[0]->left);
        self::assertSame(15.0, $annotation->regions[0]->top);
        self::assertNull($annotation->interior);
        $reversed = new RedactionAnnotation($this->dictionary(['Subtype' => new NameType('Redact'), 'Rect' => $this->box(20, 15, 10, 10)]));
        self::assertSame(10.0, $reversed->regions[0]->left);
    }

    public function testOpaquePageContentIsRejectedBeforeAnyOutputExists(): void
    {
        $page = new PageObject($this->box(0, 0, 200, 120), new ResourceDictionary(), new StreamObject('(SECRET) Tj'));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('never an opaque byte stream');
        (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
    }

    public function testInstructionBudgetIsCumulativeAcrossTheTwoSecurityPasses(): void
    {
        $page = new PageObject($this->box(0, 0, 200, 120), new ResourceDictionary(), new StreamObject(new ContentStreams(new SaveGraphicsState(), new RestoreGraphicsState())));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $this->expectException(\OverflowException::class);
        (new SecureRedaction(new RedactionLimits(operations: 3)))->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
    }

    public function testRetainedEncodedImageMetadataIsRejectedWithoutExplicitSanitizer(): void
    {
        $image = new StreamObject('SECRET-JPEG-APP-METADATA', $this->image('PUBLICIMG')->dictionary(), new \PDF\Syntax\Filters\EncodedData(\PDF\Syntax\Filters\FilterName::DCTDecode));
        $resources = new ResourceDictionary(new Entry(new NameType('XObject'), $this->dictionary(['Image' => new IndirectReference($image)])));
        $page = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(...$this->imagePaint('Image', 150, 20))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $this->expectException(\InvalidArgumentException::class);
        (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
    }

    public function testRetainedTrailingPixelDataIsNotMistakenForNormalizedImageSamples(): void
    {
        $image = $this->image('PUBLICIMG-SECRET-TRAILER');
        $resources = new ResourceDictionary(new Entry(new NameType('XObject'), $this->dictionary(['Image' => new IndirectReference($image)])));
        $page = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(...$this->imagePaint('Image', 150, 20))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without trailing metadata');
        (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
    }

    public function testTaintedOpaqueImageIsRemovedWithoutDecodingOrKeepingItsMetadata(): void
    {
        $image = new StreamObject('SECRET-JPEG-APP-METADATA', $this->image('PUBLICIMG')->dictionary(), new \PDF\Syntax\Filters\EncodedData(\PDF\Syntax\Filters\FilterName::DCTDecode));
        $resources = new ResourceDictionary(new Entry(new NameType('XObject'), $this->dictionary(['Image' => new IndirectReference($image)])));
        $page = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(...$this->imagePaint('Image', 20, 70))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $result = (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
        self::assertStringNotContainsString('SECRET', $result->toPdf());
        self::assertSame(1, $result->removedSharedResources);
    }

    public function testEmbeddedFontOnUntouchedPageRequiresAnExplicitProgramSanitizer(): void
    {
        $descriptor = $this->dictionary(['FontFile2' => new IndirectReference(new StreamObject('SECRET-FONT-METADATA'))]);
        $font = $this->dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType('TrueType'), 'BaseFont' => new NameType('CustomFont'), 'FontDescriptor' => new IndirectReference($descriptor)]);
        $resources = new ResourceDictionary(new Entry(new NameType('Font'), $this->dictionary(['F1' => new IndirectReference($font)])));
        $first = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(...$this->text('PUBLIC', 20, 20))));
        $second = new PageObject($this->box(0, 0, 200, 120), new ResourceDictionary(), new StreamObject(new ContentStreams()));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($first)->addKid($second));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit font-program sanitizer');
        (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($second, $this->annotation())));
    }

    public function testUnneededFontProgramsDisappearWhenTheirTextObjectsAreRemoved(): void
    {
        $descriptor = $this->dictionary(['FontFile2' => new IndirectReference(new StreamObject('SECRET-FONT-METADATA'))]);
        $font = $this->dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType('TrueType'), 'BaseFont' => new NameType('CustomFont'), 'FontDescriptor' => new IndirectReference($descriptor)]);
        $resources = new ResourceDictionary(new Entry(new NameType('Font'), $this->dictionary(['F1' => new IndirectReference($font)])));
        $page = new PageObject($this->box(0, 0, 200, 120), $resources, new StreamObject(new ContentStreams(...$this->text('SECRET-TEXT', 20, 70))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $result = (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
        self::assertStringNotContainsString('SECRET', $result->toPdf());
        self::assertStringNotContainsString('/FontFile2', $result->toPdf());
        self::assertStringNotContainsString('/FontDescriptor', $result->toPdf());
    }

    public function testUnknownGraphicsStateCannotSilentlyPreserveHiddenPaintChannels(): void
    {
        $page = new PageObject($this->box(0, 0, 200, 120), new ResourceDictionary(), new StreamObject(new ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\SetGraphicsStateParameters(new NameType('GS1')))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported redaction content operator gs');
        (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
    }

    public function testRetainingAClippingPathThroughTheRegionWouldPreserveRedactedGeometry(): void
    {
        $page = new PageObject($this->box(0, 0, 200, 120), new ResourceDictionary(), new StreamObject(new ContentStreams(new AppendRectangle(new NumericType(0), new NumericType(0), new NumericType(200), new NumericType(120)), new \PDF\Syntax\ContentStreamsResources\Operators\ClipPathNonzero(), new \PDF\Syntax\ContentStreamsResources\Operators\EndPath())));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retaining its original geometry');
        (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($page, $this->annotation())));
    }

    /** @return list<Operator> */
    private function text(string $text, int $x, int $y): array
    {
        return [new BeginTextObject(), new SetTextFontAndSize(new NameType('F1'), new NumericType(10)), new SetTextMatrix(new Matrix(e: new NumericType($x), f: new NumericType($y))), new ShowText(new LiteralString($text)), new EndTextObject()];
    }

    /** @return list<Operator> */
    private function imagePaint(string $name, int $x, int $y): array
    {
        return [new SaveGraphicsState(), new ConcatenateMatrix(new Matrix(new NumericType(10), d: new NumericType(10), e: new NumericType($x), f: new NumericType($y))), new InvokeXObject(new NameType($name)), new RestoreGraphicsState()];
    }

    private function annotation(): RedactionAnnotation
    {
        return new RedactionAnnotation($this->dictionary(['Subtype' => new NameType('Redact'), 'Rect' => $this->box(19, 68, 45, 82), 'IC' => new ArrayType(new NumericType(0), new NumericType(0), new NumericType(0))]));
    }

    private function fontResources(): ResourceDictionary
    {
        $descriptor = $this->dictionary(['Type' => new NameType('FontDescriptor'), 'FontName' => new NameType('Helvetica'), 'Flags' => new IntegerType(32), 'FontBBox' => $this->box(0, -200, 1000, 800), 'ItalicAngle' => new NumericType(0), 'Ascent' => new NumericType(800), 'Descent' => new NumericType(-200), 'CapHeight' => new NumericType(700), 'StemV' => new NumericType(80)]);
        $font = $this->dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType('Type1'), 'BaseFont' => new NameType('Helvetica'), 'FirstChar' => new IntegerType(0), 'LastChar' => new IntegerType(255), 'Widths' => new ArrayType(...array_fill(0, 256, new NumericType(600))), 'FontDescriptor' => new IndirectReference($descriptor)]);
        return new ResourceDictionary(new Entry(new NameType('Font'), $this->dictionary(['F1' => new IndirectReference($font)])));
    }

    private function image(string $bytes): StreamObject
    {
        return new StreamObject($bytes, $this->dictionary(['Type' => new NameType('XObject'), 'Subtype' => new NameType('Image'), 'Width' => new IntegerType(3), 'Height' => new IntegerType(1), 'BitsPerComponent' => new IntegerType(8), 'ColorSpace' => new NameType('DeviceRGB')]));
    }

    private function box(int $x1, int $y1, int $x2, int $y2): Rectangle
    {
        return new Rectangle(new NumericType($x1), new NumericType($y1), new NumericType($x2), new NumericType($y2));
    }

    /** @param array<string, \PDF\Syntax\FileStructure\BasicType> $values */
    private function dictionary(array $values): DictionaryType
    {
        $entries = [];
        foreach ($values as $name => $value) {
            $entries[] = new Entry(new NameType($name), $value);
        }
        return new DictionaryType(...$entries);
    }
}
