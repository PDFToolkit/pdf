<?php

declare(strict_types=1);

namespace PDF\Specification;

/** Review annotation states described on physical page 2 of the supplied EC3 source. */
enum ErrataReviewStatus: string
{
    case Completed = 'Completed';
    case Accepted = 'Accepted';

    public function approvalAuthority(): string
    {
        return match ($this) {
            self::Completed => 'ISO TC 171 SC 2 WG 8',
            self::Accepted => 'PDF Association PDF Technical Working Group',
        };
    }
}
