<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Document\VersionedComponent;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Namespace dictionary, ISO_32000-2_sponsored_EC3.pdf, table 356, physical page 744. */
final class StructureNamespace extends DictionaryType implements VersionedComponent
{
    public readonly string $identifier;

    public function __construct(string|StandardStructureNamespace $identifier, public readonly NamespaceRoleMap $roleMap = new NamespaceRoleMap(), ?BasicType $schema = null)
    {
        $this->identifier = $identifier instanceof StandardStructureNamespace ? $identifier->value : $identifier;
        if ($this->identifier === '' || str_contains($this->identifier, "\0")) {
            throw new \InvalidArgumentException('A structure namespace requires a nonempty text identifier.');
        }
        $entries = [new Entry(new NameType('Type'), new NameType('Namespace')), new Entry(new NameType('NS'), new TextString($this->identifier))];
        if (count($roleMap) > 0) {
            $entries[] = new Entry(new NameType('RoleMapNS'), $roleMap);
        }
        if ($schema !== null) {
            (new \PDF\Specification\Iso32000_2\Definition\NamespaceDictionaryTable356Definition())->entry('Schema')->type->validate($schema);
            $entries[] = new Entry(new NameType('Schema'), $schema);
        }
        parent::__construct(...$entries);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Structure namespaces');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Build a new explicit structure namespace.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Build a new explicit structure namespace.');
    }
}
