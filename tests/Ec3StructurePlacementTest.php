<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\Document\Structure\AttributeAssociation;
use PDF\Document\Structure\AttributeClass;
use PDF\Document\Structure\AttributeClassAssociation;
use PDF\Document\Structure\AttributeClassAssociations;
use PDF\Document\Structure\AttributeResolver;
use PDF\Document\Structure\ClassMap;
use PDF\Document\Structure\MarkInformation;
use PDF\Document\Structure\RoleMap;
use PDF\Document\Structure\RoleMapping;
use PDF\Document\Structure\StandardStructureAttributes;
use PDF\Document\Structure\StandardStructureType;
use PDF\Document\Structure\StructureAttributeObject;
use PDF\Document\Structure\StructureAttributes;
use PDF\Document\Structure\StructureAttributeSemantics;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Ec3StructurePlacementTest extends TestCase
{
    #[DataProvider('contexts')]
    public function testPdf20FigureDefaultsDependOnTheContainingRole(StandardStructureType $parentRole, string $expected): void
    {
        $parent = new StructureElement($parentRole);
        $figure = new StructureElement(StandardStructureType::Figure);
        $parent->addKid($figure);
        $resolver = new AttributeResolver(new ClassMap(), new StandardStructureAttributes(StructureAttributeSemantics::Pdf20));
        self::assertSame($expected, $resolver->resolve($figure, new NameType('Layout'), new NameType('Placement'))->value);
        self::assertSame('Inline', (new AttributeResolver(new ClassMap()))->resolve($figure, new NameType('Layout'), new NameType('Placement'))->value);
    }

    public static function contexts(): iterable
    {
        yield [StandardStructureType::P, 'Inline'];
        yield [StandardStructureType::Sect, 'Block'];
        yield [StandardStructureType::Span, 'Inline'];
        yield [StandardStructureType::Div, 'Block'];
    }

    public function testExplicitContextIsUsedOnlyAfterDirectAndClassValues(): void
    {
        $class = new ClassMap(new AttributeClass(new NameType('Position'), $this->attribute('Placement', new NameType('Block'))));
        $resolver = new AttributeResolver($class, new StandardStructureAttributes(StructureAttributeSemantics::Pdf20));
        $context = new NameType('Start');
        $plain = new StructureElement(StandardStructureType::Figure);
        $classified = new StructureElement(StandardStructureType::Figure, classes: new AttributeClassAssociations(new AttributeClassAssociation(new NameType('Position'))));
        $explicit = new StructureElement(StandardStructureType::Figure, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Placement', new NameType('Inline')))), classes: $classified->classes);
        self::assertSame($context, $resolver->resolve($plain, new NameType('Layout'), new NameType('Placement'), contextDefault: $context));
        self::assertSame('Block', $resolver->resolve($classified, new NameType('Layout'), new NameType('Placement'), contextDefault: $context)->value);
        self::assertSame('Inline', $resolver->resolve($explicit, new NameType('Layout'), new NameType('Placement'), contextDefault: $context)->value);
        self::assertNull($resolver->resolve($plain, new NameType('Layout'), new NameType('Placement')));
    }

    public function testMappedParentRoleControlsTheContextWithoutInheritingItsPlacement(): void
    {
        $parent = new StructureElement(new NameType('Section'), attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Placement', new NameType('Inline')))));
        $figure = new StructureElement(StandardStructureType::Figure);
        $parent->addKid($figure);
        $resolver = new AttributeResolver(new ClassMap(), new StandardStructureAttributes(StructureAttributeSemantics::Pdf20), new RoleMap(new RoleMapping(new NameType('Section'), StandardStructureType::Sect)));
        self::assertSame('Block', $resolver->resolve($figure, new NameType('Layout'), new NameType('Placement'))->value);
    }

    public function testContextualBlockStopsLineHeightInheritance(): void
    {
        $parent = new StructureElement(StandardStructureType::Sect, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('LineHeight', new IntegerType(30)))));
        $figure = new StructureElement(StandardStructureType::Figure);
        $parent->addKid($figure);
        $resolver = new AttributeResolver(new ClassMap(), new StandardStructureAttributes(StructureAttributeSemantics::Pdf20));
        self::assertSame('Normal', $resolver->resolve($figure, new NameType('Layout'), new NameType('LineHeight'))->value);
    }

    public function testNativePdf20FigureBelowSectionUsesHeight(): void
    {
        self::assertStringContainsString('/Height 10', $this->file(StandardStructureType::Sect, 'Height', StructureAttributeSemantics::Pdf20, '2.0')->toPdf());
        self::assertStringContainsString('/Width 10', $this->file(StandardStructureType::P, 'Width', StructureAttributeSemantics::Pdf20, '2.0')->toPdf());
    }

    public function testHistoricalConstructionStillUsesInlineDefaults(): void
    {
        self::assertStringContainsString('/Width 10', $this->file(StandardStructureType::Sect, 'Width', StructureAttributeSemantics::Pdf17, '1.7')->toPdf());
    }

    public function testActualPdf20CannotBypassContextWithHistoricalConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('numeric Height');
        $this->file(StandardStructureType::Sect, 'Width', StructureAttributeSemantics::Pdf17, '2.0')->toPdf();
    }

    public function testPdf20ConstructionRequiresTheMatchingFileEdition(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PDF2 structure attribute semantics');
        $this->file(StandardStructureType::Sect, 'Height', StructureAttributeSemantics::Pdf20, '1.7')->toPdf();
    }

    private function attribute(string $key, NameType|IntegerType $value): StructureAttributeObject
    {
        return new StructureAttributeObject(new DictionaryType(new Entry(new NameType('O'), new NameType('Layout')), new Entry(new NameType($key), $value)));
    }

    private function file(StandardStructureType $parentRole, string $dimension, StructureAttributeSemantics $semantics, string $version): FileStructure
    {
        $pages = (new PageTreeNode())->addKid(new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(10), new IntegerType(10)), new ResourceDictionary()));
        $figure = new StructureElement(StandardStructureType::Figure, entries: new Entry(new NameType('Alt'), new LiteralString('An explicit illustration.')), attributes: new StructureAttributes(new AttributeAssociation($this->attribute($dimension, new IntegerType(10)))));
        $root = new StructureTreeRoot($pages, attributeSemantics: $semantics);
        $root->addKid((new StructureElement(StandardStructureType::Document))->addKid((new StructureElement($parentRole))->addKid($figure)));
        $catalog = new DocumentCatalog($pages, ...$root->catalogEntries(new MarkInformation(new BooleanType(true))));
        return new FileStructure(new FileHeader($version), new FileBody($catalog));
    }
}
