<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Document\CatalogComponent;
use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Specification\Definition\StructureTreeRootTable322Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\NumberTree;
use PDF\Syntax\DataStructure\NumberTreeEntry;
use PDF\Syntax\DocumentStructure\CatalogDictionary\MarkInfo;
use PDF\Syntax\DocumentStructure\CatalogDictionary\StructTreeRoot;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Logical-structure graph and document-local derived ParentTree/IDTree (PDF pages 564–577). */
final class StructureTreeRoot extends SchemaDictionary implements CatalogComponent, VersionedComponent
{
    public const string TABLE = '322';
    /** @var list<StructureElement> */
    private array $children = [];
    private ?StructureTreeIndex $cachedIndex = null;
    private ?MarkInformation $markInformation = null;

    public function __construct(
        public readonly PageTreeNode $pages,
        public readonly RoleMap $roleMap = new RoleMap(),
        public readonly ClassMap $classMap = new ClassMap(),
        public readonly StructureLimits $limits = new StructureLimits(),
        public readonly StructureNamespaces $namespaces = new StructureNamespaces(),
        public readonly ?StructureInclusionRules $inclusionRules = null,
    ) {
        if ($pages->parent() !== null) {
            throw new \InvalidArgumentException('A structure tree requires the document page-tree root.');
        }
        parent::__construct(new StructureTreeRootTable322Definition(), new Entry(new NameType('Type'), new NameType('StructTreeRoot')));
    }

    public function addKid(StructureElement $element): self
    {
        $element->attachTo($this);
        $this->children[] = $element;
        $this->invalidate();
        return $this;
    }

    /** @internal Document-local cache invalidation when the attached hierarchy changes. */
    public function invalidate(): void
    {
        $this->cachedIndex = null;
    }

    /** @return list<StructureElement> */
    public function kids(): array
    {
        return $this->children;
    }

