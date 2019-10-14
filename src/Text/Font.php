<?php

declare(strict_types=1);

namespace PDF\Text;

class Font
{
    /** https://ddd.uab.cat/pub/butgroc/butgrocCAT/butgroc_a2004m5-6v17n3.info */
    public const CFCJIL_HELVETICA_LIGHT = 'F9';
    public const HELVETICA_BOLD = 'F11';
    public const SYMBOL = 'F12';
    public const HELVETICA = 'F13';
    public const CFJJHF_GADGET_REGULAR = 'F14';

    /** @var string */
    private string $font;

    /** @var int */
    private int $size;

    public function __construct(string $font, int $size)
    {
        $this->font = $font;
        $this->size = $size;
    }

    public function __toString(): string
    {
        return sprintf('/%s %s', $this->font, $this->size);
    }
}
