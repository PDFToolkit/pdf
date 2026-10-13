<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** 9.9: a supplied TrueType program with its uncompressed Length1; no sfnt parsing. */
final class FontFile2 extends FontStream implements EmbeddedFontProgram
{
    public function __construct(string $bytes, Filter|FontProgramMetadata ...$filters)
    {
        $options = new FontProgramOptions(...$filters);
        parent::__construct($bytes, $options->dictionary(new DictionaryType(new Entry(new NameType('Length1'), new IntegerType(strlen($bytes))))), ...$options->filters);
    }

    public function descriptorKey(): string
    {
        return 'FontFile2';
    }
    public function supports(FontSubtype $subtype): bool
    {
        return in_array($subtype, [FontSubtype::TrueType, FontSubtype::CIDFontType2], true);
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        parent::validateVersion($version);
        $version->require(PdfVersion::PDF11, 'TrueType FontFile2 embedding');
    }
}