    /** Builds and validates all bidirectional associations before any graph bytes are emitted. */
    public function index(): StructureTreeIndex
    {
        $cachedIndex = $this->cachedIndex;
        if ($cachedIndex !== null) {
            (new StructureContentValidator())->validate($cachedIndex, false, $this->pages);
            $information = $this->markInformation;
            if ($information !== null && $information->marked->value) {
                // The caller can still extend the separate mutable page tree.
                $this->validateTaggedIndex($cachedIndex, $information);
            }
            return $cachedIndex;
        }
        $elements = [];
        $ids = [];
        $idPairs = [];
        $containers = [];
        $marked = [];
        $whole = [];
        $objects = [];
        $keys = [];
        $hasUserProperties = false;
        $seen = new \SplObjectStorage();
        $queue = array_reverse($this->children);
        while ($queue !== []) {
            $element = array_pop($queue);
            if ($seen->offsetExists($element) || count($elements) >= $this->limits->maximumElements) {
                throw new \InvalidArgumentException('The structure graph contains a repeated element or exceeds its explicit construction limit.');
            }
            $seen->offsetSet($element);
            $element->validate();
            if ($element->root() !== $this) {
                throw new \InvalidArgumentException('All elements must belong to the same structure tree.');
            }
            $elements[] = $element;
            $this->namespaces->require($element->namespace);
            if ($element->page !== null) {
                $this->validatePage($element->page);
            }
            if ($element->identifier !== null) {
                $id = $element->identifier->value;
                if (isset($ids[$id])) {
                    throw new \InvalidArgumentException('Structure-element identifiers must be unique in this document.');
                }
                $ids[$id] = true;
                $idPairs[] = new NameTreeEntry($element->identifier, new IndirectReference($element));
            }
            $attributes = array_map(static fn (AttributeAssociation $association): StructureAttributeObject => $association->attribute, $element->attributes === null ? [] : $element->attributes->associations);
            foreach ($element->classes === null ? [] : $element->classes->associations as $association) {
                array_push($attributes, ...$this->classMap->attributeClass($association->name)->attributes);
            }
            foreach ($attributes as $attribute) {
                $this->namespaces->require($attribute->namespace);
                if ($attribute->owner->value === 'UserProperties') {
                    $properties = $attribute->dictionary()->get('P');
                    if (!$properties instanceof ArrayType) {
                        throw new \InvalidArgumentException('UserProperties attributes require a /P array.');
                    }
                    foreach ($properties->items as $property) {
                        $property = $property instanceof IndirectReference ? $property->target() : $property;
                        if (!$property instanceof \PDF\Syntax\Object\DictionaryType || !$property->get('N') instanceof \PDF\Syntax\Object\StringType || $property->get('V') === null) {
                            throw new \InvalidArgumentException('Each user property requires a text name /N and value /V.');
                        }
                    }
                    $hasUserProperties = true;
                }
            }
            foreach (array_reverse($element->kids()) as $child) {
                if ($child instanceof StructureElement) {
                    if ($child->parent() !== $element) {
                        throw new \InvalidArgumentException('Structure parent and child links must agree.');
                    }
                    $queue[] = $child;
                    continue;
                }
                if ($child instanceof PageMarkedContent || $child instanceof MarkedContentReference) {
                    $container = $child->container;
                    $this->validatePage($container->page);
                    $container->validate();
                    $key = $container->parentTreeKey->integer();
                    $objectId = spl_object_id($container->object());
                    if (isset($keys[$key]) && $keys[$key] !== $objectId) {
                        throw new \InvalidArgumentException('Parent-tree keys must identify distinct content containers or entire objects.');
                    }
                    if (isset($whole[$key])) {
                        throw new \InvalidArgumentException('An entire content object cannot also contain structural marked-content items.');
                    }
                    if (isset($containers[$key]) && $containers[$key]->page !== $container->page) {
                        throw new \InvalidArgumentException('Marked content in an auxiliary stream may not be associated with several rendered pages.');
                    }
                    $keys[$key] = $objectId;
                    $containers[$key] = $container;
                    $id = $child->identifier->number->integer();
                    if ($id >= $this->limits->maximumParentArrayLength || isset($marked[$key][$id])) {
                        throw new \InvalidArgumentException('A structural MCID must have one parent and fit the explicit parent-array construction limit.');
                    }
                    $marked[$key][$id] = $element;
                } else {
                    $this->validatePage($child->page);
                    $child->validateBinding();
                    $key = $child->parentTreeKey->integer();
                    $objectId = spl_object_id($child->object);
                    if ((isset($keys[$key]) && $keys[$key] !== $objectId) || isset($containers[$key]) || (isset($whole[$key]) && $whole[$key] !== $element)) {
                        throw new \InvalidArgumentException('An entire content object must have one structural parent and a unique parent-tree key.');
                    }
                    $keys[$key] = $objectId;
                    $whole[$key] = $element;
                    $objects[] = $child;
                }
            }
        }
        $pairs = [];
        foreach ($elements as $element) {
            $references = $element->get('Ref');
            if ($references !== null) {
                if (!$references instanceof ArrayType) {
                    throw new \InvalidArgumentException('A structure-element Ref association must be an array.');
                }
                foreach ($references as $reference) {
                    if (!$reference instanceof IndirectReference || !$reference->target() instanceof StructureElement || !in_array($reference->target(), $elements, true)) {
                        throw new \InvalidArgumentException('Ref associations must identify structure elements in this same document tree.');
                    }
                }
            }
        }
        foreach ($marked as $key => $parents) {
            $values = [];
            $last = max(array_keys($parents));
            for ($id = 0; $id <= $last; ++$id) {
                $values[] = isset($parents[$id]) ? new IndirectReference($parents[$id]) : new NullType();
            }
            $pairs[] = new NumberTreeEntry(new IntegerType($key), new IndirectReference(new ArrayType(...$values)));
        }
        foreach ($whole as $key => $parent) {
            $pairs[] = new NumberTreeEntry(new IntegerType($key), new IndirectReference($parent));
        }
        $maximum = $keys === [] ? -1 : max(array_keys($keys));
        if ($maximum === PHP_INT_MAX) {
            throw new \InvalidArgumentException('ParentTreeNextKey cannot exceed the integer representation.');
        }
        $index = new StructureTreeIndex($elements, $pairs === [] ? null : new NumberTree(...$pairs), $idPairs === [] ? null : new NameTree(...$idPairs), new IntegerType($maximum + 1), $containers, $marked, $objects, $hasUserProperties);
        (new StructureContentValidator())->validate($index, false, $this->pages);
        $markInformation = $this->markInformation;
        if ($markInformation !== null) {
            $this->validateMarkInformation($index, $markInformation);
            if ($markInformation->marked->value) {
                $this->validateTaggedIndex($index, $markInformation);
            }
        }
        $this->cachedIndex = $index;
        return $index;
    }

    public function validateTagged(MarkInformation $markInformation): void
    {
        $index = $this->index();
        $this->validateTaggedIndex($index, $markInformation);
    }

    private function validateTaggedIndex(StructureTreeIndex $index, MarkInformation $markInformation): void
    {
        if (!$markInformation->marked->value || count($this->children) !== 1) {
            throw new \InvalidArgumentException('Tagged PDF requires MarkInfo.Marked=true and exactly one root structure element.');
        }
        $this->validateMarkInformation($index, $markInformation);
        if ($this->namespaces->items !== [] || $this->inclusionRules !== null) {
            ($this->inclusionRules ?? new StructureInclusionRules())->validate($this, $index);
        } else {
            $validator = new TaggedStructureValidator($this->roleMap, new AttributeResolver($this->classMap, roles: $this->roleMap));
            $validator->validate($index);
        }
        (new StructureContentValidator())->validate($index, true, $this->pages);
    }

