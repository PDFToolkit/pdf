<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Specification\Definition\MacOSFileInformationDictionaryTable47Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** The normalized Mac-specific facts, without accessing a platform resource fork. */
final class MacFileInformation extends SchemaDictionary
{
    public const string TABLE = '47';

    public function __construct(?MacFileCode $subtype = null, ?MacFileCode $creator = null, ?StreamObject $resourceFork = null)
    {
        $entries = [];
        foreach (['Subtype' => $subtype?->number, 'Creator' => $creator?->number, 'ResFork' => $resourceFork === null ? null : new IndirectReference($resourceFork)] as $key => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($key), $value);
            }
        }
        parent::__construct(new MacOSFileInformationDictionaryTable47Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Mac file information requires explicit four-byte codes and a resource-fork stream.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Mac file information is constructor-controlled.');
    }
}
