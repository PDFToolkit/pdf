<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;

interface CidMapping
{
    public function source(): CMapCodeRange;
    public function operator(): string;
    public function line(): string;
    public function notdef(): bool;
    public function cid(CMapCode $code): ?IntegerType;
}
