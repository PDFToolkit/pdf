<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Redaction\RedactionAnnotation;
use PDF\Document\Redaction\RedactionLimits;
use PDF\Document\Redaction\RedactionPageRequest;
use PDF\Document\Redaction\RedactionPlan;
use PDF\Document\Redaction\RedactionResourceCopy;
use PDF\Document\Redaction\RedactionResourceSanitizer;
use PDF\Document\Redaction\SecureRedaction;
use PDF\Document\ResourceDictionary;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
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

final class SecureRedactionFontContextTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('simpleForeignDomains')]
    public function testUnusedCidDomainsCannotCarryStringsThroughAPublicSimpleFont(string $subtype, string $key): void
    {
        $cid = $this->dictionary(['Registry' => new LiteralString('SOURCE-SECRET-REGISTRY'), 'Ordering' => new LiteralString('Identity'), 'Supplement' => new IntegerType(0)]);
        $font = $this->dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType($subtype), 'BaseFont' => new NameType('Helvetica'), $key => $key === 'DescendantFonts' ? new ArrayType($cid) : $cid]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('normalized context');
        $this->publicFontResult($font);
    }

    public static function simpleForeignDomains(): iterable
    {
        foreach (['Type1', 'MMType1', 'TrueType'] as $subtype) {
            foreach (['CIDSystemInfo', 'DescendantFonts'] as $key) {
                yield $subtype . '/' . $key => [$subtype, $key];
            }
        }
    }

    public function testPublicSimpleFontAndTextRemainWhileAncillaryAlternativesAreRemoved(): void
    {
        $font = $this->dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType('Type1'), 'BaseFont' => new NameType('Helvetica'), 'Encoding' => new NameType('WinAnsiEncoding'), 'ToUnicode' => new StreamObject('SOURCE-SECRET-ALTERNATIVE'), 'FontDescriptor' => $this->dictionary(['FontName' => new NameType('Helvetica'), 'CharSet' => new LiteralString('SOURCE-SECRET-CHARSET')])]);
        $bytes = $this->publicFontResult($font);
        self::assertStringContainsString('PUBLIC-TEXT', $bytes);
        self::assertStringContainsString('/Helvetica', $bytes);
        self::assertStringNotContainsString('SOURCE-SECRET', $bytes);
        self::assertStringNotContainsString('/ToUnicode', $bytes);
    }

    public function testExplicitNormalizedCompositeFontRetainsItsRequiredCidIdentifiers(): void
    {
        $cid = $this->dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType('CIDFontType2'), 'BaseFont' => new NameType('NormalizedFont'), 'CIDSystemInfo' => $this->dictionary(['Registry' => new LiteralString('Adobe'), 'Ordering' => new LiteralString('Identity'), 'Supplement' => new IntegerType(0)]), 'CIDToGIDMap' => new NameType('Identity')]);
        $font = $this->dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType('Type0'), 'BaseFont' => new NameType('NormalizedFont'), 'Encoding' => new NameType('Identity-H'), 'DescendantFonts' => new ArrayType(new IndirectReference($cid))]);
        $copy = $this->copier()->font($font);
        self::assertInstanceOf(DictionaryType::class, $copy);
        $descendants = $copy->get('DescendantFonts');
        self::assertInstanceOf(ArrayType::class, $descendants);
        self::assertInstanceOf(IndirectReference::class, $descendants->items[0]);
        $descendant = $descendants->items[0]->target();
        self::assertInstanceOf(DictionaryType::class, $descendant);
        $system = $descendant->get('CIDSystemInfo');
        self::assertInstanceOf(DictionaryType::class, $system);
        self::assertEquals(new LiteralString('Adobe'), $system->get('Registry'));
        self::assertEquals(new LiteralString('Identity'), $system->get('Ordering'));
        self::assertEquals(new NameType('Identity'), $descendant->get('CIDToGIDMap'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCompositeDomains')]
    public function testNormalizedCompositeAndCidSubtypesRejectForeignFontDomains(DictionaryType $font): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->copier()->font($font);
    }

    public static function invalidCompositeDomains(): iterable
    {
        $dictionary = static fn (array $values): DictionaryType => new DictionaryType(...array_map(static fn (string $key, BasicType $value): Entry => new Entry(new NameType($key), $value), array_keys($values), array_values($values)));
        $cid = $dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType('CIDFontType2'), 'BaseFont' => new NameType('NormalizedFont')]);
        $composite = static fn (ArrayType $descendants): DictionaryType => $dictionary(['Type' => new NameType('Font'), 'Subtype' => new NameType('Type0'), 'BaseFont' => new NameType('NormalizedFont'), 'Encoding' => new NameType('Identity-H'), 'DescendantFonts' => $descendants]);
        yield 'missing CID subtype' => [$composite(new ArrayType($dictionary(['Registry' => new LiteralString('SOURCE-SECRET')])))];
        yield 'simple font used as CID descendant' => [$composite(new ArrayType($dictionary(['Subtype' => new NameType('Type1'), 'BaseFont' => new NameType('Helvetica')])))];
        yield 'two descendants' => [$composite(new ArrayType($cid, $cid))];
        yield 'missing composite encoding' => [$dictionary(['Subtype' => new NameType('Type0'), 'BaseFont' => new NameType('NormalizedFont'), 'DescendantFonts' => new ArrayType($cid)])];
        yield 'extra composite CIDSystemInfo' => [$composite(new ArrayType($cid))->with(new Entry(new NameType('CIDSystemInfo'), $dictionary(['Registry' => new LiteralString('SOURCE-SECRET')])))];
        yield 'CIDFontType0 with Type2-only glyph map' => [$composite(new ArrayType($dictionary(['Subtype' => new NameType('CIDFontType0'), 'BaseFont' => new NameType('NormalizedFont'), 'CIDToGIDMap' => new NameType('Identity')])))];
        yield 'CID font with simple encoding' => [$composite(new ArrayType($cid->with(new Entry(new NameType('Encoding'), new NameType('WinAnsiEncoding')))))];
        yield 'CID as top-level resource' => [$cid];
        yield 'simple encoding with composite stream type' => [$dictionary(['Subtype' => new NameType('Type1'), 'BaseFont' => new NameType('Helvetica'), 'Encoding' => new StreamObject('SOURCE-SECRET')])];
    }

    private function copier(): RedactionResourceCopy
    {
        // This fixture explicitly supplies already normalized font syntax. It is
        // not a font-program parser, and no opaque font program is used here.
        $normalizer = new class () implements RedactionResourceSanitizer {
            public function font(DictionaryType $font): DictionaryType
            {
                return $font;
            }

            public function image(StreamObject $image): StreamObject
            {
                throw new \LogicException('This fixture supplies no image capability.');
            }
        };
        return new RedactionResourceCopy(new RedactionLimits(), $normalizer);
    }

    private function publicFontResult(DictionaryType $font): string
    {
        $box = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(200), new NumericType(120));
        $resources = new ResourceDictionary(new Entry(new NameType('Font'), $this->dictionary(['F1' => new IndirectReference($font)])));
        $public = new PageObject($box, $resources, new StreamObject(new ContentStreams(new BeginTextObject(), new SetTextFontAndSize(new NameType('F1'), new NumericType(10)), new ShowText(new LiteralString('PUBLIC-TEXT')), new EndTextObject())));
        $selected = new PageObject($box, new ResourceDictionary(), new StreamObject(new ContentStreams()));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($public)->addKid($selected));
        $annotation = new RedactionAnnotation($this->dictionary(['Subtype' => new NameType('Redact'), 'Rect' => new Rectangle(new NumericType(1), new NumericType(1), new NumericType(2), new NumericType(2))]));
        return (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($selected, $annotation)))->toPdf();
    }

    /** @param array<string, BasicType> $values */
    private function dictionary(array $values): DictionaryType
    {
        return new DictionaryType(...array_map(static fn (string $key, BasicType $value): Entry => new Entry(new NameType($key), $value), array_keys($values), array_values($values)));
    }
}
