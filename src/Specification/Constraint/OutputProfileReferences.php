<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Document\Files\FileSpecificationRules;
use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** EC3 #482, Table402 p.839: local profile locations, without fetching or interpreting ICC bytes. */
final readonly class OutputProfileReferences implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $references = $values->value($dictionary, 'URLs');
        if ($references === null) {
            return;
        }
        if (!$references instanceof ArrayType || count($references) === 0) {
            throw new \InvalidArgumentException('Output-profile URLs require a nonempty array.');
        }
        foreach ($references as $reference) {
            $file = $reference instanceof IndirectReference ? $reference->target() : $reference;
            if (!$file instanceof DictionaryType) {
                throw new \InvalidArgumentException('Output-profile URLs require file-specification dictionaries.');
            }
            (new FileSpecificationRules())->validate($file);
            $embedded = $values->value($file, 'EF');
            if ($embedded instanceof DictionaryType) {
                $hasStream = false;
                foreach ($embedded->entries() as $entry) {
                    $target = $entry->value instanceof IndirectReference ? $entry->value->target() : $entry->value;
                    $hasStream = $hasStream || $target instanceof StreamObject;
                }
                if (!$hasStream) {
                    throw new \InvalidArgumentException('An embedded output profile requires an embedded-file stream.');
                }
                continue;
            }
            $system = $values->value($file, 'FS');
            $name = $values->value($file, 'F');
            if (!$system instanceof NameType || $system->value !== 'URL' || !$name instanceof StringType) {
                throw new \InvalidArgumentException('An external output profile requires FS URL and an F string.');
            }
            // RFC3986 URI characters are all ASCII and therefore also PDFDocEncoding.
            // This checks the permitted byte repertoire and percent escapes, not URI resolution.
            if (preg_match("~\\A(?:[A-Za-z0-9._:/?\\#\\[\\]@!$&'()*+,;=\\x7e-]|%[0-9A-Fa-f]{2})*\\z~D", $name->value) !== 1) {
                throw new \InvalidArgumentException('An output-profile F string requires PDFDocEncoding bytes limited to RFC3986 characters and percent escapes.');
            }
        }
    }
}
