<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

enum UsageCategory: string
{
    case CreatorInfo = 'CreatorInfo';
    case Language = 'Language';
    case Export = 'Export';
    case Zoom = 'Zoom';
    case Print = 'Print';
    case View = 'View';
    case User = 'User';
    case PageElement = 'PageElement';
}
