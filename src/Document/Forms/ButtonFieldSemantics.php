<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

/** Explicit normative edition: ISO2 EC3 #170/#386 relax historical button constraints. */
enum ButtonFieldSemantics
{
    case Pdf17;
    case Pdf20;
}
