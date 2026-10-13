<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

/** ISO table 228, PDF pp.451–452. */
enum TextFieldFlag: int
{
    case Multiline = 4096;
    case Password = 8192;
    case FileSelect = 1048576;
    case DoNotSpellCheck = 4194304;
    case DoNotScroll = 8388608;
    case Comb = 16777216;
    case RichText = 33554432;
}
