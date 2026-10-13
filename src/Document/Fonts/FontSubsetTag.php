<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** 9.6.4: an explicitly chosen six-uppercase-letter subset identifier. */
final readonly class FontSubsetTag
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[A-Z]{6}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('A font subset tag consists of six uppercase ASCII letters.');
        }
    }
}
