<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Exception\AlreadyExistsException;
use PDF\StringClass;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Entry;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1839799
 */
class FileBody implements StringClass
{
    /** @var [] */
    private $objects;

    public function __construct()
    {
        $this->objects = [];
    }

    public function __toString(): string
    {
        $objectIdentifier = ObjectIdentifier::instance();

        $string = sprintf(<<<PDF
%s %s obj
<< /Type /Catalog

PDF, $objectIdentifier->objectNumber($this), $objectIdentifier->generationNumber());

        /** @var BasicType $object */
        foreach ($this->objects as $object) {
            $string .= sprintf('%s %s', $object->key(), new IndirectReference($object));
        }
$string .= <<<PDF
>>
endobj

PDF;

        /** @var BasicType $object */
        foreach ($this->objects as $object) {
            $string .= sprintf('%s', new IndirectObject($object));
        }

        return $string;
    }

    public function addObject(Entry $object): self
    {
        if (isset($this->objects[get_class($object)])) {
            throw new AlreadyExistsException(sprintf('Entry "%s" has already defined.', get_class($object)));
        }
        $this->objects[get_class($object)] = $object;

        return $this;
    }

    public function addStreamObject(ObjectStream $objectStream): self
    {
        $this->objects[] = $objectStream;

        return $this;
    }
}
