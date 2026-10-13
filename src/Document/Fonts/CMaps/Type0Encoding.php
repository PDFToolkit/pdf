<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Encoding selection and explicitly declared decoding metadata, without loading external CMap resources. */
interface Type0Encoding
{
    public function object(): NameType|IndirectReference;
    public function name(): CMapName;
    public function mode(): CMapWritingMode;
    public function codeSpaces(): CodeSpaces;
    public function collection(): ?CMapSystemInfo;
    public function cid(CMapCode $code, bool $notdef = false): ?IntegerType;
}
