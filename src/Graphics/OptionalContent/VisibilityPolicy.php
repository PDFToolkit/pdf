<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

enum VisibilityPolicy: string
{
    case AllOn = 'AllOn';
    case AnyOn = 'AnyOn';
    case AnyOff = 'AnyOff';
    case AllOff = 'AllOff';
}
