<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\IndirectObject;

/**
 * https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1956489
 *
 * The object identifier shall consist of two parts:
 * • A positive integer object number. Indirect objects may be numbered sequentially within a PDF file, but this is not required; object numbers may be assigned in any arbitrary order.
 * • A non-negative integer generation number. In a newly created file, all indirect objects shall have generation numbers  of  0.  Nonzero  generation  numbers  may  be  introduced  when  the  file  is  later  updated;  see  sub-clauses 7.5.4, "Cross-Reference Table" and 7.5.6, "Incremental Updates."
 */
class ObjectIdentifier
{
    private static $instance;
    /** @var ObjectNumber */
    private $objectNumber;

    /** @var GenerationNumber */
    private $generationNumber;

    /** @var object */
    private object $root;

    /** @var array */
    private array $objectNumbers;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function objectNumber(object $object): ObjectNumber
    {
        $hash = spl_object_hash($object);

        if (!isset($this->objectNumbers[$hash])) {
            $this->objectNumbers[$hash] = $this->objectNumber->increment();
        }

        return $this->objectNumbers[$hash];
    }

    public function generationNumber(): GenerationNumber
    {
        return $this->generationNumber;
    }

    private function __construct()
    {
        $this->objectNumber = new ObjectNumber();
        $this->generationNumber = new GenerationNumber();
        $this->objectNumbers = [];
    }
}
