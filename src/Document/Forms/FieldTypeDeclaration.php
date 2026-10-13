<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

enum FieldTypeDeclaration
{
    case Explicit;
    case Inherited;
}
