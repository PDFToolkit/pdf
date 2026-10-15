<?php

declare(strict_types=1);

namespace PDF\Document\Extensions;

use PDF\Specification\Definition\DeveloperExtensionsDictionaryTable50Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** One developer's extension of a PDF base version (ISO 32000-1, table 50). */
final class DeveloperExtension extends SchemaDictionary implements \PDF\Document\VersionedComponent
{
    public const string TABLE = '50';

    public function __construct(NameType $baseVersion, IntegerType $extensionLevel, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            (new ExtensionDirectness())->validate($entry->value);
            if (in_array($entry->key->value, ['BaseVersion', 'ExtensionLevel'], true)) {
                throw new \InvalidArgumentException('BaseVersion and ExtensionLevel are supplied by the constructor.');
            }
        }
        parent::__construct(
            new DeveloperExtensionsDictionaryTable50Definition(),
            new Entry(new NameType('BaseVersion'), $baseVersion),
            new Entry(new NameType('ExtensionLevel'), $extensionLevel),
            ...$entries,
        );
    }

    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        if ($version->value === \PDF\Syntax\FileStructure\PdfVersion::PDF20) {
            (new \PDF\Specification\Constraint\DirectDictionaryObjects())->validate($this);
            if (!$this->get('URL') instanceof \PDF\Syntax\Object\StringType) {
                throw new \InvalidArgumentException('EC3 #732 requires a direct URL string in PDF 2.0 developer extensions.');
            }
        }
    }

    public function without(NameType|string $key): static
    {
        if (in_array($key instanceof NameType ? $key->value : $key, ['BaseVersion', 'ExtensionLevel'], true)) {
            throw new \InvalidArgumentException('An extension declaration requires BaseVersion and ExtensionLevel.');
        }

        return parent::without($key);
    }

    public function with(Entry $entry): static
    {
        (new ExtensionDirectness())->validate($entry->value);

        return parent::with($entry);
    }
}
