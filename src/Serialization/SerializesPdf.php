<?php

declare(strict_types=1);

namespace PDF\Serialization;

trait SerializesPdf
{
    public function __toString(): string
    {
        return $this->toPdf(new SerializationContext());
    }
}
