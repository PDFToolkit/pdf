<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Fonts\Type3Glyph;
use PDF\Document\Files as F;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\ContentStreamsResources\Type3GlyphContentStream;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

/** EC3 #128: ISO_32000-2_sponsored_EC3.pdf, physical pp.127–128/333–334. */
final class Type3ResourceScopesTest extends TestCase
{
    public function testGlyphResourcesAreExplicitAndOverrideTheFont(): void
    {
        $resources = $this->resources(true);
        $glyph = new Type3Glyph($this->content(), $resources);
        self::assertSame($resources, $glyph->dictionary()->get('Resources'));
        $pdf = $this->file($this->font($glyph, $this->resources(false)), $this->resources(false))->toPdf();
        self::assertStringContainsString('/AF /Files BDC', $pdf);
    }

    public function testAFontWithoutResourcesStillUsesItsGlyphLocalResources(): void
    {
        $glyph = new Type3Glyph($this->content(), $this->resources(true));
        self::assertStringContainsString('/MCAF', $this->file($this->font($glyph), $this->resources(false))->toPdf());
    }

    public function testInvalidGlyphResourcesCannotBorrowAValidFontEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCAF requires a nonempty array');
        $this->file($this->font(new Type3Glyph($this->content(), $this->resources(false)), $this->resources(true)))->toPdf();
    }

    public function testAnExplicitEmptyGlyphDictionaryShadowsTheFont(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing');
        $this->file($this->font(new Type3Glyph($this->content(), new DictionaryType()), $this->resources(true)))->toPdf();
    }

    public function testAbsentGlyphResourcesUseTheFontBeforeThePage(): void
    {
        self::assertStringContainsString('/MCAF', $this->file($this->font(new Type3Glyph($this->content()), $this->resources(true)), $this->resources(false))->toPdf());
    }

    public function testAbsentGlyphAndFontResourcesUseAnAncestorOfThePage(): void
    {
        self::assertStringContainsString('/MCAF', $this->file($this->font(new Type3Glyph($this->content())), $this->resources(true), true)->toPdf());
    }

    public function testEveryPageChecksItsOwnInheritedResources(): void
    {
        $font = $this->font(new Type3Glyph($this->content()));
        $pages = new PageTreeNode();
        foreach ([true, false] as $valid) {
            $pages->addKid(new PageObject($this->box(), $this->pageResources($font, $this->resources($valid)), new StreamObject('')));
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCAF requires a nonempty array');
        (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($pages))))->toPdf();
    }

    public function testAnEmptyFontDictionaryDoesNotMergePageProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing');
        $this->file($this->font(new Type3Glyph($this->content()), new DictionaryType()), $this->resources(true))->toPdf();
    }

    public function testAType3FontReachedThroughAFormStillUsesThePageFallback(): void
    {
        $font = $this->font(new Type3Glyph($this->content()));
        $formResources = $this->pageResources($font, $this->resources(false));
        $form = new StreamObject('', new DictionaryType($this->entry('Type', new NameType('XObject')), $this->entry('Subtype', new NameType('Form')), $this->entry('BBox', $this->box()), $this->entry('Resources', $formResources)));
        $resources = new ResourceDictionary(...[...iterator_to_array($this->resources(true)->entries(), false), $this->entry('XObject', new DictionaryType($this->entry('Nested', new IndirectReference($form))))]);
        $pages = new PageTreeNode($this->entry('Resources', new IndirectReference($resources)));
        $pages->addKid(new PageObject($this->box(), null, new StreamObject(new ContentStreams(new Op\InvokeXObject(new NameType('Nested'))))));
        self::assertStringContainsString('/MCAF', (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($pages))))->toPdf());
    }

    public function testType3InAnAnnotationAppearanceUsesItsPagesResources(): void
    {
        foreach ([true, false] as $valid) {
            $font = $this->font(new Type3Glyph($this->content()));
            $form = new StreamObject('', new DictionaryType($this->entry('Type', new NameType('XObject')), $this->entry('Subtype', new NameType('Form')), $this->entry('BBox', $this->box()), $this->entry('Resources', $this->pageResources($font, $this->resources(!$valid)))));
            $annotation = new DictionaryType($this->entry('Type', new NameType('Annot')), $this->entry('Subtype', new NameType('Stamp')), $this->entry('Rect', $this->box()), $this->entry('AP', new DictionaryType($this->entry('N', new IndirectReference($form)))));
            $pages = new PageTreeNode();
            $pages->addKid(new PageObject($this->box(), $this->resources($valid), new StreamObject(''), $this->entry('Annots', new ArrayType(new IndirectReference($annotation)))));
            try {
                $pdf = (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($pages))))->toPdf();
                self::assertTrue($valid);
                self::assertStringContainsString('/MCAF', $pdf);
            } catch (\InvalidArgumentException $error) {
                self::assertFalse($valid);
                self::assertStringContainsString('MCAF requires a nonempty array', $error->getMessage());
            }
        }
    }

    public function testGenericGlyphIndirectResourcesOverrideTheFont(): void
    {
        $glyph = new StreamObject($this->content(), new DictionaryType($this->entry('Resources', new IndirectReference($this->resources(true)))));
        self::assertStringContainsString('/MCAF', $this->file($this->font($glyph, $this->resources(false)))->toPdf());
    }

    public function testAnIncrementalResourceReplacementRechecksFrozenGlyphFacts(): void
    {
        $resources = $this->resources(true);
        $glyph = new StreamObject($this->content(), new DictionaryType($this->entry('Resources', new IndirectReference($resources))));
        $base = $this->file($this->font($glyph, $this->resources(true)))->toRevision();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCAF requires a nonempty array');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($this->resources(false), $base->binding($resources)->identifier))))->toRevision();
    }

    public function testFrozenGlyphResourcesDoNotReadAnUnstagedMutableBuilder(): void
    {
        $resources = new class ($this->resources(true), $this->resources(false)) extends DictionaryType {
            public bool $valid = true;
            public function __construct(private DictionaryType $good, private DictionaryType $bad)
            {
                parent::__construct();
            }
            public function entries(): iterable
            {
                yield from ($this->valid ? $this->good : $this->bad)->entries();
            }
        };
        $glyph = new StreamObject($this->content(), new DictionaryType($this->entry('Resources', new IndirectReference($resources))));
        $base = $this->file($this->font($glyph))->toRevision();
        $resources->valid = false;
        $next = (new IncrementalUpdate($base, new FileBody(new IntegerType(7))))->toRevision();
        self::assertStringStartsWith($base->bytes, $next->bytes);
    }

    public function testMalformedGlyphResourcesDoNotSilentlyFallBack(): void
    {
        $glyph = new StreamObject($this->content(), new DictionaryType($this->entry('Resources', new IntegerType(7))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Resources entry must resolve to a dictionary');
        $this->file($this->font($glyph, $this->resources(true)))->toPdf();
    }

    public function testRedactionKeepsItsExplicitType3SanitizerBoundary(): void
    {
        $font = $this->font(new Type3Glyph($this->content(), $this->resources(true)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit font-program sanitizer');
        (new \PDF\Document\Redaction\RedactionResourceCopy(new \PDF\Document\Redaction\RedactionLimits()))->font($font);
    }

    public function testResourceInspectionBudgetsAreCumulativeAndDepthBounded(): void
    {
        $budget = new \PDF\Document\Fonts\Type3ResourceBudget(maximumNodes: 2, maximumDepth: 1);
        $budget->visit(0);
        $budget->visit(1);
        try {
            $budget->visit(0);
            self::fail('The node budget must be cumulative.');
        } catch (\LengthException $error) {
            self::assertStringContainsString('budget', $error->getMessage());
        }
        $this->expectException(\LengthException::class);
        (new \PDF\Document\Fonts\Type3ResourceBudget(maximumDepth: 1))->visit(2);
    }

    private function content(): Type3GlyphContentStream
    {
        return new Type3GlyphContentStream(new Op\SetGlyphWidth(new IntegerType(600), new IntegerType(0)), new F\BeginAssociatedFilesMarkedContent(new NameType('Files')), new Op\EndMarkedContent());
    }

    private function resources(bool $valid): ResourceDictionary
    {
        $file = new F\FileSpecification(new LiteralString('application.csv'), relationship: new F\AssociatedFileRelationship(F\AFRelationship::Data));
        $files = $valid ? new ArrayType(new IndirectReference($file)) : new ArrayType();
        return new ResourceDictionary($this->entry('Properties', new DictionaryType($this->entry('Files', new DictionaryType($this->entry('MCAF', $files))))));
    }

    private function font(StreamObject $glyph, ?DictionaryType $resources = null): DictionaryType
    {
        return new DictionaryType(
            $this->entry('Type', new NameType('Font')),
            $this->entry('Subtype', new NameType('Type3')),
            $this->entry('FontBBox', $this->box()),
            $this->entry('FontMatrix', new ArrayType(new IntegerType(1), new IntegerType(0), new IntegerType(0), new IntegerType(1), new IntegerType(0), new IntegerType(0))),
            $this->entry('CharProcs', new DictionaryType($this->entry('A', new IndirectReference($glyph)))),
            $this->entry('Encoding', new DictionaryType($this->entry('Differences', new ArrayType(new IntegerType(65), new NameType('A'))))),
            $this->entry('FirstChar', new IntegerType(65)),
            $this->entry('LastChar', new IntegerType(65)),
            $this->entry('Widths', new IndirectReference(new ArrayType(new IntegerType(600)))),
            ...($resources === null ? [] : [$this->entry('Resources', $resources)]),
        );
    }

    private function pageResources(DictionaryType $font, ?DictionaryType $resources): ResourceDictionary
    {
        return new ResourceDictionary(...[...iterator_to_array(($resources ?? new DictionaryType())->entries(), false), $this->entry('Font', new DictionaryType($this->entry('Outer', new IndirectReference($font))))]);
    }

    private function file(DictionaryType $font, ?DictionaryType $resources = null, bool $inherited = false): FileStructure
    {
        $resources = $this->pageResources($font, $resources);
        $pages = new PageTreeNode(...($inherited ? [$this->entry('Resources', new IndirectReference($resources))] : []));
        $pages->addKid(new PageObject($this->box(), $inherited ? null : $resources, new StreamObject('')));
        return new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($pages)));
    }

    private function box(): Rectangle
    {
        return new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(600), new IntegerType(700));
    }

    private function entry(string $name, \PDF\Syntax\FileStructure\BasicType $value): Entry
    {
        return new Entry(new NameType($name), $value);
    }
}
