<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\ByteString;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** 9.9, Table 127: three already normalized Type 1 segments, binary encrypted portion. */
final class FontFile extends FontStream implements EmbeddedFontProgram
{
    public function __construct(AsciiString $clearText, ByteString $encryptedBinary, ?ByteString $fixedContent = null, Filter|FontProgramMetadata ...$filters)
    {
        if ($fixedContent !== null && preg_match('/^[\x09\x0a\x0d\x20]*(?:0[\x09\x0a\x0d\x20]*){512}cleartomark(?:\s|$)/D', $fixedContent->value) !== 1) {
            throw new \InvalidArgumentException('A Type 1 fixed segment contains 512 ASCII zeros followed by cleartomark.');
        }
        $options = new FontProgramOptions(...$filters);
        parent::__construct($clearText->value . $encryptedBinary->value . ($fixedContent->value ?? ''), $options->dictionary(new DictionaryType(
            new Entry(new NameType('Length1'), new IntegerType(strlen($clearText->value))),
            new Entry(new NameType('Length2'), new IntegerType(strlen($encryptedBinary->value))),
            new Entry(new NameType('Length3'), new IntegerType(strlen($fixedContent->value ?? ''))),
        )), ...$options->filters);
    }

    public function descriptorKey(): string
    {
        return 'FontFile';
    }
    public function supports(FontSubtype $subtype): bool
    {
        return in_array($subtype, [FontSubtype::Type1, FontSubtype::MMType1], true);
    }
}
