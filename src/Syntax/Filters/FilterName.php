<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

/** All stream filter names in 7.4. This enum does not claim to implement their codecs. */
enum FilterName: string
{
    case ASCIIHexDecode = 'ASCIIHexDecode';
    case ASCII85Decode = 'ASCII85Decode';
    case LZWDecode = 'LZWDecode';
    case FlateDecode = 'FlateDecode';
    case RunLengthDecode = 'RunLengthDecode';
    case CCITTFaxDecode = 'CCITTFaxDecode';
    case JBIG2Decode = 'JBIG2Decode';
    case DCTDecode = 'DCTDecode';
    case JPXDecode = 'JPXDecode';
    case Crypt = 'Crypt';
}
