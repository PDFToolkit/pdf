<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

enum ListMode: string
{
    case AllPages = 'AllPages';
    case VisiblePages = 'VisiblePages';
}
