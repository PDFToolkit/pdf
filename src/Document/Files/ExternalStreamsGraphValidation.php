<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** Table5/p28 and7.6.1/p63: the external-file exemption follows an actual effective file declaration. */
final readonly class ExternalStreamsGraphValidation
{
    public function validate(DictionaryType $dictionary, DocumentGraphContext $graph, bool $nativeExternal = false, bool $retainedExternal = false): void
    {
        $declared = $dictionary->get('F');
        if ($declared === null || $declared instanceof NullType) {
            if ($nativeExternal || $retainedExternal) {
                throw new \InvalidArgumentException('A native external stream retains a non-null effective file declaration F.');
            }
            return; // Generic null entries are omitted under 7.3.9.
        }
        $file = $graph->resolve($declared);
        if ($file instanceof NullType) {
            if ($nativeExternal || $retainedExternal) {
                throw new \InvalidArgumentException('An external stream file dependency cannot be retired while its declaration remains live.');
            }
            return;
        }
        $this->validateFile($file, $graph);
    }

    public function validateFile(?BasicType $file, ?DocumentGraphContext $graph = null): void
    {
        if ($file instanceof StringType) {
            return;
        }
        if (!$file instanceof DictionaryType) {
            throw new \InvalidArgumentException('An external stream F requires a non-null effective filename or file-specification dictionary.');
        }
        (new FileSpecificationRules())->validate($file, $graph);
    }
}
