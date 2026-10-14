<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\DocumentGraphContext;
use PDF\Document\Metadata as M;
use PDF\Document\Metadata\Declarations as D;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Graphics\GraphicsState\BlackPointCompensation;
use PDF\Graphics\GraphicsState\GraphicsStateParameters;
use PDF\Graphics\GraphicsState\UseBlackPointCompensation;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
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
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetadataBundleTest extends TestCase
{
    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
    private function metadata(): M\MetadataStream
    {
        return new M\MetadataStream(new M\XmpPacket(new D\Declarations(new D\Declaration(new M\MetadataUri('https://example.org/profiles/reviewed')))));
    }
    private function file(Entry ...$entries): FileStructure
    {
        return new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog(new PageTreeNode(), ...$entries)));
    }

    public function testDeclarationsAndEveryClaimFieldProduceNamespaceAwareXmp(): void
    {
        $claims = new D\Declarations(
            new D\Declaration(new M\MetadataUri('https://example.org/profile?x=1&y=2'), new D\ClaimData(new M\XmlText('A <company> & "person"'), new D\ClaimDate('2026-10-14T12:34:56.123+02:00'), new M\XmlText('Credentials é'), new D\ClaimReport('#ef=report%20name.xml'))),
            new D\Declaration(new M\MetadataUri('urn:example:another'), new D\ClaimData()),
        );
        $packet = new M\XmpPacket($claims, new D\DeclarationsPdfaExtensionSchema());
        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML($packet->xml, LIBXML_NONET));
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('pdfd', D\Declarations::NAMESPACE);
        $xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        self::assertSame(2.0, $xpath->evaluate('count(//pdfd:declarations/rdf:Bag/rdf:li)'));
        self::assertSame('https://example.org/profile?x=1&y=2', $xpath->evaluate('string(//pdfd:conformsTo[1])'));
        self::assertSame('A <company> & "person"', $xpath->evaluate('string(//pdfd:claimBy)'));
        self::assertSame('2026-10-14T12:34:56.123+02:00', $xpath->evaluate('string(//pdfd:claimDate)'));
        self::assertSame('#ef=report%20name.xml', $xpath->evaluate('string(//pdfd:claimReport)'));
        self::assertStringContainsString('Bag Declaration', $packet->xml);
        self::assertStringNotContainsString('pdfaid:part', $packet->xml);
        self::assertStringNotContainsString('pdfuaid:part', $packet->xml);
    }

    public function testLocalizedTitlesHaveExplicitDefaultAndSeparateFrench(): void
    {
        $title = new M\XmpLanguageAlternative(new M\XmpName('dc', new M\MetadataUri('http://purl.org/dc/elements/1.1/'), 'title'), new M\XmlText('A title'), new M\XmpLocalizedText('fr', new M\XmlText('Un titre')));
        $packet = new M\XmpPacket($title);
        self::assertSame('x-default', $title->values[0]->language);
        self::assertSame('{http://purl.org/dc/elements/1.1/}title', $title->expandedName());
        self::assertStringContainsString('<rdf:Alt><rdf:li xml:lang="x-default">A title</rdf:li><rdf:li xml:lang="fr">Un titre</rdf:li>', $packet->xml);
        $this->expectException(\InvalidArgumentException::class);
        new M\XmpLanguageAlternative($title->name, new M\XmlText('x'), new M\XmpLocalizedText('FR', new M\XmlText('y')), new M\XmpLocalizedText('fr', new M\XmlText('z')));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidText(): iterable
    {
        foreach (["\xC0\xAF", "\x00", "\x08", "\xEF\xBF\xBE", "\xED\xA0\x80"] as $index => $value) {
            yield (string) $index => [$value];
        }
    }
    #[DataProvider('invalidText')]
    public function testInvalidXmlCharactersCannotBeSerialized(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new M\XmlText($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDates(): iterable
    {
        foreach (['0000', '2026-00', '2026-02-29', '2026-10-14T24:00Z', '2026-10-14T12:99Z', '2026-10-14T12:00:61Z', '2026-10-14T12:00', '2026-10-14T12:00+25:00', '2026-10-14<script/>'] as $value) {
            yield $value => [$value];
        }
    }
    #[DataProvider('invalidDates')]
    public function testClaimDatesDoNotNormalizeImpossibleAuthorSuppliedValues(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new D\ClaimDate($value);
    }

    public function testDuplicateExpandedPropertiesAndConflictingPrefixesAreRejected(): void
    {
        $name = new M\XmpName('sample', new M\MetadataUri('urn:example:a'), 'value');
        $property = new M\XmpTextProperty($name, new M\XmlText('x'));
        try {
            new M\XmpPacket($property, $property);
            self::fail('Duplicate property accepted.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new M\XmpPacket($property, new M\XmpTextProperty(new M\XmpName('sample', new M\MetadataUri('urn:example:b'), 'other'), new M\XmlText('y')));
    }

    public function testObjectLevelMetadataDoesNotApplyToEmbeddedResources(): void
    {
        $document = $this->metadata();
        $object = new M\MetadataStream(new M\XmpPacket(new D\Declarations(new D\Declaration(new M\MetadataUri('urn:example:object-only')))));
        $resource = new DictionaryType(new M\MetadataEntry($object));
        $pdf = $this->file(new M\MetadataEntry($document), $this->entry('Resource', new IndirectReference($resource)))->toPdf();
        self::assertStringContainsString('urn:example:object-only', $pdf);
        self::assertStringContainsString('https://example.org/profiles/reviewed', $pdf);
        self::assertSame(2, substr_count($pdf, '/Type /Metadata'));
    }

    /** @return iterable<string, array{BasicType}> */
    public static function invalidMetadataTargets(): iterable
    {
        yield 'direct-stream' => [new StreamObject('<xml/>')];
        yield 'dictionary' => [new IndirectReference(new DictionaryType())];
        yield 'wrong-stream-type' => [new IndirectReference(new StreamObject('<xml/>', new DictionaryType(new Entry(new NameType('Type'), new NameType('XObject')), new Entry(new NameType('Subtype'), new NameType('XML')))))];
    }
    #[DataProvider('invalidMetadataTargets')]
    public function testRawMetadataMustResolveToAnIndirectXmlMetadataStream(BasicType $value): void
    {
        $owner = new DictionaryType($this->entry('Metadata', $value));
        $this->expectException($value instanceof StreamObject ? \LogicException::class : \InvalidArgumentException::class);
        $context = new SerializationContext();
        $context->prepareFile(new FileBody($owner), new IndirectReference($owner), null);
        $graph = new DocumentGraphContext($context, $owner);
        (new M\MetadataGraphValidation())->validate($graph->catalog, $graph, new EffectivePdfVersion(new FileHeader('2.0'), $graph->catalog));
    }

    public function testNativeMetadataRequiresPdf14(): void
    {
        $this->expectException(\LogicException::class);
        (new FileStructure(new FileHeader('1.3'), new FileBody(new DocumentCatalog(new PageTreeNode(), new M\MetadataEntry($this->metadata())))))->toPdf();
    }

    public function testRetainedMetadataUsesTheEffectiveStreamAfterIncrementalReplacement(): void
    {
        $stream = $this->metadata();
        $catalog = new DocumentCatalog(new PageTreeNode(), new M\MetadataEntry($stream));
        $revision = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $replacement = new StreamObject('wrong subtype', new DictionaryType($this->entry('Type', new NameType('Metadata')), $this->entry('Subtype', new NameType('NotXML'))));
        $update = new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $revision->binding($stream)->identifier)));
        $this->expectException(\InvalidArgumentException::class);
        $update->toRevision();
    }

    public function testInlineMetadataIsRejectedBeforeContentBytesAreWritten(): void
    {
        $content = new ContentStreams(new Op\BeginMarkedContentWithProperties(new NameType('Span'), new M\MetadataPropertyList($this->metadata())), new Op\EndMarkedContent());
        $this->expectException(\InvalidArgumentException::class);
        new M\MetadataMarkedContentFacts($content);
    }

    public function testNamedMetadataUsesTheActualPropertiesResourceScope(): void
    {
        $metadata = $this->metadata();
        $resources = new ResourceDictionary(new \PDF\Document\Resources\Properties(new DictionaryType($this->entry('ObjectInfo', new M\MetadataPropertyList($metadata)))));
        $content = new ContentStreams(new Op\BeginMarkedContentWithProperties(new NameType('Span'), new NameType('ObjectInfo')), new Op\EndMarkedContent());
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), $resources, new StreamObject($content));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        self::assertStringContainsString('/Span /ObjectInfo BDC', (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf());
        $context = new SerializationContext();
        $context->prepareFile(new FileBody($catalog), new IndirectReference($catalog), null);
        $graph = new DocumentGraphContext($context, $catalog);
        $this->expectException(\InvalidArgumentException::class);
        (new M\MetadataMarkedContentGraphValidation())->validate($content, new DictionaryType(), $graph, new EffectivePdfVersion(new FileHeader('2.0'), $graph->catalog));
    }

    public function testBlackPointCompensationHasThreeExactStatesAndRequiresPdf20(): void
    {
        foreach (BlackPointCompensation::cases() as $state) {
            $parameters = new GraphicsStateParameters(new UseBlackPointCompensation($state));
            $name = $parameters->get('UseBlackPtComp');
            self::assertInstanceOf(NameType::class, $name);
            self::assertSame($state->value, $name->value);
            $parameters->validateVersion(new EffectivePdfVersion(new FileHeader('2.0'), new DictionaryType()));
        }
        $this->expectException(\LogicException::class);
        $parameters->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), new DictionaryType()));
    }

    public function testBlackPointCompensationRejectsCaseVariations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GraphicsStateParameters($this->entry('UseBlackPtComp', new NameType('On')));
    }

    public function testMetadataPropertyResourcesCannotDisappearFromRetainedPageBytes(): void
    {
        $resource = new M\MetadataPropertyList($this->metadata());
        $resources = new ResourceDictionary(new \PDF\Document\Resources\Properties(new DictionaryType($this->entry('Info', $resource))));
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), $resources, new StreamObject(new ContentStreams(new Op\MarkedContentPointWithProperties(new NameType('Info'), new NameType('Info')))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $previous = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $replacement = (new DictionaryType(...iterator_to_array($page->entries(), false)))->with($this->entry('Resources', new DictionaryType()));
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($page)->identifier)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('current Properties resource');
        $update->toRevision();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUris(): iterable
    {
        foreach (['relative-profile', 'https://example.org/a b', 'https://example.org/%zz', 'https://example.org/é', 'https://example.org/#a#b', 'https://example.org/"/><element/>'] as $uri) {
            yield $uri => [$uri];
        }
    }
    #[DataProvider('invalidUris')]
    public function testMetadataIdentifiersRequireAsciiAbsoluteUriSyntax(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new M\MetadataUri($uri);
    }

    public function testUnicodeXmlNamesRemainValidForXmpVocabulariesAndDpm(): void
    {
        $packet = new M\XmpPacket(new M\XmpTextProperty(new M\XmpName('données', new M\MetadataUri('urn:example:french'), 'état'), new M\XmlText('révisé')));
        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML($packet->xml, LIBXML_NONET));
        self::assertSame('révisé', $xml->getElementsByTagNameNS('urn:example:french', 'état')->item(0)?->textContent);
    }

    public function testGenericInformationObjectsCannotHideInvalidAfEntries(): void
    {
        $object = new DictionaryType($this->entry('AF', new IntegerType(3)));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($this->entry('InformationResource', $object))->toPdf();
    }

    public function testShadingMetadataCannotBeAttachedToThePatternWrapper(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        M\MetadataResourceLocation::Shading->attach(new DictionaryType($this->entry('PatternType', new IntegerType(2))), $this->metadata());
    }
}
