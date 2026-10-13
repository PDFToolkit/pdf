<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\NumericType;

/** Explicit outside factors; evaluating PDF usage never reads process/OS state. */
final readonly class UsageEnvironment
{
    /** @param array<string, list<TextString>> $users Keys Ind/Ttl/Org. */
    public function __construct(public NumericType $magnification = new NumericType(1), public ?TextString $language = null, public array $users = [])
    {
        if ($magnification->value < 0) {
            throw new \InvalidArgumentException('Current magnification must be nonnegative.');
        }
        foreach ($users as $type => $names) {
            if (UserType::tryFrom($type) === null) {
                throw new \InvalidArgumentException('User environment identifiers require Ind/Ttl/Org categories.');
            }
            foreach ($names as $name) {
                if (!$name instanceof TextString) {
                    throw new \InvalidArgumentException('User identifiers must be explicit text strings.');
                }
            }
        }
    }
}
