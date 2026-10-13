<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

enum IconScaleWhen: string
{
    case Always = 'A';
    case Bigger = 'B';
    case Smaller = 'S';
    case Never = 'N';
}
