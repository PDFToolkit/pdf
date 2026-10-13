<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Syntax\Object\IntegerType;

/** Every permitted coordinate/component/flag width from tables 82–84, PDF pages 197/201/204. */
final readonly class MeshBitDepths
{
    public function __construct(public IntegerType $coordinate = new IntegerType(16), public IntegerType $component = new IntegerType(8), public IntegerType $flag = new IntegerType(8))
    {
        if (!in_array($coordinate->integer(), [1, 2, 4, 8, 12, 16, 24, 32], true) || !in_array($component->integer(), [1, 2, 4, 8, 12, 16], true) || !in_array($flag->integer(), [2, 4, 8], true)) {
            throw new \InvalidArgumentException('Invalid PDF mesh bit depth.');
        }
    }
}
