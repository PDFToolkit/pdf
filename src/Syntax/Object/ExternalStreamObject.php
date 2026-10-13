<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Document\Files\FileSpecification;
use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\ExternalStreamFilters;
use PDF\Syntax\Object\DictionaryObject\Entry;

/** Table5/page28 and7.6.1/page63: external payload declaration, local ignored bytes, no loading. */
final class ExternalStreamObject extends StreamObject implements VersionedComponent
{
    public function __construct(public readonly StringType|FileSpecification|IndirectReference $file, public readonly ExternalStreamFilters $externalFilters = new ExternalStreamFilters(), string $ignoredBytes = '', ?DictionaryType $dictionary = null)
    {
        if ($file instanceof IndirectReference && !$file->target() instanceof StringType && !$file->target() instanceof DictionaryType) {
            throw new \InvalidArgumentException('An external stream F reference must target a file specification.');
        }
        (new \PDF\Document\Files\ExternalStreamsGraphValidation())->validateFile($file instanceof IndirectReference ? $file->target() : $file);
        parent::__construct($ignoredBytes, $dictionary);
    }

    public function dictionary(): DictionaryType
    {
        $dictionary = parent::dictionary()->with(new Entry(new NameType('F'), $this->file));
        foreach ($this->externalFilters->entries() as $entry) {
            $dictionary = $dictionary->with($entry);
        }
        return $dictionary;
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF12, 'External stream files and decoding filters');
    }
}
