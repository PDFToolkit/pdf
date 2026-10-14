<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** ISO 32000-2 Table28, physical p.110; PDF20_AN002-AF.pdf p.10/§4.1.5. */
final class EncryptedPayload extends DictionaryType implements VersionedComponent
{
    public function __construct(public readonly NameType $securityHandler, public readonly ?NameType $version = null)
    {
        if ($securityHandler->value === '' || ($version !== null && preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $version->value) !== 1)) {
            throw new \InvalidArgumentException('An encrypted payload requires a named security handler and an optional dotted integer version name.');
        }
        $entries = [new Entry(new NameType('Type'), new NameType('EncryptedPayload')), new Entry(new NameType('Subtype'), $securityHandler)];
        if ($version !== null) {
            $entries[] = new Entry(new NameType('Version'), $version);
        }
        parent::__construct(...$entries);
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Encrypted payload descriptors');
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Encrypted payloads are constructor-controlled.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Encrypted payloads are constructor-controlled.');
    }
}
