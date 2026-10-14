<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;

/** A document-local canonical namespace set. Identity disagreements never resolve by name alone. */
final readonly class StructureNamespaces
{
    /** @var list<StructureNamespace> */
    public array $items;

    public function __construct(StructureNamespace ...$namespaces)
    {
        $names = [];
        foreach ($namespaces as $namespace) {
            if (isset($names[$namespace->identifier])) {
                throw new \InvalidArgumentException('A namespace identifier must have exactly one canonical dictionary in this document.');
            }
            $names[$namespace->identifier] = true;
        }
        $this->items = array_values($namespaces);
        foreach ($this->items as $namespace) {
            foreach ($namespace->roleMap->targets() as $target) {
                $this->require($target->namespace);
            }
        }
    }

    public function require(?StructureNamespace $namespace): void
    {
        if ($namespace !== null && !in_array($namespace, $this->items, true)) {
            throw new \InvalidArgumentException('Every used or targeted namespace must belong to the document namespace set.');
        }
    }

    public function value(): ArrayType
    {
        return new ArrayType(...array_map(static fn (StructureNamespace $namespace): IndirectReference => new IndirectReference($namespace), $this->items));
    }
}
