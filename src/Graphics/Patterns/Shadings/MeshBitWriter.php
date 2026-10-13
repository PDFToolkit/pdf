<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

/** Content-scoped MSB-first binary writer, with explicit record alignment and resource limit. */
final class MeshBitWriter
{
    private string $written = '';
    private int $pending = 0;
    private int $used = 0;

    public function __construct(private readonly int $maximumBytes = 67108864)
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('A mesh binary output limit must be positive.');
        }
    }

    public function write(int $value, int $width): void
    {
        if ($width < 1 || $width > 32 || ($width === 32 && PHP_INT_SIZE < 8) || $value < 0 || $value > (1 << $width) - 1) {
            throw new \InvalidArgumentException('An unsigned mesh value must fit its declared bit width.');
        }
        while ($width > 0) {
            $take = min(8 - $this->used, $width);
            $this->pending |= (($value >> ($width - $take)) & ((1 << $take) - 1)) << (8 - $this->used - $take);
            $this->used += $take;
            $width -= $take;
            if ($this->used === 8) {
                $this->align();
            }
        }
    }

    public function align(): void
    {
        if ($this->used !== 0) {
            if (strlen($this->written) >= $this->maximumBytes) {
                throw new \LengthException('Mesh encoding exceeds its explicit binary output limit.');
            }
            $this->written .= chr($this->pending);
            $this->pending = 0;
            $this->used = 0;
        }
    }

    public function bytes(): string
    {
        if ($this->used !== 0 && strlen($this->written) >= $this->maximumBytes) {
            throw new \LengthException('Mesh encoding exceeds its explicit binary output limit.');
        }
        return $this->written . ($this->used === 0 ? '' : chr($this->pending));
    }
}
