<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** Explicit document-scoped forward binding for the FieldMDP Data reference. */
final class SignatureDataReference
{
    private ?IndirectReference $reference = null;
    public function __construct(?IndirectReference $reference = null)
    {
        if ($reference !== null) {
            $this->bind($reference);
        }
    }
    public function bind(IndirectReference $reference): self
    {
        if (!$reference->target() instanceof DictionaryType) {
            throw new \InvalidArgumentException('FieldMDP Data identifies a document/form dictionary.');
        }
        if ($this->reference !== null && ($this->reference->target() !== $reference->target() || $this->reference->identifier !== $reference->identifier)) {
            throw new \LogicException('A signature Data reference belongs to one document target.');
        }
        $this->reference = $reference;
        return $this;
    }
    public function reference(): IndirectReference
    {
        return $this->reference ?? throw new \LogicException('Bind FieldMDP Data explicitly before writing the signature graph.');
    }
}
