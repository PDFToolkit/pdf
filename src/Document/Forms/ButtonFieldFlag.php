<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

/** ISO table 226, PDF p.447. */
enum ButtonFieldFlag: int
{
    case NoToggleToOff = 16384;
    case Radio = 32768;
    case Pushbutton = 65536;
    case RadiosInUnison = 33554432;
}
