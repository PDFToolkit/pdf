<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Specification\EntryDefinition;
use PDF\Specification\ObjectTypeConstraint;
use PDF\Specification\Requirement;
use PDF\Specification\SourceReference;
use PDF\Specification\TableDefinition;
use PDF\Specification\Iso32000_2\Definition\CatalogDictionaryTable29Definition;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** ISO 32000-2 12.8.5, physical PDF pages 605-607; EC3 #448 Completed. */
final class PendingDocumentTimestamp extends PendingSignatureDictionary
{
    public function __construct(SignatureCapacity $capacity, NameType $handler = new NameType('Adobe.PPKLite'), Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Reference', 'Changes'], true)) {
                throw new \InvalidArgumentException('Document timestamps cannot carry signature reference transforms or change counts (ISO 32000-2 Table 255).');
            }
        }
        $edition = (new CatalogDictionaryTable29Definition())->source;
        $source = new SourceReference('255', '12.8.5', 605, $edition->standard, $edition->documentSha256, $edition->document, $edition->edition);
        $definitions = [];
        foreach (['Type' => ['name', 'DocTimeStamp'], 'Filter' => ['name', null], 'SubFilter' => ['name', 'ETSI.RFC3161'], 'Contents' => ['byte string', null], 'ByteRange' => ['array', null]] as $name => [$type, $fixed]) {
            $definitions[] = new EntryDefinition($name, new ObjectTypeConstraint($type, fixedName: $fixed), new Requirement('Required', true, false, false, '2.0'), $source, 'Required document timestamp ' . $name . '.');
        }
        parent::__construct($capacity, SignatureFormat::Rfc3161, $handler, 'DocTimeStamp', new TableDefinition($source, 'Document timestamp dictionary', $definitions), ...$entries);
    }
}
