<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\DataStructure\PdfDateValidation;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Actual AF dictionary associations, 14.13/pp.853–856; EC3 issue568 is Accepted, not Completed. */
final readonly class AssociatedFilesGraphValidation
{
    public function validate(DictionaryType $owner, DocumentGraphContext $graph, EffectivePdfVersion $version, ?AssociatedFileOwner $context = null): void
    {
        // Prior editions and explicit extensions retain their own semantics.
        if ($version->value !== PdfVersion::PDF20) {
            return;
        }
        $value = $graph->entry($owner, 'AF');
        if ($value === null) {
            return;
        }
        $files = $graph->resolve($value);
        if (!$files instanceof ArrayType) {
            throw new \InvalidArgumentException('AF requires an array of file specification dictionaries.');
        }
        $this->validateFiles($files, $graph, $version);
    }

    /** AF dictionaries and MCAF named property resources share effective Filespec relationships. */
    public function validateFiles(ArrayType $files, DocumentGraphContext $graph, EffectivePdfVersion $version, string $entry = 'AF'): void
    {
        $version->require(PdfVersion::PDF20, 'Associated files');
        if (count($files) === 0) {
            throw new \InvalidArgumentException($entry . ' requires a nonempty array of file specification dictionaries.');
        }
        foreach ($files as $value) {
            $projection = $value instanceof IndirectReference ? $graph : $graph->forDirectValue($value);
            $file = $projection->dictionary($value);
            if ($file === null) {
                throw new \InvalidArgumentException('Every ' . $entry . ' item must resolve to a file specification dictionary.');
            }
            (new FileSpecificationRules())->validate($file, $projection);
            $relationship = $projection->entry($file, 'AFRelationship');
            $relationship = $relationship === null ? null : $projection->resolve($relationship);
            if (!$relationship instanceof NameType || $relationship->value === '') {
                throw new \InvalidArgumentException('Every associated file requires an AFRelationship name (' . ($entry === 'MCAF' ? 'EC3 issue374, Completed' : 'EC3 issue568, Accepted') . ').');
            }
            $embedded = $projection->entry($file, 'EF');
            if (!$value instanceof IndirectReference && ($embedded !== null || $projection->entry($file, 'RF') !== null)) {
                throw new \InvalidArgumentException('An associated file specification containing EF or RF requires an indirect reference.');
            }
            if ($embedded === null) {
                continue;
            }
            $bindings = $projection->dictionary($embedded) ?? throw new \InvalidArgumentException('Associated embedded files require an EF dictionary.');
            foreach ($bindings->entries() as $binding) {
                if ($projection->entry($bindings, $binding->key) === null) {
                    continue;
                }
                $stream = $projection->dictionary($binding->value) ?? throw new \InvalidArgumentException('Associated embedded-file bindings require streams.');
                $subtype = $projection->entry($stream, 'Subtype');
                $subtype = $subtype === null ? null : $projection->resolve($subtype);
                if (!$subtype instanceof NameType || preg_match("@^[!#$%&'*+.^_`|~0-9A-Za-z-]+/[!#$%&'*+.^_`|~0-9A-Za-z-]+$@D", $subtype->value) !== 1) {
                    throw new \InvalidArgumentException('An associated embedded-file stream requires a valid MIME Subtype name; use application/octet-stream if unknown.');
                }
                $parameters = $projection->entry($stream, 'Params');
                if ($parameters !== null) {
                    $parameters = $projection->dictionary($parameters) ?? throw new \InvalidArgumentException('Associated embedded-file Params must be a dictionary.');
                    $date = $projection->entry($parameters, 'ModDate');
                    $date = $date === null ? null : $projection->resolve($date);
                    if (!$date instanceof StringType) {
                        throw new \InvalidArgumentException('Associated embedded-file Params require a source ModDate date string.');
                    }
                    try {
                        (new PdfDateValidation(new TextStringDecoder($version->value)))->validate($date);
                    } catch (\InvalidArgumentException $exception) {
                        throw new \InvalidArgumentException('Associated embedded-file ModDate requires a valid PDF date.', previous: $exception);
                    }
                }
            }
        }
    }

}
