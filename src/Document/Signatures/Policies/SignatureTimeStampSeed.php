<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Table234 timestamp server metadata; this object never contacts the server. */
final class SignatureTimeStampSeed extends DictionaryType
{
    public function __construct(public readonly AsciiString $url, public readonly bool $required = false)
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:[^\\x00-\\x20\\x7f]*$/D', $url->value) !== 1) {
            throw new \InvalidArgumentException('A timestamp server URL is an ASCII URI without control/space bytes.');
        }
        parent::__construct(new Entry(new NameType('URL'), $url), new Entry(new NameType('Ff'), new IntegerType($required ? 1 : 0)));
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct another timestamp seed.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct another timestamp seed.');
    }
}
