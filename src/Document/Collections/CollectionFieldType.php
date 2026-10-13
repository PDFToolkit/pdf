<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

enum CollectionFieldType: string
{
    case Text = 'S';
    case Date = 'D';
    case Number = 'N';
    case FileName = 'F';
    case Description = 'Desc';
    case ModificationDate = 'ModDate';
    case CreationDate = 'CreationDate';
    case FileSize = 'Size';
}
