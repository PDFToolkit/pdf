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
use PDF\Document\Structure\MarkedContentContainer;
use PDF\Document\Structure\MarkedContentIdentifier;
use PDF\Document\Structure\MarkedContentReference;
use PDF\Document\Structure\ObjectReference;
use PDF\Document\Structure\PageMarkedContent;
use PDF\Document\Structure\PageContentSequence;
use PDF\Document\Structure\PageContentFragment;
use PDF\Document\Structure\RoleMap;
use PDF\Document\Structure\RoleMapping;
use PDF\Document\Structure\StandardStructureAttributes;
use PDF\Document\Structure\StandardStructureType;
use PDF\Document\Structure\StructureAttributeObject;
use PDF\Document\Structure\StructureAttributes;
use PDF\Document\Structure\StructureColumns;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureLimits;
use PDF\Document\Structure\StructureNamespace;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\Document\Structure\TaggedStructureValidator;
use PDF\Document\Structure\UserProperties;
use PDF\Document\Structure\UserProperty;
use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

final class TaggedStructureTest extends TestCase
{
    public function testIndexesJoinOneParagraphAcrossPagesAndPreserveSparseMcids(): void
    {
        $page1 = $this->page($this->content(0), 2);
        $page2 = $this->page($this->content(2), 6);
        $pages = (new PageTreeNode())->addKid($page1)->addKid($page2);
        $root = new StructureTreeRoot($pages);
        $document = new StructureElement(StandardStructureType::Document);
        $paragraph = new StructureElement(StandardStructureType::P, $page1, new LiteralString("paragraph\0one"));
        $paragraph->addKid(new PageMarkedContent(new MarkedContentContainer($page1, new IntegerType(2)), $this->mcid(0)));
        $paragraph->addKid(new MarkedContentReference(new MarkedContentContainer($page2, new IntegerType(6)), $this->mcid(2)));
        $document->addKid($paragraph);
        $root->addKid($document);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
        $index = $root->index();
        self::assertSame($index, $root->index());
        self::assertSame(7, $index->nextParentKey->integer());
        $pairs = $index->parentTree?->get('Nums');
        self::assertInstanceOf(ArrayType::class, $pairs);
        self::assertSame(2, $pairs->items[0]->value);
        self::assertSame(6, $pairs->items[2]->value);
        self::assertInstanceOf(IndirectReference::class, $pairs->items[3]);
        $parents = $pairs->items[3]->target();
        self::assertInstanceOf(ArrayType::class, $parents);
        self::assertInstanceOf(NullType::class, $parents->items[0]);
        self::assertInstanceOf(NullType::class, $parents->items[1]);
        self::assertSame($paragraph, $parents->items[2]->target());
        $names = $index->identifierTree?->get('Names');
        self::assertInstanceOf(ArrayType::class, $names);
        self::assertSame("paragraph\0one", $names->items[0]->value);
        self::assertSame($paragraph, $names->items[1]->target());
        self::assertSame($document, $paragraph->get('P')->target());
        self::assertSame($root, $document->get('P')->target());
        self::assertSame(566, $paragraph->definition()->source->pdfPage);
    }

    public function testOneCompleteFileSerializesWithStableIndexObjects(): void
    {
        [$root, $page, $paragraph] = $this->simpleTree();
        $catalog = new DocumentCatalog($root->pages, ...$root->catalogEntries(new MarkInformation(new BooleanType(true))));
        $file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
        $bytes = $file->toPdf();
        self::assertStringContainsString('/StructTreeRoot ', $bytes);
        self::assertStringContainsString('/ParentTree ', $bytes);
        self::assertStringContainsString('/Marked true', $bytes);
        self::assertStringContainsString('/MCID 0', $bytes);
        self::assertStringContainsString('/StructParents 0', $bytes);
        self::assertStringNotContainsString('/R 0', $bytes);
        self::assertSame(0, $paragraph->revision->integer());
    }

    public function testMutationInvalidatesOnlyTheDocumentLocalIndexAndKeepsCatalogValidation(): void
    {
        [$root, $page, $paragraph] = $this->simpleTree();
        $root->catalogEntries(new MarkInformation(new BooleanType(true)));
        $first = $root->index();
        $paragraph->addKid(new StructureElement(StandardStructureType::Span, identifier: new LiteralString('span')));
        self::assertNotSame($first, $root->index());
        self::assertCount(3, $root->index()->elements);
        $root->addKid(new StructureElement(StandardStructureType::Document));
        $this->expectException(\InvalidArgumentException::class);
        $root->toPdf(new SerializationContext());
    }

