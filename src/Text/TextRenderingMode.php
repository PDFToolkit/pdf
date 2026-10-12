<?php

declare(strict_types=1);

namespace PDF\Text;

/** ISO 32000-1, Table 106. */
enum TextRenderingMode: int
{
    case Fill = 0;
    case Stroke = 1;
    case FillAndStroke = 2;
    case Invisible = 3;
    case FillAndClip = 4;
    case StrokeAndClip = 5;
    case FillStrokeAndClip = 6;
    case Clip = 7;
}
