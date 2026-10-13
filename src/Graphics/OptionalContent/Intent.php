<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

enum Intent: string
{
    case View = 'View';
    case Design = 'Design';
    case All = 'All';
}
