<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

interface UnicodeMapping
{
    public function source(): CMapCodeRange;
    public function operator(): string;
    public function line(): string;
    public function sequence(CMapCode $code): ?UnicodeSequence;
}
