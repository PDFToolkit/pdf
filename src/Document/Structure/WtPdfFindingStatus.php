<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

enum WtPdfFindingStatus
{
    case Failed;
    case Recommendation;
    case HumanReviewRequired;
    case AdditionalValidationRequired;
}
