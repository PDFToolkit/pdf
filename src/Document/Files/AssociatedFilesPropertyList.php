<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\VersionedComponent;
use PDF\Specification\Iso32000_2\Definition\AssociatedFilesPropertyListTable409aDefinition;
use PDF\Specification\Iso32000_2\Dictionary\AssociatedFilesPropertyListTable409a\MCAFEntry;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Named MCAF property list (ISO_32000-2_sponsored_EC3.pdf, 14.13.5/EC3 issue374, PDF p.1015). */
final class AssociatedFilesPropertyList extends SchemaDictionary implements VersionedComponent
{
    public const string TABLE = '409a';

    public function __construct(public readonly AssociatedFiles $files, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if ($entry->key->value === 'MCAF') {
                throw new \InvalidArgumentException('MCAF is supplied by the explicit associated-files constructor.');
            }
        }
        parent::__construct(new AssociatedFilesPropertyListTable409aDefinition(), new MCAFEntry($files), ...$entries);
    }

    public function with(Entry $entry): static
    {
        if ($entry->key->value === 'MCAF') {
            throw new \InvalidArgumentException('Construct a new associated-files property list to replace MCAF.');
        }
        return parent::with($entry);
    }

    public function without(NameType|string $key): static
    {
        if (($key instanceof NameType ? $key->value : $key) === 'MCAF') {
            throw new \InvalidArgumentException('An associated-files property list requires its MCAF entry.');
        }
        return parent::without($key);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Associated-file marked-content property lists');
    }
}
