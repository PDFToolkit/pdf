<?php

declare(strict_types=1);

use PDF\Document\Fonts\Encodings\NamedFontEncoding;
use PDF\Document\Fonts\Encodings\PredefinedEncoding;
use PDF\Document\Fonts\FontDescriptor;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\SimpleFontMetrics;
use PDF\Document\Fonts\SimpleWidths;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

// PDF2 Table109 no longer permits omission of standard-font metrics. This
// example explicitly supplies Courier's Adobe AFM values for ASCII 32–126.
// Source: Adobe Courier.afm (1997), original primary data mirrored at
// https://sources.debian.org/src/gle-graphics/4.2.2-4/src/fonts/Adobe-Core35_AFMs-314/Courier.afm/
// Every glyph in that ASCII range has width600 in 1000-unit glyph space.
// No font program is loaded or embedded; use an explicit embedded font when
// the document's profile or portable appearance requires one (e.g. WTPDF).
$widths = new SimpleWidths(new IntegerType(32), ...array_fill(0, 95, new IntegerType(600)));
$descriptor = new FontDescriptor(
    null,
    new NameType('Courier'),
    new FontDescriptorFlags(1 | 32), // FixedPitch and Nonsymbolic.
    new Rectangle(new IntegerType(-23), new IntegerType(-250), new IntegerType(715), new IntegerType(805)),
    new NumericType(0),
    new NumericType(629),
    new NumericType(-157),
    new NumericType(562),
    new NumericType(51),
);

return new Type1Font(StandardFont::Courier, new SimpleFontMetrics($widths, $descriptor), new NamedFontEncoding(PredefinedEncoding::WinAnsi));
