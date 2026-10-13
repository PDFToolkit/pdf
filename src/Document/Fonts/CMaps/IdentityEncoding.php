<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Predefined Identity-H/V, ISO PDF pp.283–285; collection-independent, two-byte big-endian CIDs. */
final readonly class IdentityEncoding implements Type0Encoding
{
    public function __construct(private CMapWritingMode $writingMode = CMapWritingMode::Horizontal)
    {
    }
    public function object(): NameType
    {
        return new NameType($this->writingMode === CMapWritingMode::Horizontal ? 'Identity-H' : 'Identity-V');
    }
    public function name(): CMapName
    {
        return new CMapName($this->object());
    }
    public function mode(): CMapWritingMode
    {
        return $this->writingMode;
    }
    public function codeSpaces(): CodeSpaces
    {
        return new CodeSpaces(new CodeSpaceRange(new CMapCode(new HexadecimalString("\0\0")), new CMapCode(new HexadecimalString("\xff\xff"))));
    }
    public function collection(): ?CMapSystemInfo
    {
        return null;
    }
    public function cid(CMapCode $code, bool $notdef = false): ?IntegerType
    {
        return !$notdef && $code->length() === 2 ? new IntegerType((int) $code->number()) : null;
    }
}
