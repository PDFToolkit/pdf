<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Graphics\GraphicsState\RenderingIntent;
use PDF\Syntax\Object\NameType;

/** ri — ISO 32000-1, 8.4.4. */
final readonly class SetRenderingIntent extends Operator
{
    public const string SYMBOL = "ri";

    public function __construct(public RenderingIntent $intent)
    {
        parent::__construct(new NameType($intent->value));
    }
}
