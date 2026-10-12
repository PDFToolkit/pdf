<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** The standard fourteen Type 1 fonts (ISO 32000-1, 9.6.2.2). */
enum StandardFont: string
{
    case TimesRoman = 'Times-Roman';
    case TimesBold = 'Times-Bold';
    case TimesItalic = 'Times-Italic';
    case TimesBoldItalic = 'Times-BoldItalic';
    case Helvetica = 'Helvetica';
    case HelveticaBold = 'Helvetica-Bold';
    case HelveticaOblique = 'Helvetica-Oblique';
    case HelveticaBoldOblique = 'Helvetica-BoldOblique';
    case Courier = 'Courier';
    case CourierBold = 'Courier-Bold';
    case CourierOblique = 'Courier-Oblique';
    case CourierBoldOblique = 'Courier-BoldOblique';
    case Symbol = 'Symbol';
    case ZapfDingbats = 'ZapfDingbats';
}