    /**
     * Explicit catalog entries; the caller chooses its conformance statement and remaining catalog entries.
     * @return list<Entry>
     */
    public function catalogEntries(MarkInformation $markInformation): array
    {
        if ($markInformation->marked->value) {
            $this->validateTagged($markInformation);
        } else {
            $this->validateMarkInformation($this->index(), $markInformation);
        }
        $this->markInformation = $markInformation;
        return [new StructTreeRoot(new IndirectReference($this)), new MarkInfo($markInformation)];
    }

    /** Writer integration: validates the actual catalog, including generic schema dictionaries. */
    public function validateCatalog(DictionaryType $catalog): void
    {
        $pages = $catalog->get('Pages');
        if (!$pages instanceof IndirectReference || $pages->target() !== $this->pages) {
            throw new \InvalidArgumentException('Catalog Pages must reference the page tree used to construct its logical structure.');
        }
        $structure = $catalog->get('StructTreeRoot');
        if (!$structure instanceof IndirectReference || $structure->target() !== $this) {
            throw new \InvalidArgumentException('The managed structure root must be the catalog canonical indirect root, so P references identify the same object.');
        }
        $information = $catalog->get('MarkInfo');
        $information = $information instanceof IndirectReference ? $information->target() : $information;
        if ($information !== null && !$information instanceof DictionaryType) {
            throw new \InvalidArgumentException('Catalog MarkInfo must be a dictionary.');
        }
        $flags = [];
        foreach (['Marked', 'UserProperties', 'Suspects'] as $key) {
            $flag = $information?->get($key) ?? new BooleanType(false);
            $flag = $flag instanceof IndirectReference ? $flag->target() : $flag;
            $flag = $flag instanceof NullType ? new BooleanType(false) : $flag;
            if (!$flag instanceof BooleanType) {
                throw new \InvalidArgumentException('MarkInfo.' . $key . ' must be a boolean.');
            }
            $flags[] = $flag;
        }
        $markInformation = new MarkInformation(...$flags);
        $index = $this->index();
        $this->validateMarkInformation($index, $markInformation);
        if ($markInformation->marked->value) {
            $this->validateTaggedIndex($index, $markInformation);
        }
        $this->markInformation = $markInformation;
    }

    public function entries(): iterable
    {
        $index = $this->index();
        yield from parent::entries();
        if ($this->namespaces->items !== []) {
            yield new Entry(new NameType('Namespaces'), $this->namespaces->value());
        }
        if ($this->children !== []) {
            yield new Entry(new NameType('K'), new ArrayType(...array_map(static fn (StructureElement $element): IndirectReference => new IndirectReference($element), $this->children)));
        }
        if ($index->parentTree !== null) {
            yield new Entry(new NameType('ParentTree'), new IndirectReference($index->parentTree));
            yield new Entry(new NameType('ParentTreeNextKey'), $index->nextParentKey);
        }
        if ($index->identifierTree !== null) {
            yield new Entry(new NameType('IDTree'), new IndirectReference($index->identifierTree));
        }
        if (count($this->roleMap) !== 0) {
            yield new Entry(new NameType('RoleMap'), $this->roleMap);
        }
        if (count($this->classMap) !== 0) {
            yield new Entry(new NameType('ClassMap'), $this->classMap);
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Structure-tree entries are derived from explicit hierarchy, role and class objects.');
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        if ($this->namespaces->items !== [] || $this->inclusionRules !== null) {
            $version->require(PdfVersion::PDF20, 'Structure namespaces and TS 32005 inclusion rules');
        }
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Structure-tree indexes cannot be removed independently of their content.');
    }

    private function validatePage(PageObject $page): void
    {
        $node = $page->parent();
        if ($node === null) {
            throw new \InvalidArgumentException('Attach content pages to the document page tree before validating structure.');
        }
        while ($node->parent() !== null) {
            $node = $node->parent();
        }
        if ($node !== $this->pages) {
            throw new \InvalidArgumentException('A structural content page must belong to this document.');
        }
    }

    private function validateMarkInformation(StructureTreeIndex $index, MarkInformation $markInformation): void
    {
        if ($index->hasUserProperties && !$markInformation->userProperties->value) {
            throw new \InvalidArgumentException('UserProperties attributes require MarkInfo.UserProperties=true.');
        }
    }

    private function __clone(): void
    {
    }
}
