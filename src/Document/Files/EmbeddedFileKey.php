<?php

declare(strict_types=1);

namespace PDF\Document\Files;

enum EmbeddedFileKey: string
{
    case File = 'F';
    case UnicodeFile = 'UF';
    case DOS = 'DOS';
    case Mac = 'Mac';
    case Unix = 'Unix';
}
