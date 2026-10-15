<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

/** Explicit construction rules, distinct from the document's effective version. */
enum ChoiceIndexSemantics
{
    case Pdf17;
    case Pdf20Ec3;
}
