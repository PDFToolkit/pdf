<?php

declare(strict_types=1);

namespace PDF\Document\Parts;

use PDF\Document\DocumentGraphComponent;
use PDF\Document\DocumentGraphContext;
use PDF\Document\Metadata\MetadataEntry;
use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\DataStructure\XmlName;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Table408/physical p.851: explicit root, per-level XML names and optional record level. */
final class DocumentPartRoot extends DictionaryType implements DocumentGraphComponent, VersionedComponent
{
    public function __construct(public readonly DocumentPart $root, ?IntegerType $recordLevel = null, NameType|MetadataEntry ...$entries)
    {
        if ($recordLevel !== null && $recordLevel->integer() < 0) {
            throw new \InvalidArgumentException('A document-part record level must be nonnegative.');
        }
        $nodeNames = [];
        $metadata = null;
        foreach ($entries as $entry) {
            if ($entry instanceof MetadataEntry) {
                if ($metadata !== null) {
                    throw new \InvalidArgumentException('A document-part root has at most one explicit Metadata stream.');
                }
                $metadata = $entry;
            } else {
                new XmlName($entry->value);
                $nodeNames[] = $entry;
            }
        }
        $entries = [new Entry(new NameType('Type'), new NameType('DPartRoot')), new Entry(new NameType('DPartRootNode'), new IndirectReference($root))];
        if ($recordLevel !== null) {
            $entries[] = new Entry(new NameType('RecordLevel'), $recordLevel);
        }
        if ($nodeNames !== []) {
            $entries[] = new Entry(new NameType('NodeNameList'), new ArrayType(...$nodeNames));
        }
        if ($metadata !== null) {
            $entries[] = $metadata;
        }
        $root->attachTo($this);
        parent::__construct(...$entries);
    }
    public function catalogEntry(): Entry
    {
        return new Entry(new NameType('DPartRoot'), new IndirectReference($this));
    }
    public function validateGraph(DocumentGraphContext $graph): void
    {
        (new DocumentPartsGraphValidation())->validate($graph);
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Document parts');
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Document-part roots are constructor-controlled.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Document-part roots are constructor-controlled.');
    }
}
