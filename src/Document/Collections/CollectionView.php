<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

enum CollectionView: string
{
    case Details = 'D';
    case Tiles = 'T';
    case Hidden = 'H';
}
