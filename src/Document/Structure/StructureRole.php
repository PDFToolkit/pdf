<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\NameType;

/** A type and its exact namespace; null denotes the default PDF 1.7 namespace. */
final readonly class StructureRole
{
    public NameType $type;

    public function __construct(NameType|StandardStructureType $type, public ?StructureNamespace $namespace = null)
    {
        $this->type = $type instanceof StandardStructureType ? $type->name() : $type;
    }

    public function namespaceName(): string
    {
        return $this->namespace->identifier ?? StandardStructureNamespace::PDF17->value;
    }

    public function tableType(): string
    {
        return preg_match('/^H[1-9][0-9]*$/D', $this->type->value) === 1 ? 'Hn' : $this->type->value;
    }
}
