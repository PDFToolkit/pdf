<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;

/** Table F.12, physical PDF page 702; shared-only groups explicitly use zero length/count. */
final readonly class EmbeddedFileHintGroup
{
    /** @var list<SharedObjectReference> */
    public array $sharedObjects;

    public function __construct(public ObjectNumber $embeddedFile, public IntegerType $objectCount, public IntegerType $length, SharedObjectReference ...$sharedObjects)
    {
        if ($objectCount->integer() < 0 || $length->integer() < 0 || (($objectCount->integer() === 0) !== ($length->integer() === 0))
            || ($objectCount->integer() === 0 && $sharedObjects === []) || ($objectCount->integer() > 0 && $sharedObjects !== [])) {
            throw new \InvalidArgumentException('An embedded-file hint contains either private objects or shared references, as required by F.4.6.');
        }
        $this->sharedObjects = array_values($sharedObjects);
    }
}
