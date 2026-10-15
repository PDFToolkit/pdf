<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

enum WtPdfDeprecatedScope: string
{
    case ConstructedGraph = 'constructed-graph';
    case SuppliedFileContext = 'supplied-file-context';
    case OpaquePayload = 'opaque-payload';
    case ConsumerOrSemanticReview = 'consumer-or-semantic-review';
    case SourceReviewRequired = 'source-review-required';
}
