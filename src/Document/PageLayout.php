<?php

declare(strict_types=1);

namespace PDF\Document;

enum PageLayout: string
{
    case SinglePage = 'SinglePage';
    case OneColumn = 'OneColumn';
    case TwoColumnLeft = 'TwoColumnLeft';
    case TwoColumnRight = 'TwoColumnRight';
    case TwoPageLeft = 'TwoPageLeft';
    case TwoPageRight = 'TwoPageRight';
}
