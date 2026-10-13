<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;

/** Exact qualified field names. A nonterminal name also selects its descendants. */
final class SignatureFieldNames extends ArrayType
{
    /** @var list<TextString> */
    public readonly array $names;
    public function __construct(TextString ...$names)
    {
        $seen = [];
        foreach ($names as $name) {
            if (isset($seen[$name->text])) {
                throw new \InvalidArgumentException('Signature field selections have distinct names.');
            }
            $seen[$name->text] = true;
        }
        $this->names = array_values($names);
        parent::__construct(...$names);
    }
    public function selects(string $qualifiedName): bool
    {
        foreach ($this->names as $name) {
            if ($name->text === $qualifiedName || str_starts_with($qualifiedName, $name->text . '.')) {
                return true;
            }
        }
        return false;
    }
}
