<?php

declare(strict_types=1);

namespace PDF\Document\Artifacts;

use PDF\Specification\Definition\PropertyListEntriesForArtifactsTable330Definition;
use PDF\Specification\EntryDefinition;
use PDF\Specification\ObjectTypeConstraint;
use PDF\Specification\Requirement;
use PDF\Specification\SchemaDictionary;
use PDF\Specification\SourceReference;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Bates numbering artifact properties (Adobe extension level 3, table 10.17, p.100). */
final class BatesArtifact extends SchemaDictionary
{
    public const string TABLE = '10.17';

    public function __construct(StringType|ArrayType|null $contents = null, Entry ...$entries)
    {
        $source = new SourceReference(
            '10.17',
            '10.7.1',
            100,
            'Adobe Supplement to ISO 32000, June 2008, ExtensionLevel 3',
            '50d0bbc96028dca1a54cf3c671054536d26e9494dc52877061763b4aed08ac48',
            'adobe_supplement_iso32000.pdf',
        );
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Type', 'Subtype', 'Contents'], true)) {
                throw new \InvalidArgumentException('Bates artifact properties are supplied by the constructor.');
            }
        }
        $definition = new TableDefinition($source, 'Property list entries for artifacts', [
            new EntryDefinition('Type', new ObjectTypeConstraint('name', fixedName: 'Pagination'), new Requirement(null, true, false, false, null), $source, 'Pagination artifact.', false),
            new EntryDefinition('Subtype', new ObjectTypeConstraint('name', fixedName: 'BatesN'), new Requirement(null, true, false, false, null), $source, 'Bates numbering artifact.', false),
            new EntryDefinition('Contents', new ObjectTypeConstraint('text string or array of text strings'), new Requirement('Optional; ExtensionLevel 3', false, false, false, null), $source, 'One Bates number, or an ordered array of Bates numbers.', false),
        ], [new PropertyListEntriesForArtifactsTable330Definition()]);
        $properties = [new Entry(new NameType('Type'), new NameType('Pagination')), new Entry(new NameType('Subtype'), new NameType('BatesN'))];
        if ($contents !== null) {
            $properties[] = new Entry(new NameType('Contents'), $contents);
        }
        parent::__construct($definition, ...[...$properties, ...$entries]);
    }

    public function without(NameType|string $key): static
    {
        if (in_array($key instanceof NameType ? $key->value : $key, ['Type', 'Subtype'], true)) {
            throw new \InvalidArgumentException('Bates artifact Type and Subtype cannot be removed.');
        }

        return parent::without($key);
    }
}
