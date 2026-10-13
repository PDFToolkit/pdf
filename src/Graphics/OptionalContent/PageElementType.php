<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

enum PageElementType: string
{
    case HeaderFooter = 'HF';
    case Foreground = 'FG';
    case Background = 'BG';
    case Logo = 'L';
}
