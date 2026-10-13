<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Table234 MDP: null omits P, 0 requests no certification, 1–3 select exact DocMDP permissions. */
final class SignatureSeedMdp extends DictionaryType
{
    public function __construct(public readonly ?IntegerType $permissions = null)
    {
        if ($permissions !== null && ($permissions->integer() < 0 || $permissions->integer() > 3)) {
            throw new \InvalidArgumentException('Seed MDP P is0–3.');
        }
        parent::__construct(...($permissions === null ? [] : [new Entry(new NameType('P'), $permissions)]));
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct another seed MDP choice.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct another seed MDP choice.');
    }
}
