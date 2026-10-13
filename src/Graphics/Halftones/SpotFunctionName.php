<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

/** Predefined spot functions from Table128; the reader supplies their screening algorithm. */
enum SpotFunctionName: string
{
    case SimpleDot = 'SimpleDot';
    case InvertedSimpleDot = 'InvertedSimpleDot';
    case DoubleDot = 'DoubleDot';
    case InvertedDoubleDot = 'InvertedDoubleDot';
    case CosineDot = 'CosineDot';
    case Double = 'Double';
    case InvertedDouble = 'InvertedDouble';
    case Line = 'Line';
    case LineX = 'LineX';
    case LineY = 'LineY';
    case Round = 'Round';
    case Ellipse = 'Ellipse';
    case EllipseA = 'EllipseA';
    case InvertedEllipseA = 'InvertedEllipseA';
    case EllipseB = 'EllipseB';
    case EllipseC = 'EllipseC';
    case InvertedEllipseC = 'InvertedEllipseC';
    case Square = 'Square';
    case Cross = 'Cross';
    case Rhomboid = 'Rhomboid';
    case Diamond = 'Diamond';
}
