<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

/** Explicit intent selection; an empty array intentionally considers no groups. */
final readonly class Intents
{
    /** @var list<Intent> */
    public array $intents;

    public function __construct(Intent ...$intents)
    {
        $this->intents = array_values($intents);
    }

    public function value(): NameType|ArrayType
    {
        $names = array_map(static fn (Intent $intent): NameType => new NameType($intent->value), $this->intents);
        return count($names) === 1 ? $names[0] : new ArrayType(...$names);
    }

    public function includes(Intents $group): bool
    {
        if (in_array(Intent::All, $this->intents, true)) {
            return true;
        }
        foreach ($group->intents as $intent) {
            if (in_array($intent, $this->intents, true)) {
                return true;
            }
        }
        return false;
    }
}
