<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

/** JPX SMaskInData declarations; payload consistency belongs to the codec supplier. */
enum EmbeddedImageAlpha: int
{
    case Ignore = 0;
    case Opacity = 1;
    case Preblended = 2;
}
