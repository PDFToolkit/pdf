<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

/** Table 269 PDU, physical page 618. */
enum LinearDisplayUnit: string
{
    case Metre = 'M';
    case Kilometre = 'KM';
    case InternationalFoot = 'FT';
    case UsSurveyFoot = 'USFT';
    case InternationalMile = 'MI';
    case InternationalNauticalMile = 'NM';
}
