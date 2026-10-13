<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

/** Document-local binary builder; item vectors are padded to byte boundaries. */
final class HintBitStream
{
    private string $bytes = '';
    private int $byte = 0;
    private int $used = 0;

    public function write(HintField ...$fields): self
    {
        foreach ($fields as $field) {
            for ($bit = $field->bits->integer() - 1; $bit >= 0; --$bit) {
                $this->byte = ($this->byte << 1) | (($field->value->integer() >> $bit) & 1);
                if (++$this->used === 8) {
                    $this->bytes .= chr($this->byte);
                    $this->byte = 0;
                    $this->used = 0;
                }
            }
        }
        return $this;
    }

    public function align(): self
    {
        if ($this->used !== 0) {
            $this->bytes .= chr($this->byte << (8 - $this->used));
            $this->byte = 0;
            $this->used = 0;
        }
        return $this;
    }

    public function bytes(): string
    {
        return $this->bytes . ($this->used === 0 ? '' : chr($this->byte << (8 - $this->used)));
    }
}
