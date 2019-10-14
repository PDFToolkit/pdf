<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\IndirectObject;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1638996
 */
class GenerationNumber
{
    /** @var int */
    private $number;

    public function __construct(int $number = 0)
    {
        $this->number = $number;
    }

    public function increment(): self
    {
        return new self(++$this->number);
    }

    public function __toString(): string
    {
        return (string) $this->number;
    }
}
