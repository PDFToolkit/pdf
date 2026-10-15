<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Structure\WtPdfConformanceLevel;
use PDF\Document\Structure\WtPdfGraphChecks;
use PDF\Document\Structure\WtPdfRichTextChecks;
use PDF\Document\Structure\WtPdfXmlRichTextParser;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\TestCase;

final class WtPdfRichTextTest extends TestCase
{
    public function testParagraphsEntitiesAndStyledSpansAreComparedToCanonicalValue(): void
    {
        $checks = new WtPdfRichTextChecks(new WtPdfXmlRichTextParser());
        $field = $this->field('<body xmlns="http://www.w3.org/1999/xhtml"><p>A &amp; B</p><p><span style="font-weight:bold">café</span></p></body>', "A & B\r\ncafé");
        self::assertSame([], $checks->inspect($field));
        self::assertCount(1, $checks->inspect($field->with(new Entry(new NameType('V'), new TextString('different')))));
        $graph = new WtPdfGraphChecks(richText: $checks);
        self::assertSame([], array_values(array_filter($graph->inspect($field, null, WtPdfConformanceLevel::Reuse), static fn ($finding): bool => $finding->clause === '8.10.3.3')));
    }

    public function testDecodedStreamValuesAndBinaryIndirectIdentityAreSupported(): void
    {
        $checks = new WtPdfRichTextChecks(new WtPdfXmlRichTextParser());
        $field = $this->field('<body><p>Text</p></body>', 'Text')->with(new Entry(new NameType('RV'), new IndirectReference(new StreamObject('<body><p>Text</p></body>'))));
        self::assertSame([], $checks->inspect($field));
    }

    public function testExternalEntitiesAndUnsupportedCssNeverPassEquivalence(): void
    {
        $checks = new WtPdfRichTextChecks(new WtPdfXmlRichTextParser());
        foreach (['<!DOCTYPE body [<!ENTITY x SYSTEM "file:///nonexistent">]><body>&x;</body>', '<body><script>Text</script></body>', '<body><span style="display:none">Text</span></body>'] as $xml) {
            self::assertCount(1, $checks->inspect($this->field($xml, 'Text')));
        }
    }

    public function testXmlDepthAndBytesAreBounded(): void
    {
        $parser = new WtPdfXmlRichTextParser(maximumDepth: 1);
        $this->expectException(\OverflowException::class);
        $parser->plainText('<body><p><span>Text</span></p></body>');
    }

    public function testFieldTypeAndCanonicalValueMayBeInherited(): void
    {
        $parent = new DictionaryType(new Entry(new NameType('FT'), new NameType('Tx')), new Entry(new NameType('V'), new TextString('Inherited')));
        $child = new DictionaryType(new Entry(new NameType('Parent'), new IndirectReference($parent)), new Entry(new NameType('RV'), new TextString('<body><p>Inherited</p></body>')));
        $checks = new WtPdfRichTextChecks(new WtPdfXmlRichTextParser());
        self::assertSame([], $checks->inspect($child));
        self::assertSame([], array_values(array_filter((new WtPdfGraphChecks(richText: $checks))->inspect($child, null, WtPdfConformanceLevel::Reuse), static fn ($finding): bool => $finding->clause === '8.10.3.3')));
    }

    public function testCommentsDoNotEraseParagraphBreaksAndMixedContentDoesNotPass(): void
    {
        $parser = new WtPdfXmlRichTextParser();
        self::assertSame("A\nB", $parser->plainText('<body><p>A</p><!-- formatting --><p>B</p></body>'));
        $checks = new WtPdfRichTextChecks($parser);
        $findings = $checks->inspect($this->field('<body>A<p>B</p>C</body>', 'ABC'));
        self::assertCount(1, $findings);
        self::assertSame(\PDF\Document\Structure\WtPdfFindingStatus::AdditionalValidationRequired, $findings[0]->status);
    }

    private function field(string $xml, string $plain): DictionaryType
    {
        return new DictionaryType(new Entry(new NameType('FT'), new NameType('Tx')), new Entry(new NameType('RV'), new TextString($xml)), new Entry(new NameType('V'), new IndirectReference(new TextString($plain))));
    }
}
