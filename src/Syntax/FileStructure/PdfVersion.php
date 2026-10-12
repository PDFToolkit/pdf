<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

enum PdfVersion: string
{
    case PDF10 = '1.0';
    case PDF11 = '1.1';
    case PDF12 = '1.2';
    case PDF13 = '1.3';
    case PDF14 = '1.4';
    case PDF15 = '1.5';
    case PDF16 = '1.6';
    case PDF17 = '1.7';
}
