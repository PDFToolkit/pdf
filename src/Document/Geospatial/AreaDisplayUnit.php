<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

/** SQFT uses US Survey feet, unlike the FT international linear unit. */
enum AreaDisplayUnit: string
{
    case SquareMetre = 'SQM';
    case Hectare = 'HA';
    case SquareKilometre = 'SQKM';
    case SquareUsSurveyFoot = 'SQFT';
    case Acre = 'A';
    case SquareInternationalMile = 'SQMI';
}
