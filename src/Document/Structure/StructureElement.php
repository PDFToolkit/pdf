<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Definition\StructureElementDictionaryTable323Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Document-scoped hierarchy builder; all P/K links derive from attached child objects. */
final class StructureElement extends SchemaDictionary
{
    public const string TABLE = '323';
    private StructureElement|StructureTreeRoot|null $parentElement = null;
    /** @var list<StructureElement|PageMarkedContent|MarkedContentReference|ObjectReference> */
    private array $children = [];
    /** @var list<StructureElement> */
    private array $references = [];
    public readonly NameType $type;
    public readonly ?StructureNamespace $namespace;

    public function __construct(
        NameType|StandardStructureType|StructureRole $type,
        public readonly ?PageObject $page = null,
        public readonly ?StringType $identifier = null,
        public readonly ?StructureAttributes $attributes = null,
        public readonly ?AttributeClassAssociations $classes = null,
        public readonly IntegerType $revision = new IntegerType(0),
        Entry ...$entries,
    ) {
        if ($revision->integer() < 0) {
            throw new \InvalidArgumentException('A structure-element revision must be non-negative.');
        }
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Type', 'S', 'P', 'Pg', 'K', 'ID', 'A', 'C', 'R', 'NS'], true)) {
                throw new \InvalidArgumentException('Structural entries are supplied by the element constructor and hierarchy.');
            }
        }
        $this->namespace = $type instanceof StructureRole ? $type->namespace : null;
        $this->type = $type instanceof StructureRole ? $type->type : ($type instanceof StandardStructureType ? $type->name() : $type);
        $required = [
            new Entry(new NameType('Type'), new NameType('StructElem')),
            new Entry(new NameType('S'), $this->type),
            new Entry(new NameType('R'), $revision),
        ];
        if ($this->namespace !== null) {
            $required[] = new Entry(new NameType('NS'), new IndirectReference($this->namespace));
        }
        if ($page !== null) {
            $required[] = new Entry(new NameType('Pg'), new IndirectReference($page));
        }
        if ($identifier !== null) {
            $required[] = new Entry(new NameType('ID'), $identifier);
        }
        if ($attributes !== null) {
            $required[] = new Entry(new NameType('A'), $attributes->value());
        }
        if ($classes !== null) {
            $required[] = new Entry(new NameType('C'), $classes->value());
        }
        parent::__construct(new StructureElementDictionaryTable323Definition(), ...$required, ...$entries);
    }

    public function addKid(self|PageMarkedContent|MarkedContentReference|ObjectReference $child): self
    {
        if ($child instanceof self) {
            $child->attachTo($this);
        } elseif (in_array($child, $this->children, true)) {
            throw new \InvalidArgumentException('A content item cannot occur twice under the same parent.');
        }
        $this->children[] = $child;
        $node = $this->parentElement;
        while ($node instanceof self) {
            $node = $node->parentElement;
        }
        $node?->invalidate();
        return $this;
    }

    /** @internal Attach only through the parent builder. */
    public function attachTo(self|StructureTreeRoot $parent): void
    {
        if ($this->parentElement !== null) {
            throw new \InvalidArgumentException('A structure element can be attached only once.');
        }
        for ($node = $parent; $node instanceof self; $node = $node->parentElement) {
            if ($node === $this) {
                throw new \InvalidArgumentException('A structure hierarchy cannot contain a cycle.');
            }
        }
        $this->parentElement = $parent;
    }

    public function parent(): self|StructureTreeRoot|null
    {
        return $this->parentElement;
    }

    /** Explicit document-scoped graph construction also permits reciprocal FENote citations. */
    public function addReference(self $target): self
    {
        foreach (parent::entries() as $entry) {
            if ($entry->key->value === 'Ref') {
                throw new \InvalidArgumentException('Build Ref associations through one explicit mechanism.');
            }
        }
        if (in_array($target, $this->references, true)) {
            throw new \InvalidArgumentException('Build Ref associations through one explicit mechanism without duplicate targets.');
        }
        $this->references[] = $target;
        $node = $this->parentElement;
        while ($node instanceof self) {
            $node = $node->parentElement;
        }
        $node?->invalidate();
        return $this;
    }

    public function root(): StructureTreeRoot
    {
        $node = $this->parentElement;
        while ($node instanceof self) {
            $node = $node->parentElement;
        }
        return $node ?? throw new \LogicException('Attach an element to a structure tree before serialization.');
    }

    /** @return list<StructureElement|PageMarkedContent|MarkedContentReference|ObjectReference> */
    public function kids(): array
    {
        return $this->children;
    }

    public function entries(): iterable
    {
        $parent = $this->parentElement ?? throw new \LogicException('A structure element requires a parent.');
        if (!in_array($this, $parent->kids(), true)) {
            throw new \LogicException('A structure parent must contain its attached element.');
        }
        yield from parent::entries();
        if ($this->references !== []) {
            yield new StructureElementReferences(...$this->references);
        }
        yield new Entry(new NameType('P'), new IndirectReference($parent));
        if ($this->children !== []) {
            $values = array_map(fn (self|PageMarkedContent|MarkedContentReference|ObjectReference $child): BasicType => $this->kidValue($child), $this->children);
            yield new Entry(new NameType('K'), count($values) === 1 ? $values[0] : new ArrayType(...$values));
        }
    }

    public function toPdf(SerializationContext $context): string
    {
        $this->root()->index();
        return parent::toPdf($context);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Configure a structure element explicitly before attaching it.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Build a new element to change its attributes or metadata.');
    }

    private function kidValue(self|PageMarkedContent|MarkedContentReference|ObjectReference $child): BasicType
    {
        if ($child instanceof self) {
            return new IndirectReference($child);
        }
        if ($child instanceof PageMarkedContent) {
            if ($this->page !== $child->container->page) {
                throw new \InvalidArgumentException('A bare MCID requires the containing element to specify the same page in /Pg.');
            }
            return $child->identifier->number;
        }
        return $child;
    }

    private function __clone(): void
    {
    }
}
