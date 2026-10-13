<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

enum UserType: string
{
    case Individual = 'Ind';
    case Title = 'Ttl';
    case Organization = 'Org';
}
