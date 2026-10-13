<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\Object\ArrayType;

/** One direct reference per method; a certification signature may also have FieldMDP. */
final class SignatureReferences extends ArrayType
{
    /** @var list<SignatureReference> */
    public readonly array $references;
    public function __construct(SignatureReference ...$references)
    {
        $methods = [];
        foreach ($references as $reference) {
            $method = $reference->transform->method()->value;
            if (isset($methods[$method])) {
                throw new \InvalidArgumentException('A signature has at most one reference per transform method.');
            }
            $methods[$method] = true;
        }
        if (isset($methods['UR']) && count($methods) > 1) {
            throw new \InvalidArgumentException('A usage-rights signature is separate from form approval/certification signatures.');
        }
        $this->references = array_values($references);
        parent::__construct(...$references);
    }
    public function reference(SignatureTransformMethod $method): ?SignatureReference
    {
        foreach ($this->references as $reference) {
            if ($reference->transform->method() === $method) {
                return $reference;
            }
        }
        return null;
    }
}
