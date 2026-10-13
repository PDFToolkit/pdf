<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

enum FieldType: string
{
    case Button = 'Btn';
    case Text = 'Tx';
    case Choice = 'Ch';
    case Signature = 'Sig';
}
