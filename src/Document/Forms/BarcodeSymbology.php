<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

enum BarcodeSymbology: string
{
    case Pdf417 = 'PDF417';
    case QrCode = 'QRCode';
    case DataMatrix = 'DataMatrix';
}
