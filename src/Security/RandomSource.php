<?php

declare(strict_types=1);

namespace PDF\Security;

/** Randomness is supplied explicitly by the document's application. */
interface RandomSource
{
    public function bytes(int $length): string;
}
