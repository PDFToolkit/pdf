<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\SignatureDerElement;

/** An explicitly retained RFC 3161 extension, without inventing its application semantics. */
final readonly class TimestampExtension
{
    public function __construct(public SignatureDerElement $identifier, public string $value, public bool $critical = false)
    {
        $identifier->requireObjectIdentifier();
    }

    public static function fromDer(SignatureDerElement $element): self
    {
        $parts = $element->requireTag(48)->children();
        if (count($parts) < 2 || count($parts) > 3 || (count($parts) === 3 && $parts[1]->encoding !== "\x01\x01\xff")) {
            throw new \InvalidArgumentException('Timestamp extensions contain an OID, optional DER TRUE critical flag and octets.');
        }
        return new self($parts[0], $parts[array_key_last($parts)]->requireTag(4)->value, count($parts) === 3);
    }
}
