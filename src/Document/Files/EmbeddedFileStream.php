<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\VersionedComponent;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Table45/page112: attachment bytes supplied by the caller, then filtered and encrypted by the writer. */
final class EmbeddedFileStream extends StreamObject implements VersionedComponent
{
    public readonly EmbeddedFileParameters $parameters;

    public function __construct(string $bytes, ?NameType $subtype = null, ?Date $creationDate = null, ?Date $modificationDate = null, ?MacFileInformation $mac = null, Filter ...$filters)
    {
        foreach ($filters as $filter) {
            if ($filter instanceof EncodedData) {
                throw new \InvalidArgumentException('Embedded-file metadata requires the uncompressed bytes; a pre-encoded payload cannot prove its Size or CheckSum.');
            }
        }
        $this->parameters = new EmbeddedFileParameters($bytes, $creationDate, $modificationDate, $mac);
        $entries = [new Entry(new NameType('Type'), new NameType('EmbeddedFile')), new Entry(new NameType('Params'), $this->parameters)];
        if ($subtype !== null) {
            $entries[] = new Entry(new NameType('Subtype'), $subtype);
        }
        parent::__construct($bytes, new DictionaryType(...$entries), ...$filters);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF13, 'Embedded files');
        $subtype = $this->dictionary()->get('Subtype');
        if ($version->value === PdfVersion::PDF20 && $subtype instanceof NameType) {
            new EmbeddedFileMediaType($subtype);
        }
    }
}
