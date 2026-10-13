<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IntegerType;

/** Table 126: exactly one normalized CFF font, or a declared OpenType outline program. No parsing. */
final class FontFile3 extends FontStream implements EmbeddedFontProgram
{
    public function __construct(string $bytes, public readonly FontFile3Subtype $subtype, public readonly ?OpenTypeOutline $outline = null, Filter|FontProgramMetadata ...$filters)
    {
        if (($subtype === FontFile3Subtype::OpenType) !== ($outline !== null)) {
            throw new \InvalidArgumentException('Only an OpenType program declares its normalized outline kind.');
        }
        $options = new FontProgramOptions(...$filters);
        $dictionary = new DictionaryType(new Entry(new NameType('Subtype'), new NameType($subtype->value)));
        if ($outline === OpenTypeOutline::Glyf) {
            $dictionary = $dictionary->with(new Entry(new NameType('Length1'), new IntegerType(strlen($bytes))));
        }
        parent::__construct($bytes, $options->dictionary($dictionary), ...$options->filters);
    }

    public function descriptorKey(): string
    {
        return 'FontFile3';
    }
    public function supports(FontSubtype $subtype): bool
    {
        return match ($this->subtype) {
            FontFile3Subtype::Type1C => in_array($subtype, [FontSubtype::Type1, FontSubtype::MMType1], true),
            FontFile3Subtype::CIDFontType0C => $subtype === FontSubtype::CIDFontType0,
            FontFile3Subtype::OpenType => match ($this->outline) {
                OpenTypeOutline::Glyf => in_array($subtype, [FontSubtype::TrueType, FontSubtype::CIDFontType2], true),
                OpenTypeOutline::CffSimple => in_array($subtype, [FontSubtype::Type1, FontSubtype::CIDFontType0], true),
                OpenTypeOutline::CffCid => $subtype === FontSubtype::CIDFontType0,
                null => false,
            },
        };
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        parent::validateVersion($version);
        $version->require(match ($this->subtype) {
            FontFile3Subtype::Type1C => PdfVersion::PDF12,
            FontFile3Subtype::CIDFontType0C => PdfVersion::PDF13,
            FontFile3Subtype::OpenType => PdfVersion::PDF16,
        }, 'FontFile3 ' . $this->subtype->value);
    }
}
