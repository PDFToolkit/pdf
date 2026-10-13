<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

/** ISO p.454 says display names; table 231 and common reader behavior use export values. */
enum ChoiceValueDomain
{
    case DisplayNames;
    case ExportValues;
}
