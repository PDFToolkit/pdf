<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

interface UnicodeCMapResource
{
    public function object(): NameType|IndirectReference;
    public function name(): CMapName;
    public function codeSpaces(): CodeSpaces;
    public function sequence(CMapCode $code): ?UnicodeSequence;
}
