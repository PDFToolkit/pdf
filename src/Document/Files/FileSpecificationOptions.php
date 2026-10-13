<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\Collections\CollectionItem;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final readonly class FileSpecificationOptions
{
    /** @var list<FileSpecificationName> */
    public array $additionalNames;

    public function __construct(
        public ?EmbeddedFileBindings $embedded = null,
        public ?RelatedFileBindings $related = null,
        public ?TextString $description = null,
        public ?FileSpecificationIdentifiers $identifiers = null,
        public ?BooleanType $volatile = null,
        public ?NameType $fileSystem = null,
        public ?CollectionItem $collectionItem = null,
        FileSpecificationName ...$additionalNames,
    ) {
        $this->additionalNames = array_values($additionalNames);
    }

    /** @return iterable<Entry> */
    public function entries(): iterable
    {
        foreach ($this->additionalNames as $name) {
            if ($name->key === EmbeddedFileKey::File) {
                throw new \InvalidArgumentException('The F filename is supplied by the file-specification constructor.');
            }
            yield new Entry(new NameType($name->key->value), $name->value);
        }
        foreach (['EF' => $this->embedded, 'RF' => $this->related, 'Desc' => $this->description, 'ID' => $this->identifiers, 'V' => $this->volatile, 'FS' => $this->fileSystem,
            'CI' => $this->collectionItem === null ? null : new IndirectReference($this->collectionItem)] as $key => $value) {
            if ($value !== null) {
                yield new Entry(new NameType($key), $value);
            }
        }
    }
}
