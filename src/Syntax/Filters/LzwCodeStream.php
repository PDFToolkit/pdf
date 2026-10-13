<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

/** A local MSB-first code writer; any final unused bits are zero. */
final class LzwCodeStream
{
    private string $bytes = '';
    private int $byte = 0;
    private int $used = 0;

    public function write(LzwCodeWord $word): void
    {
        for ($position = $word->bits->integer() - 1; $position >= 0; --$position) {
            $this->byte = ($this->byte << 1) | (($word->code->integer() >> $position) & 1);
            if (++$this->used === 8) {
                $this->bytes .= chr($this->byte);
                $this->used = 0;
                $this->byte = 0;
            }
        }
    }

    public function bytes(): string
    {
        return $this->bytes . ($this->used === 0 ? '' : chr($this->byte << (8 - $this->used)));
    }
}
