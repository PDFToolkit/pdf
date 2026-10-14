<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

/** Supported output declarations (ISO_32000-2_sponsored_EC3.pdf, 7.5.2, PDF pp. 69–70). */
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
    case PDF20 = '2.0';
}
