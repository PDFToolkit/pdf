<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\VersionedComponent;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Document-level attachments, name dictionary/Table31 and7.11.4.1/page112. */
final readonly class EmbeddedFiles implements VersionedComponent
{
    public NameTree $tree;

    public function __construct(EmbeddedFileAttachment ...$attachments)
    {
        $this->tree = new NameTree(...array_map(static fn (EmbeddedFileAttachment $attachment): NameTreeEntry => new NameTreeEntry($attachment->key, new IndirectReference($attachment->file)), $attachments));
    }

    public function nameDictionaryEntry(): Entry
    {
        return new Entry(new NameType('EmbeddedFiles'), new IndirectReference($this->tree));
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF14, 'Document-level EmbeddedFiles name tree');
    }

}
