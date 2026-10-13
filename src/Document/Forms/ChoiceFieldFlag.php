<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

/** ISO table 230, PDF pp.452–453. */
enum ChoiceFieldFlag: int
{
    case Combo = 131072;
    case Edit = 262144;
    case Sort = 524288;
    case MultiSelect = 2097152;
    case DoNotSpellCheck = 4194304;
    case CommitOnSelectionChange = 67108864;
}
