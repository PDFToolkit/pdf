<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Structure\NamespaceRoleMap;
use PDF\Document\Structure\NamespaceRoleMapping;
use PDF\Document\Structure\NamespaceRoleResolver;
use PDF\Document\Structure\RoleMap;
use PDF\Document\Structure\RoleMapping;
use PDF\Document\Structure\StandardStructureNamespace;
use PDF\Document\Structure\StandardStructureType;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureInclusionRules;
use PDF\Document\Structure\StructureInclusionTable;
use PDF\Document\Structure\StructureNamespace;
use PDF\Document\Structure\StructureNamespaces;
use PDF\Document\Structure\StructureRole;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\Document\Structure\MarkInformation;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PHPUnit\Framework\TestCase;

final class StructureNamespaceTest extends TestCase
{
    public function testTransitiveCrossNamespaceRoleMappingUsesCanonicalObjects(): void
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $intermediate = new StructureNamespace('urn:example:intermediate', new NamespaceRoleMap(new NamespaceRoleMapping(new NameType('Paragraph'), new StructureRole(StandardStructureType::P, $pdf))));
        $custom = new StructureNamespace('urn:example:custom', new NamespaceRoleMap(new NamespaceRoleMapping(new NameType('Body'), new StructureRole(new NameType('Paragraph'), $intermediate))));
        $resolver = new NamespaceRoleResolver(new StructureNamespaces($pdf, $intermediate, $custom));
        $result = $resolver->resolve(new StructureRole(new NameType('Body'), $custom));
        self::assertSame('P', $result?->type->value);
        self::assertSame($pdf, $result?->namespace);
        self::assertStringContainsString('/RoleMapNS << /Body [/Paragraph ', $custom->toPdf(new SerializationContext()));
    }

    public function testNameOnlyNamespaceMappingTargetsDefaultPdf17NotItsSourceNamespace(): void
    {
        $custom = new StructureNamespace('urn:example:custom', new NamespaceRoleMap(new NamespaceRoleMapping(new NameType('Body'), new StructureRole(StandardStructureType::P))));
        $resolver = new NamespaceRoleResolver(new StructureNamespaces($custom));
        self::assertNull($resolver->resolve(new StructureRole(new NameType('Body'), $custom))?->namespace);
        self::assertSame('P', $resolver->resolve(new StructureRole(new NameType('Body'), $custom))?->type->value);
    }

    public function testPdf20AcceptsArbitraryPositiveHeadingLevelsAndRejectsLiteralHn(): void
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $resolver = new NamespaceRoleResolver(new StructureNamespaces($pdf));
        self::assertSame('H42', $resolver->resolve(new StructureRole(new NameType('H42'), $pdf))?->type->value);
        self::assertNull($resolver->resolve(new StructureRole(new NameType('Hn'), $pdf)));
        self::assertNull($resolver->resolve(new StructureRole(new NameType('H0'), $pdf)));
        self::assertNull($resolver->resolve(new StructureRole(StandardStructureType::TOC, $pdf)));
    }

    public function testDefaultRoleMappingTerminatesCyclesAndRecognizesStandardTarget(): void
    {
        $resolver = new NamespaceRoleResolver(new StructureNamespaces(), new RoleMap(new RoleMapping(new NameType('A'), new NameType('B')), new RoleMapping(new NameType('B'), new NameType('A'))));
        self::assertNull($resolver->resolve(new StructureRole(new NameType('A'))));
        $resolver = new NamespaceRoleResolver(new StructureNamespaces(), new RoleMap(new RoleMapping(new NameType('P'), StandardStructureType::P)));
        self::assertSame('P', $resolver->resolve(new StructureRole(StandardStructureType::P))?->type->value);
    }

    public function testExplicitPdf17NamespaceUsesItsOwnRoleMapForCustomNames(): void
    {
        $legacy = new StructureNamespace(StandardStructureNamespace::PDF17, new NamespaceRoleMap(new NamespaceRoleMapping(new NameType('CustomNote'), new StructureRole(StandardStructureType::Note))));
        $resolver = new NamespaceRoleResolver(new StructureNamespaces($legacy));
        self::assertSame('Note', $resolver->resolve(new StructureRole(new NameType('CustomNote'), $legacy))?->type->value);
    }

    public function testNamespaceSetRejectsDuplicateIdentifiersEvenOnDifferentObjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StructureNamespaces(new StructureNamespace('urn:example:test'), new StructureNamespace('urn:example:test'));
    }

    public function testNamespaceSetRejectsAnUndeclaredMappingTarget(): void
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $custom = new StructureNamespace('urn:example:custom', new NamespaceRoleMap(new NamespaceRoleMapping(new NameType('Body'), new StructureRole(StandardStructureType::P, $pdf))));
        $this->expectException(\InvalidArgumentException::class);
        new StructureNamespaces($custom);
    }

    public function testFullSourceMatrixRetainsEveryTypeAndSourceAmbiguity(): void
    {
        self::assertCount(53, StructureInclusionTable::RULES);
        self::assertSame('1', StructureInclusionTable::RULES['StructTreeRoot']['children']['Document']);
        self::assertSame('c', StructureInclusionTable::RULES['WP']['children']['Figure']);
        self::assertSame('0..1', StructureInclusionTable::RULES['Table']['children']['THead']);
        self::assertSame(hash_file('sha256', __DIR__ . '/../resources/reference-documents/ISO-TS-32005-2023-sponsored.pdf'), StructureInclusionTable::SOURCE_SHA256);
    }

    public function testMixedNamespacesSerializeAnExplicitPdf17TableOfContents(): void
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $legacy = new StructureNamespace(StandardStructureNamespace::PDF17);
        $root = new StructureTreeRoot(new PageTreeNode(), namespaces: new StructureNamespaces($pdf, $legacy), inclusionRules: new StructureInclusionRules());
        $document = new StructureElement(new StructureRole(StandardStructureType::Document, $pdf));
        $toc = new StructureElement(new StructureRole(StandardStructureType::TOC, $legacy));
        $toc->addKid(new StructureElement(new StructureRole(StandardStructureType::TOCI, $legacy)));
        $document->addKid($toc);
        $root->addKid($document);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
        self::assertCount(3, $root->index()->elements);
        self::assertStringContainsString('/Namespaces [', $root->toPdf(new SerializationContext()));
        self::assertStringContainsString('/NS ', $toc->toPdf(new SerializationContext()));
    }

    public function testMixedTreeRejectsSharedTypeInPdf17Namespace(): void
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $root = new StructureTreeRoot(new PageTreeNode(), namespaces: new StructureNamespaces($pdf));
        $root->addKid((new StructureElement(new StructureRole(StandardStructureType::Document, $pdf)))->addKid(new StructureElement(StandardStructureType::P)));
        $this->expectException(\InvalidArgumentException::class);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
    }

    public function testInvalidTableContainmentFailsBeforeSerialization(): void
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $root = new StructureTreeRoot(new PageTreeNode(), namespaces: new StructureNamespaces($pdf));
        $root->addKid((new StructureElement(new StructureRole(StandardStructureType::Document, $pdf)))->addKid(new StructureElement(new StructureRole(StandardStructureType::TD, $pdf))));
        $this->expectException(\InvalidArgumentException::class);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
    }

    public function testVersionValidationRequiresPdf20(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode(), inclusionRules: new StructureInclusionRules());
        $this->expectException(\LogicException::class);
        $root->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), new DictionaryType()));
    }

    public function testCodeSubCorrectionIsExplicitlySelected(): void
    {
        foreach (\PDF\Document\Structure\StructureInclusionCorrections::cases() as $corrections) {
            $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
            $legacy = new StructureNamespace(StandardStructureNamespace::PDF17);
            $root = new StructureTreeRoot(new PageTreeNode(), namespaces: new StructureNamespaces($pdf, $legacy), inclusionRules: new StructureInclusionRules($corrections));
            $root->addKid((new StructureElement(new StructureRole(StandardStructureType::Document, $pdf)))->addKid((new StructureElement(new StructureRole(new NameType('Code'), $legacy)))->addKid(new StructureElement(new StructureRole(new NameType('Sub'), $pdf)))));
            try {
                $root->validateTagged(new MarkInformation(new BooleanType(true)));
                self::assertSame(\PDF\Document\Structure\StructureInclusionCorrections::Resolved336And440, $corrections);
            } catch (\InvalidArgumentException $error) {
                self::assertSame(\PDF\Document\Structure\StructureInclusionCorrections::Published2023, $corrections);
                self::assertStringContainsString('Sub below Code', $error->getMessage());
            }
        }
    }

    public function testTheUndefinedWpFigureCellRequiresAnExplicitResolvedCorrection(): void
    {
        foreach (\PDF\Document\Structure\StructureInclusionCorrections::cases() as $corrections) {
            $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
            $element = static fn (string $name): StructureElement => new StructureElement(new StructureRole(new NameType($name), $pdf));
            $root = new StructureTreeRoot(new PageTreeNode(), namespaces: new StructureNamespaces($pdf), inclusionRules: new StructureInclusionRules($corrections));
            $warichu = $element('Warichu')->addKid($element('WP')->addKid($element('Figure')))->addKid($element('WT'))->addKid($element('WP'));
            $root->addKid($element('Document')->addKid($element('P')->addKid($warichu)));
            try {
                $root->validateTagged(new MarkInformation(new BooleanType(true)));
                self::assertSame(\PDF\Document\Structure\StructureInclusionCorrections::Resolved336And440, $corrections);
            } catch (\LogicException $error) {
                self::assertSame(\PDF\Document\Structure\StructureInclusionCorrections::Published2023, $corrections);
                self::assertStringContainsString('undefined c', $error->getMessage());
            }
        }
    }

    public function testTransparentGroupingInheritsTheContainingDocumentRules(): void
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $element = static fn (string $name): StructureElement => new StructureElement(new StructureRole(new NameType($name), $pdf));
        $root = new StructureTreeRoot(new PageTreeNode(), namespaces: new StructureNamespaces($pdf));
        $root->addKid($element('Document')->addKid($element('Part')->addKid($element('Div')->addKid($element('H42')))));
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
        self::assertCount(4, $root->index()->elements);
    }

    public function testTableOccurrenceBoundsAreAppliedToResolvedTypes(): void
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $element = static fn (string $name): StructureElement => new StructureElement(new StructureRole(new NameType($name), $pdf));
        $root = new StructureTreeRoot(new PageTreeNode(), namespaces: new StructureNamespaces($pdf));
        $root->addKid($element('Document')->addKid($element('Table')->addKid($element('THead'))->addKid($element('THead'))));
        $this->expectException(\InvalidArgumentException::class);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
    }

    public function testActualWriterRejectsNamespacedStructureBelowPdf20(): void
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $page = new \PDF\Syntax\DocumentStructure\PageTree\PageObject(new \PDF\Syntax\DataStructure\Rectangle(new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(100), new \PDF\Syntax\Object\IntegerType(100)), new \PDF\Document\ResourceDictionary());
        $pages = (new PageTreeNode())->addKid($page);
        $root = new StructureTreeRoot($pages, namespaces: new StructureNamespaces($pdf));
        $root->addKid(new StructureElement(new StructureRole(StandardStructureType::Document, $pdf)));
        $catalog = new \PDF\Syntax\DocumentStructure\DocumentCatalog($pages, ...$root->catalogEntries(new MarkInformation(new BooleanType(true))));
        $this->expectException(\LogicException::class);
        (new \PDF\FileStructure(new FileHeader('1.7'), new \PDF\Syntax\FileStructure\FileBody($catalog)))->toPdf();
    }

    public function testNamespaceGraphValidatorChecksGenericDictionaryMappingShapes(): void
    {
        $page = new \PDF\Syntax\DocumentStructure\PageTree\PageObject(new \PDF\Syntax\DataStructure\Rectangle(new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(100), new \PDF\Syntax\Object\IntegerType(100)), new \PDF\Document\ResourceDictionary());
        $namespace = new DictionaryType(new \PDF\Syntax\Object\DictionaryObject\Entry(new NameType('Type'), new NameType('Namespace')), new \PDF\Syntax\Object\DictionaryObject\Entry(new NameType('NS'), new \PDF\Syntax\DataStructure\TextString('urn:example:bad')), new \PDF\Syntax\Object\DictionaryObject\Entry(new NameType('RoleMapNS'), new DictionaryType(new \PDF\Syntax\Object\DictionaryObject\Entry(new NameType('Bad'), new \PDF\Syntax\Object\ArrayType(new NameType('P'))))));
        $catalog = new \PDF\Syntax\DocumentStructure\DocumentCatalog((new PageTreeNode())->addKid($page), new \PDF\Syntax\Object\DictionaryObject\Entry(new NameType('CustomNamespace'), new \PDF\Syntax\Object\IndirectReference($namespace)));
        $this->expectException(\InvalidArgumentException::class);
        (new \PDF\FileStructure(new FileHeader('2.0'), new \PDF\Syntax\FileStructure\FileBody($catalog)))->toPdf();
    }
}
