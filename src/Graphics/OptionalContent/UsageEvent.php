<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

enum UsageEvent: string
{
    case View = 'View';
    case Print = 'Print';
    case Export = 'Export';
}
