<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** Table 126 binding of explicitly normalized external bytes to their PDF font family. */
interface EmbeddedFontProgram
{
    public function descriptorKey(): string;
    public function supports(FontSubtype $subtype): bool;
}
