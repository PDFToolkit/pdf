<?php

declare(strict_types=1);

namespace PDF\Document\Extensions;

use PDF\Syntax\Object\DictionaryObject\Entry;

/** An explicit direct prefix-to-extension association (ISO 32000-1, 7.12.1). */
final class DeveloperExtensionEntry extends Entry
{
    public function __construct(DeveloperPrefix $prefix, DeveloperExtension $extension)
    {
        (new ExtensionDirectness())->validate($extension);
        parent::__construct($prefix->name, $extension);
    }
}
