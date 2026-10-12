<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** The trailer's optional Info dictionary (ISO 32000-1, table 317). */
final class DocumentInformation extends DictionaryType
{
    public function __construct(Entry ...$entries)
    {
        foreach ($entries as $entry) {
            self::validate($entry);
        }
        parent::__construct(...$entries);
    }

    public function with(Entry $entry): static
    {
        self::validate($entry);

        return parent::with($entry);
    }

    private static function validate(Entry $entry): void
    {
        $key = $entry->key()->value;
        $value = $entry->value();
        if (in_array($key, ['Title', 'Author', 'Subject', 'Keywords', 'Creator', 'Producer'], true) && !$value instanceof TextString) {
            throw new \InvalidArgumentException('Document information text entries require TextString values.');
        }
        if (in_array($key, ['CreationDate', 'ModDate'], true) && !$value instanceof Date) {
            throw new \InvalidArgumentException('Document information date entries require Date values.');
        }
        if ($key === 'Trapped' && (!$value instanceof NameType || !in_array($value->value, ['True', 'False', 'Unknown'], true))) {
            throw new \InvalidArgumentException('Trapped must be /True, /False or /Unknown.');
        }
    }
}
