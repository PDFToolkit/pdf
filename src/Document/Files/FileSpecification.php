<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\DocumentGraphComponent;
use PDF\Document\DocumentGraphContext;
use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\FileSpecificationDictionaryTable44Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Table44/pages110–111: explicit file declaration, without path/URL loading. */
final class FileSpecification extends SchemaDictionary implements DocumentGraphComponent, VersionedComponent
{
    public const string TABLE = '44';

    public function __construct(public readonly StringType $fileName, FileSpecificationOptions $options = new FileSpecificationOptions(), public readonly ?AssociatedFileRelationship $relationship = null)
    {
        $entries = [new Entry(new NameType('Type'), new NameType('Filespec')), new Entry(new NameType('F'), $fileName), ...iterator_to_array($options->entries(), false)];
        if ($relationship !== null) {
            $entries[] = new Entry(new NameType('AFRelationship'), $relationship->name);
        }
        parent::__construct(new FileSpecificationDictionaryTable44Definition(), ...$entries);
        (new FileSpecificationRules())->validate($this);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF11, 'File specifications');
        $ef = $this->get('EF');
        if ($ef instanceof \PDF\Syntax\Object\DictionaryType && $ef->has('UF')) {
            $version->require(PdfVersion::PDF17, 'Unicode embedded-file binding');
        }
    }

    public function validateGraph(DocumentGraphContext $graph): void
    {
        (new FileSpecificationRules())->validate($graph->dictionary($this) ?? throw new \LogicException('Missing effective file specification.'), $graph);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('File specifications require their explicit filename and constructor options.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('File-specification filename relationships are constructor-controlled.');
    }
}
