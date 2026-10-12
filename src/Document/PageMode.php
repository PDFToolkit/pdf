<?php

declare(strict_types=1);

namespace PDF\Document;

enum PageMode: string
{
    case UseNone = 'UseNone';
    case UseOutlines = 'UseOutlines';
    case UseThumbs = 'UseThumbs';
    case FullScreen = 'FullScreen';
    case UseOC = 'UseOC';
    case UseAttachments = 'UseAttachments';
}
