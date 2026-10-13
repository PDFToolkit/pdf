<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Specification\Definition\EmbeddedFileParameterDictionaryTable46Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Table46, page112: Size and MD5 are derived from the original, uncompressed bytes. */
final class EmbeddedFileParameters extends SchemaDictionary
{
    public const string TABLE = '46';

    public function __construct(string $uncompressedBytes, ?Date $creationDate = null, ?Date $modificationDate = null, ?MacFileInformation $mac = null)
    {
        $entries = [new Entry(new NameType('Size'), new IntegerType(strlen($uncompressedBytes))), new Entry(new NameType('CheckSum'), new HexadecimalString(md5($uncompressedBytes, true)))];
        foreach (['CreationDate' => $creationDate, 'ModDate' => $modificationDate, 'Mac' => $mac] as $key => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($key), $value);
            }
        }
        parent::__construct(new EmbeddedFileParameterDictionaryTable46Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Embedded-file parameters are derived from the explicitly supplied bytes and facts.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Embedded-file parameters are constructor-controlled.');
    }
}
