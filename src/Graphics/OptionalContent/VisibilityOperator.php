<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

enum VisibilityOperator: string
{
    case And = 'And';
    case Or = 'Or';
    case Not = 'Not';
}