    public function testRoleMapPreservesLegalCyclesAndTerminatesUnrecognizedOnes(): void
    {
        $roles = new RoleMap(new RoleMapping(new NameType('A'), new NameType('B')), new RoleMapping(new NameType('B'), new NameType('A')));
        self::assertNull($roles->resolve(new NameType('A')));
        self::assertStringContainsString('/A /B /B /A', $roles->toPdf(new SerializationContext()));
        $roles = new RoleMap(new RoleMapping(new NameType('P'), new NameType('Custom')), new RoleMapping(new NameType('Custom'), StandardStructureType::H));
        self::assertSame(StandardStructureType::H, $roles->resolve(StandardStructureType::P));
        $selfMapping = new RoleMap(new RoleMapping(new NameType('P'), StandardStructureType::P));
        self::assertSame(StandardStructureType::P, $selfMapping->resolve(StandardStructureType::P));
    }

    public function testCustomRolesAreLegalForLogicalStructureButRequireTaggedMapping(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode());
        $root->addKid(new StructureElement(new NameType('PrivateChapter')));
        self::assertCount(1, $root->index()->elements);
        $this->expectException(\InvalidArgumentException::class);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
    }

    public function testDeepCustomRoleMappingResolvesForTaggedStructure(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode(), new RoleMap(
            new RoleMapping(new NameType('Chapter'), new NameType('Section')),
            new RoleMapping(new NameType('Section'), StandardStructureType::Sect),
        ));
        $root->addKid(new StructureElement(new NameType('Chapter')));
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
        self::assertCount(1, $root->index()->elements);
    }

    public function testHierarchyRejectsCycles(): void
    {
        $parent = new StructureElement(StandardStructureType::Div);
        $child = new StructureElement(StandardStructureType::Div);
        $parent->addKid($child);
        $this->expectException(\InvalidArgumentException::class);
        $child->addKid($parent);
    }

    public function testHierarchyRejectsSharingOneElementBetweenParents(): void
    {
        $child = new StructureElement(StandardStructureType::P);
        (new StructureElement(StandardStructureType::Div))->addKid($child);
        $this->expectException(\InvalidArgumentException::class);
        (new StructureElement(StandardStructureType::Div))->addKid($child);
    }

    public function testUnattachedElementCannotSerialize(): void
    {
        $this->expectException(\LogicException::class);
        (new StructureElement(StandardStructureType::P))->toPdf(new SerializationContext());
    }

    public function testDuplicateIdsAreRejectedAcrossTheWholeTree(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode());
        $root->addKid(new StructureElement(StandardStructureType::P, identifier: new LiteralString('same')));
        $root->addKid(new StructureElement(StandardStructureType::P, identifier: new LiteralString('same')));
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testForeignContentPagesAreRejected(): void
    {
        $page = $this->page($this->content(0));
        (new PageTreeNode())->addKid($page);
        $root = new StructureTreeRoot(new PageTreeNode());
        $root->addKid(new StructureElement(StandardStructureType::P, $page));
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testBareMcidCannotInheritPgFromAnAncestor(): void
    {
        [$root, $page, $paragraph] = $this->simpleTree();
        $child = new StructureElement(StandardStructureType::Span);
        $child->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)));
        $paragraph->addKid($child);
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testDuplicateParentAssignmentsAreRejected(): void
    {
        [$root, $page, $paragraph] = $this->simpleTree();
        $paragraph->addKid(new MarkedContentReference(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)));
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testMissingStreamMcidIsRejectedBeforeSerialization(): void
    {
        $page = $this->page($this->content(1));
        $pages = (new PageTreeNode())->addKid($page);
        $root = new StructureTreeRoot($pages);
        $element = new StructureElement(StandardStructureType::P, $page);
        $element->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)));
        $root->addKid($element);
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testNestedStructuralSequencesAreRejected(): void
    {
        $page = $this->page(new ContentStreams($this->mcid(0)->begin(StandardStructureType::P), $this->mcid(1)->begin(StandardStructureType::Span), new EndMarkedContent(), new EndMarkedContent()));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $element = new StructureElement(StandardStructureType::P, $page);
        $scope = new MarkedContentContainer($page, new IntegerType(0));
        $element->addKid(new PageMarkedContent($scope, $this->mcid(0)))->addKid(new PageMarkedContent($scope, $this->mcid(1)));
        $root->addKid($element);
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testCombinedPageStreamsUseOneMcidNamespace(): void
    {
        $page = new PageObject($this->box(), new ResourceDictionary(), new ArrayType(new IndirectReference(new StreamObject($this->content(0))), new IndirectReference(new StreamObject($this->content(0)))), $this->entry('StructParents', new IntegerType(0)));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $element = new StructureElement(StandardStructureType::P, $page);
        $element->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)));
        $root->addKid($element);
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testOpaqueStreamsCannotPretendToHaveVerifiedStructure(): void
    {
        $page = new PageObject($this->box(), new ResourceDictionary(), new StreamObject('/P << /MCID 0 >> BDC EMC'), $this->entry('StructParents', new IntegerType(0)));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $element = new StructureElement(StandardStructureType::P, $page);
        $element->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)));
        $root->addKid($element);
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testExplicitParentArrayResourceLimitRejectsHugeSparseIds(): void
    {
        $page = $this->page($this->content(500));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page), limits: new StructureLimits(maximumParentArrayLength: 16));
        $element = new StructureElement(StandardStructureType::P, $page);
        $element->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(500)));
        $root->addKid($element);
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testNegativeMcidIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mcid(-1);
    }

    public function testStructParentAndStructParentsAreMutuallyExclusive(): void
    {
        $page = new PageObject($this->box(), new ResourceDictionary(), new StreamObject($this->content(0)), $this->entry('StructParents', new IntegerType(0)), $this->entry('StructParent', new IntegerType(0)));
        (new PageTreeNode())->addKid($page);
        $this->expectException(\InvalidArgumentException::class);
        (new MarkedContentContainer($page, new IntegerType(0)))->validate();
    }

    public function testDirectAttributesOverrideClassesAndExportOverridesStandardOwner(): void
    {
        $classes = new ClassMap(new AttributeClass(new NameType('Normal'), $this->attribute('Layout', 'TextAlign', new NameType('Start'))));
        $element = new StructureElement(StandardStructureType::P, attributes: new StructureAttributes(
            new AttributeAssociation($this->attribute('Layout', 'TextAlign', new NameType('Center'))),
            new AttributeAssociation($this->attribute('HTML-4.01', 'TextAlign', new NameType('Justify'))),
        ), classes: new AttributeClassAssociations(new AttributeClassAssociation(new NameType('Normal'))));
        $resolver = new AttributeResolver($classes);
        self::assertSame('Center', $resolver->resolve($element, new NameType('Layout'), new NameType('TextAlign'))->value);
        self::assertSame('Justify', $resolver->resolve($element, new NameType('Layout'), new NameType('TextAlign'), new NameType('HTML-4.01'))->value);
    }

    public function testInheritanceHonorsNegativeQualifiersAndStopsAtExplicitValues(): void
    {
        $parent = new StructureElement(StandardStructureType::Div, attributes: new StructureAttributes(
            new AttributeAssociation($this->attribute('Layout', 'WritingMode', new NameType('RlTb'))),
            new AttributeAssociation($this->attribute('Layout', 'Padding', new IntegerType(50))),
            new AttributeAssociation($this->attribute('Layout', 'SpaceBefore', new IntegerType(30))),
        ));
        $child = new StructureElement(StandardStructureType::P);
        $parent->addKid($child);
        $resolver = new AttributeResolver(new ClassMap());
        self::assertSame('RlTb', $resolver->resolve($child, new NameType('Layout'), new NameType('WritingMode'))->value);
        self::assertSame(0, $resolver->resolve($child, new NameType('Layout'), new NameType('Padding'))->value);
        self::assertSame(0, $resolver->resolve($child, new NameType('Layout'), new NameType('SpaceBefore'))->value);
        $override = new StructureElement(StandardStructureType::Span, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Layout', 'WritingMode', new NameType('TbRl')))));
        $child->addKid($override);
        self::assertSame('TbRl', $resolver->resolve($override, new NameType('Layout'), new NameType('WritingMode'))->value);
    }

    public function testContinuedListAndArtifactAttributesNeverInherit(): void
    {
        $catalogue = new \PDF\Specification\Iso32000_2\Catalogue();
        foreach ([['382', 'List', 'ContinuedList', new BooleanType(true)], ['382', 'List', 'ContinuedFrom', new LiteralString('previous')], ['385', 'Artifact', 'Type', new NameType('Pagination')], ['385', 'Artifact', 'BBox', $this->box()], ['385', 'Artifact', 'Subtype', new NameType('Header')]] as [$table, $owner, $key, $value]) {
            $definition = $catalogue->dictionary($table)->entry($key);
            self::assertFalse($definition->requirement->inheritable);
            self::assertSame('2.0', $definition->requirement->introducedIn);
            $parent = new StructureElement(StandardStructureType::Div, attributes: new StructureAttributes(new AttributeAssociation($this->attribute($owner, $key, $value))));
            $child = new StructureElement(StandardStructureType::Span);
            $parent->addKid($child);
            $resolver = new AttributeResolver(new ClassMap());
            self::assertSame($value, $resolver->resolve($parent, new NameType($owner), new NameType($key)));
            self::assertNull($resolver->resolve($child, new NameType($owner), new NameType($key)));
        }
    }

    public function testAllStandardOwnersHaveSourceDefinitionsAndDefaults(): void
    {
        $standard = new StandardStructureAttributes();
        foreach (['Layout', 'List', 'PrintField', 'Table'] as $owner) {
            self::assertNotNull($standard->definition(new NameType($owner)));
        }
        self::assertSame('None', $standard->defaultValue(new NameType('List'), new NameType('ListNumbering'))->value);
        self::assertSame('off', $standard->defaultValue(new NameType('PrintField'), new NameType('checked'))->value);
        self::assertSame(1, $standard->defaultValue(new NameType('Table'), new NameType('RowSpan'))->value);
        self::assertFalse($standard->inheritable(new NameType('Layout'), new NameType('BBox')));
        self::assertFalse($standard->inheritable(new NameType('Table'), new NameType('Scope')));
        self::assertTrue($standard->inheritable(new NameType('List'), new NameType('ListNumbering')));
    }

    public function testRevisionAssociationEncodingAndStalenessAreExplicit(): void
    {
        $association = new AttributeAssociation($this->attribute('Layout', 'TextAlign', new NameType('Start')), new IntegerType(2));
        $classes = new AttributeClassAssociations(new AttributeClassAssociation(new NameType('Normal'), new IntegerType(2)));
        $element = new StructureElement(StandardStructureType::P, attributes: new StructureAttributes($association), classes: $classes, revision: new IntegerType(3));
        (new StructureTreeRoot(new PageTreeNode(), classMap: new ClassMap(new AttributeClass(new NameType('Normal'), $association->attribute))))->addKid($element);
        self::assertSame($element->revision, $element->get('R'));
        self::assertStringContainsString('/R 3', $element->toPdf(new SerializationContext()));
        self::assertFalse($association->currentFor($element));
        self::assertFalse($classes->associations[0]->currentFor($element));
        $value = $element->attributes->value();
        self::assertInstanceOf(ArrayType::class, $value);
        self::assertSame(2, $value->items[1]->value);
        self::assertSame(2, $classes->value()->items[1]->value);
        self::assertInstanceOf(DictionaryType::class, (new StructureAttributes(new AttributeAssociation($association->attribute)))->value());
    }

    public function testUndefinedClassContributesNoAttributesAndDoesNotChangeClassMap(): void
    {
        $classes = new ClassMap(new AttributeClass(new NameType('Empty')));
        $root = new StructureTreeRoot(new PageTreeNode(), classMap: $classes);
        $parent = new StructureElement(StandardStructureType::Div, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Layout', 'WritingMode', new NameType('RlTb')))));
        $child = new StructureElement(StandardStructureType::P, classes: new AttributeClassAssociations(new AttributeClassAssociation(new NameType('Missing'))));
        $root->addKid($parent->addKid($child));
        $root->index();
        $resolver = new AttributeResolver($classes);
        self::assertSame([], $classes->attributeClass(new NameType('Missing'))->attributes);
        self::assertFalse($classes->has('Missing'));
        self::assertSame('[]', $classes->get('Empty')->toPdf(new SerializationContext()));
        self::assertFalse($resolver->hasExplicit($child, new NameType('Layout'), new NameType('WritingMode')));
        self::assertSame('RlTb', $resolver->resolve($child, new NameType('Layout'), new NameType('WritingMode'))->value);
        self::assertSame('Start', $resolver->resolve($child, new NameType('Layout'), new NameType('TextAlign'))->value);
    }

    public function testLaterAttributeObjectsAndClassesOverrideEarlierValues(): void
    {
        $namespace = new StructureNamespace('urn:example:attributes');
        $namespaced = fn (int $value): StructureAttributeObject => new StructureAttributeObject(new DictionaryType($this->entry('NS', new IndirectReference($namespace)), $this->entry('Value', new IntegerType($value))));
        $classes = new ClassMap(
            new AttributeClass(new NameType('First'), $this->attribute('Layout', 'TextAlign', new NameType('Start')), $this->attribute('Layout', 'TextAlign', new NameType('Center')), $namespaced(1)),
            new AttributeClass(new NameType('Last'), $this->attribute('Layout', 'TextAlign', new NameType('End')), $namespaced(2)),
        );
        $first = new StructureElement(StandardStructureType::P, classes: new AttributeClassAssociations(new AttributeClassAssociation(new NameType('First'))));
        $combined = new StructureElement(StandardStructureType::P, classes: new AttributeClassAssociations(new AttributeClassAssociation(new NameType('First')), new AttributeClassAssociation(new NameType('Missing')), new AttributeClassAssociation(new NameType('Last'))));
        $resolver = new AttributeResolver($classes);
        self::assertSame('Center', $resolver->resolve($first, new NameType('Layout'), new NameType('TextAlign'))->value);
        self::assertSame('End', $resolver->resolve($combined, new NameType('Layout'), new NameType('TextAlign'))->value);
        self::assertSame(2, $resolver->resolve($combined, new NameType($namespace->identifier), new NameType('Value'))->value);
    }

    public function testPlacementUsesDirectAttributesBeforeClassOrderToDetermineInheritance(): void
    {
        $classes = new ClassMap(new AttributeClass(new NameType('Position'), $this->attribute('Layout', 'Placement', new NameType('Inline')), $this->attribute('Layout', 'Placement', new NameType('Block'))));
        $parent = new StructureElement(StandardStructureType::P, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Layout', 'LineHeight', new IntegerType(30)))));
        $block = new StructureElement(StandardStructureType::Span, classes: new AttributeClassAssociations(new AttributeClassAssociation(new NameType('Position'))));
        $inline = new StructureElement(StandardStructureType::Span, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Layout', 'Placement', new NameType('Inline')))), classes: $block->classes);
        $parent->addKid($block)->addKid($inline);
        $resolver = new AttributeResolver($classes);
        self::assertSame('Normal', $resolver->resolve($block, new NameType('Layout'), new NameType('LineHeight'))->value);
        self::assertSame(30, $resolver->resolve($inline, new NameType('Layout'), new NameType('LineHeight'))->value);
    }

    public function testConflictingDirectAttributesStillFail(): void
    {
        $element = new StructureElement(StandardStructureType::P, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Layout', 'TextAlign', new NameType('Start'))), new AttributeAssociation($this->attribute('Layout', 'TextAlign', new NameType('End')))));
        $this->expectException(\LogicException::class);
        (new AttributeResolver(new ClassMap()))->resolve($element, new NameType('Layout'), new NameType('TextAlign'));
    }

    public function testUserPropertiesRequireAnExplicitMarkInformationFlag(): void
    {
        $properties = new UserProperties(new UserProperty(new LiteralString('Part Number'), new IntegerType(11603)), new UserProperty(new LiteralString('Price'), new NumericType(-37.99), new LiteralString('$37.99'), new BooleanType(true)));
        $root = new StructureTreeRoot(new PageTreeNode());
        $root->addKid(new StructureElement(StandardStructureType::Document, attributes: new StructureAttributes(new AttributeAssociation(new StructureAttributeObject($properties)))));
        self::assertTrue($root->index()->hasUserProperties);
        $this->expectException(\InvalidArgumentException::class);
        $root->catalogEntries(new MarkInformation(new BooleanType(false)));
    }

    public function testUserPropertiesCanBeDeclaredWithLogicalOrTaggedStructure(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode());
        $root->addKid(new StructureElement(StandardStructureType::Document, attributes: new StructureAttributes(new AttributeAssociation(new StructureAttributeObject(new UserProperties(new UserProperty(new LiteralString('Name'), new LiteralString('value'))))))));
        self::assertCount(2, $root->catalogEntries(new MarkInformation(new BooleanType(true), new BooleanType(true))));
    }

    public function testColumnDimensionsRepeatTheLastValueAndIgnoreExcessValues(): void
    {
        $columns = new StructureColumns(new IntegerType(3), new ArrayType(new IntegerType(100), new IntegerType(120)), new ArrayType(new IntegerType(10), new IntegerType(20), new IntegerType(999)));
        self::assertSame([100, 120, 120], array_map(static fn (NumericType $number): int|float => $number->value, $columns->columnWidths()));
        self::assertSame([10, 20], array_map(static fn (NumericType $number): int|float => $number->value, $columns->columnGaps()));
        self::assertSame([], (new StructureColumns(new IntegerType(1), new IntegerType(100), new ArrayType()))->columnGaps());
    }

    public function testWholeObjectsCreateSingleParentEntries(): void
    {
        $object = new DictionaryType($this->entry('StructParent', new IntegerType(9)));
        $page = $this->page(new ContentStreams());
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $element = new StructureElement(StandardStructureType::P, $page);
        $element->addKid(new ObjectReference($page, $object, new IntegerType(9)));
        $root->addKid($element);
        $pairs = $root->index()->parentTree->get('Nums');
        self::assertSame(9, $pairs->items[0]->value);
        self::assertSame($element, $pairs->items[1]->target());
        self::assertSame(10, $root->index()->nextParentKey->integer());
    }

    public function testAnnotationObjectReferenceRequiresPageMembership(): void
    {
        $object = new DictionaryType($this->entry('Type', new NameType('Annot')), $this->entry('StructParent', new IntegerType(9)));
        $page = $this->page(new ContentStreams());
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $root->addKid((new StructureElement(StandardStructureType::Annot, $page))->addKid(new ObjectReference($page, $object, new IntegerType(9))));
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testGroupingElementCannotOwnContentDirectlyInTaggedPdf(): void
    {
        $page = $this->page($this->content(0));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $root->addKid((new StructureElement(StandardStructureType::Document, $page))->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0))));
        self::assertCount(1, $root->index()->elements);
        $this->expectException(\InvalidArgumentException::class);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
    }

    public function testTableHeadersAreResolvedTransitivelyByIdentifiers(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode());
        $document = new StructureElement(StandardStructureType::Document);
        $table = new StructureElement(StandardStructureType::Table);
        $first = new StructureElement(StandardStructureType::TH, identifier: new LiteralString('first'));
        $second = new StructureElement(StandardStructureType::TH, identifier: new LiteralString('second'), attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Table', 'Headers', new ArrayType(new LiteralString('first'))))));
        $cell = new StructureElement(StandardStructureType::TD, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Table', 'Headers', new ArrayType(new LiteralString('second'))))));
        $table->addKid((new StructureElement(StandardStructureType::TR))->addKid($first)->addKid($second)->addKid($cell));
        $root->addKid($document->addKid($table));
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
        $validator = new TaggedStructureValidator($root->roleMap, new AttributeResolver($root->classMap));
        self::assertSame([$second, $first], $validator->headers($cell, $root->index()));
    }

    public function testHeaderIdsMustResolveToActualHeaderElements(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode());
        $document = new StructureElement(StandardStructureType::Document);
        $document->addKid(new StructureElement(StandardStructureType::P, identifier: new LiteralString('not-header')));
        $document->addKid(new StructureElement(StandardStructureType::TD, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Table', 'Headers', new ArrayType(new LiteralString('not-header')))))));
        $root->addKid($document);
        $this->expectException(\InvalidArgumentException::class);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
    }

    public function testManagedGraphEntriesCannotBeOverriddenThroughGenericEntries(): void
    {
        [$root, $page, $paragraph] = $this->simpleTree();
        $this->expectException(\LogicException::class);
        $root->with($this->entry('ParentTree', new DictionaryType()));
    }

    public function testAttributeStreamsPreserveOpaqueBytesAndReferenceTheirOwner(): void
    {
        $stream = new StreamObject('opaque application attributes', new DictionaryType($this->entry('O', new NameType('CAD'))));
        $attribute = new StructureAttributeObject($stream);
        self::assertSame('CAD', $attribute->owner->value);
        self::assertInstanceOf(IndirectReference::class, $attribute->value());
        self::assertSame($stream, $attribute->value()->target());
    }

    public function testStandardAttributeTypeMismatchesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->attribute('Layout', 'WritingMode', new IntegerType(2));
    }

    public function testNamedPropertyResourcesAreResolvedByObjectIdentity(): void
    {
        $properties = new DictionaryType($this->entry('MCID', new IntegerType(0)));
        $resources = new ResourceDictionary($this->entry('Properties', new DictionaryType($this->entry('Paragraph', new IndirectReference($properties)))));
        $page = new PageObject($this->box(), $resources, new StreamObject(new ContentStreams(new BeginMarkedContentWithProperties(new NameType('P'), new NameType('Paragraph')), new EndMarkedContent())), $this->entry('StructParents', new IntegerType(0)));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $paragraph = (new StructureElement(StandardStructureType::P, $page))->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)));
        $root->addKid((new StructureElement(StandardStructureType::Document))->addKid($paragraph));
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
        self::assertSame($paragraph, $root->index()->markedParents[0][0]);
    }

    public function testAuxiliaryFormMcrUsesTheStreamParentKeyInsteadOfThePageKey(): void
    {
        [$root, $page, $stream, $paragraph] = $this->formTree(1, false);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
        $index = $root->index();
        self::assertSame($stream, $index->containers[2]->object());
        self::assertSame($paragraph, $index->markedParents[2][0]);
        $reference = $paragraph->kids()[0];
        self::assertSame($stream, $reference->get('Stm')->target());
        self::assertSame($page, $reference->get('Pg')->target());
    }

    public function testRepeatedFormInvocationCannotUseInternalStructuralMcids(): void
    {
        [$root] = $this->formTree(2, false);
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testStructuralPageContentCannotInvokeAnInternallyStructuredForm(): void
    {
        [$root] = $this->formTree(1, true);
        $this->expectException(\InvalidArgumentException::class);
        $root->index();
    }

    public function testAnnotationsJoinObjectParentTreeAndPageAnnots(): void
    {
        $annotation = new DictionaryType($this->entry('Type', new NameType('Annot')), $this->entry('Subtype', new NameType('Link')), $this->entry('Rect', $this->box()), $this->entry('StructParent', new IntegerType(5)));
        $page = new PageObject($this->box(), new ResourceDictionary(), new StreamObject($this->content(0)), $this->entry('StructParents', new IntegerType(0)), $this->entry('Annots', new ArrayType(new IndirectReference($annotation))));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $link = (new StructureElement(StandardStructureType::Link, $page))->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)))->addKid(new ObjectReference($page, $annotation, new IntegerType(5)));
        $root->addKid((new StructureElement(StandardStructureType::Document))->addKid($link));
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
        self::assertSame($annotation, $root->index()->objects[0]->object);
        self::assertSame(6, $root->index()->nextParentKey->integer());
    }

    public function testFractionalTablePaddingAndNumericGlyphOrientationFollowTheValueProse(): void
    {
        $padding = $this->attribute('Layout', 'TPadding', new NumericType(0.5));
        self::assertSame(0.5, $padding->dictionary()->get('TPadding')->value);
        foreach ([-180, -90, 0, 90, 180, 270, 360] as $angle) {
            self::assertSame($angle, $this->attribute('Layout', 'GlyphOrientationVertical', new IntegerType($angle))->dictionary()->get('GlyphOrientationVertical')->value);
        }
    }

    public function testGlyphOrientationRejectsNonQuarterTurns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->attribute('Layout', 'GlyphOrientationVertical', new IntegerType(45));
    }

    public function testRgbValuesRejectOutOfRangeAndWrongComponentCounts(): void
    {
        self::assertInstanceOf(StructureAttributeObject::class, $this->attribute('Layout', 'BorderColor', new ArrayType(new ArrayType(new IntegerType(0), new NumericType(0.5), new IntegerType(1)), new NullType(), new NullType(), new NullType())));
        $this->expectException(\InvalidArgumentException::class);
        $this->attribute('Layout', 'Color', new ArrayType(new IntegerType(0), new IntegerType(0), new NumericType(1.1)));
    }

    public function testPrintFieldStatesRejectUnexpectedNames(): void
    {
        self::assertInstanceOf(StructureAttributeObject::class, $this->attribute('PrintField', 'checked', new NameType('neutral')));
        $this->expectException(\InvalidArgumentException::class);
        $this->attribute('PrintField', 'checked', new NameType('maybe'));
    }

    public function testUnknownListNumberingRemainsAnExtensibleName(): void
    {
        // Table 347 permits the set to expand as Unicode adds numbering systems.
        self::assertSame('FutureSystem', $this->attribute('List', 'ListNumbering', new NameType('FutureSystem'))->dictionary()->get('ListNumbering')->value);
    }

    public function testLineHeightDoesNotPropagateThroughANestedBlock(): void
    {
        $parent = new StructureElement(StandardStructureType::P, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Layout', 'LineHeight', new IntegerType(30)))));
        $span = new StructureElement(StandardStructureType::Span);
        $nestedBlock = new StructureElement(StandardStructureType::P);
        $parent->addKid($span)->addKid($nestedBlock);
        $resolver = new AttributeResolver(new ClassMap());
        self::assertSame(30, $resolver->resolve($span, new NameType('Layout'), new NameType('LineHeight'))->value);
        self::assertSame('Normal', $resolver->resolve($nestedBlock, new NameType('Layout'), new NameType('LineHeight'))->value);
    }

    public function testAddingAPageAfterCatalogBindingCannotBypassTaggedValidation(): void
    {
        [$root] = $this->simpleTree();
        $root->catalogEntries(new MarkInformation(new BooleanType(true)));
        $root->pages->addKid($this->page($this->content(0), 5));
        $this->expectException(\InvalidArgumentException::class);
        $root->toPdf(new SerializationContext());
    }

    public function testExplicitUnitSpanIsStillInvalidOnANonCell(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode());
        $root->addKid(new StructureElement(StandardStructureType::P, attributes: new StructureAttributes(new AttributeAssociation($this->attribute('Table', 'RowSpan', new IntegerType(1))))));
        $this->expectException(\InvalidArgumentException::class);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
    }

    public function testColumnExpansionHasAnExplicitResourceLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StructureColumns(new IntegerType(PHP_INT_MAX), new IntegerType(100), new IntegerType(10));
    }

    public function testCatalogValidationRejectsAForeignPageTree(): void
    {
        [$root] = $this->simpleTree();
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('StructTreeRoot'), new IndirectReference($root)));
        $this->expectException(\InvalidArgumentException::class);
        (new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog)))->toPdf();
    }

    public function testCatalogValidationHonorsGenericMarkInformationDictionaries(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode());
        $root->addKid(new StructureElement(new NameType('Unmapped')));
        $catalog = new DocumentCatalog($root->pages, new Entry(new NameType('StructTreeRoot'), new IndirectReference($root)), new Entry(new NameType('MarkInfo'), new DictionaryType($this->entry('Marked', new BooleanType(true)))));
        $this->expectException(\InvalidArgumentException::class);
        (new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog)))->toPdf();
    }

    public function testInlineRootCannotDuplicateTheCanonicalParentObject(): void
    {
        [$root] = $this->simpleTree();
        $catalog = new DocumentCatalog($root->pages, new Entry(new NameType('StructTreeRoot'), $root));
        $this->expectException(\InvalidArgumentException::class);
        (new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog)))->toPdf();
    }

    public function testNestedLinksAreRejectedAfterRoleMapping(): void
    {
        $root = new StructureTreeRoot(new PageTreeNode(), new RoleMap(new RoleMapping(new NameType('Hyperlink'), StandardStructureType::Link)));
        $document = new StructureElement(StandardStructureType::Document);
        $document->addKid((new StructureElement(StandardStructureType::Link))->addKid((new StructureElement(StandardStructureType::Span))->addKid(new StructureElement(new NameType('Hyperlink')))));
        $root->addKid($document);
        $this->expectException(\InvalidArgumentException::class);
        $root->validateTagged(new MarkInformation(new BooleanType(true)));
    }

    public function testPageContentPartsCanSpanAMarkedSequenceWithoutCrossingPages(): void
    {
        $sequence = new PageContentSequence(new ContentStreams($this->mcid(0)->begin(StandardStructureType::P)), new ContentStreams(new EndMarkedContent()));
        $page = new PageObject($this->box(), new ResourceDictionary(), $sequence->contents(), $this->entry('StructParents', new IntegerType(0)));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $paragraph = (new StructureElement(StandardStructureType::P, $page))->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)));
        $root->addKid((new StructureElement(StandardStructureType::Document))->addKid($paragraph));
        $catalog = new DocumentCatalog($root->pages, ...$root->catalogEntries(new MarkInformation(new BooleanType(true))));
        $bytes = (new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog)))->toPdf();
        self::assertStringContainsString('/Contents [', $bytes);
        self::assertCount(2, $sequence->streams());
        self::assertSame($sequence->streams()[0], $sequence->contents()->items[0]->target());
        self::assertStringContainsString('/MCID 0', $bytes);
    }

    public function testUnattachedPageFragmentsCannotEmitPartialScopes(): void
    {
        $this->expectException(\LogicException::class);
        (new PageContentFragment($this->mcid(0)->begin(StandardStructureType::P)))->toPdf(new SerializationContext());
    }

    public function testCombinedPageContentStillRejectsUnbalancedMarkedScopes(): void
    {
        $this->expectException(\LogicException::class);
        new PageContentSequence(new ContentStreams($this->mcid(0)->begin(StandardStructureType::P)), new ContentStreams());
    }

    /** @return array{StructureTreeRoot, PageObject, StreamObject, StructureElement} */
    private function formTree(int $invocations, bool $nested): array
    {
        $stream = new StreamObject($this->content(0), new DictionaryType($this->entry('Type', new NameType('XObject')), $this->entry('Subtype', new NameType('Form')), $this->entry('BBox', $this->box()), $this->entry('StructParents', new IntegerType(2))));
        $resources = new ResourceDictionary($this->entry('XObject', new DictionaryType($this->entry('Fm', new IndirectReference($stream)))));
        $operators = $nested ? [$this->mcid(0)->begin(StandardStructureType::P)] : [];
        for ($index = 0; $index < $invocations; ++$index) {
            $operators[] = new InvokeXObject(new NameType('Fm'));
        }
        if ($nested) {
            $operators[] = new EndMarkedContent();
        }
        $page = new PageObject($this->box(), $resources, new StreamObject(new ContentStreams(...$operators)), $this->entry('StructParents', new IntegerType(0)));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $paragraph = (new StructureElement(StandardStructureType::P, $page))->addKid(new MarkedContentReference(new MarkedContentContainer($page, new IntegerType(2), $stream), $this->mcid(0)));
        if ($nested) {
            $paragraph->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)));
        }
        $root->addKid((new StructureElement(StandardStructureType::Document))->addKid($paragraph));
        return [$root, $page, $stream, $paragraph];
    }

    /** @return array{StructureTreeRoot, PageObject, StructureElement} */
    private function simpleTree(): array
    {
        $page = $this->page($this->content(0));
        $root = new StructureTreeRoot((new PageTreeNode())->addKid($page));
        $paragraph = new StructureElement(StandardStructureType::P, $page);
        $paragraph->addKid(new PageMarkedContent(new MarkedContentContainer($page, new IntegerType(0)), $this->mcid(0)));
        $root->addKid((new StructureElement(StandardStructureType::Document))->addKid($paragraph));
        return [$root, $page, $paragraph];
    }

    private function page(ContentStreams $content, int $key = 0): PageObject
    {
        return new PageObject($this->box(), new ResourceDictionary(), new StreamObject($content), $this->entry('StructParents', new IntegerType($key)));
    }

    private function box(): Rectangle
    {
        return new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(612), new IntegerType(792));
    }

    private function content(int $id): ContentStreams
    {
        return new ContentStreams($this->mcid($id)->begin(StandardStructureType::P), new EndMarkedContent());
    }

    private function mcid(int $id): MarkedContentIdentifier
    {
        return new MarkedContentIdentifier(new IntegerType($id));
    }

    private function attribute(string $owner, string $key, \PDF\Syntax\FileStructure\BasicType $value): StructureAttributeObject
    {
        return new StructureAttributeObject(new DictionaryType($this->entry('O', new NameType($owner)), $this->entry($key, $value)));
    }

    private function entry(string $key, \PDF\Syntax\FileStructure\BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
}
