<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Table235 SubjectDN values. Attribute aliases/OIDs are normalized by the external X509 provider. */
final class CertificateSubjectName extends DictionaryType
{
    public function __construct(Entry ...$attributes)
    {
        foreach ($attributes as $attribute) {
            if (preg_match('/^[A-Za-z0-9.]+$/D', $attribute->key()->value) !== 1 || !$attribute->value instanceof TextString) {
                throw new \InvalidArgumentException('SubjectDN has legal ASCII attribute identifiers and text values.');
            }
        }
        parent::__construct(...$attributes);
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct a new normalized SubjectDN.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct a new normalized SubjectDN.');
    }
}
