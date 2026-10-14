<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** A wrapper is one unencrypted PDF with one named payload, hidden collection and catalog association. */
final readonly class EncryptedPayloadGraphValidation
{
    public function validate(DocumentGraphContext $graph, EffectivePdfVersion $version, bool $encrypted = false): void
    {
        $af = $graph->entry($graph->catalog, 'AF');
        $af = $af === null ? null : $graph->resolve($af);
        if (!$af instanceof ArrayType) {
            return;
        }
        foreach ($af as $fileValue) {
            $file = $graph->dictionary($fileValue);
            $relationship = $file === null ? null : $graph->entry($file, 'AFRelationship');
            $relationship = $relationship === null ? null : $graph->resolve($relationship);
            if (!$relationship instanceof NameType || $relationship->value !== 'EncryptedPayload') {
                continue;
            }
            $version->require(PdfVersion::PDF20, 'Unencrypted encrypted-payload wrappers');
            if ($encrypted) {
                throw new \InvalidArgumentException('An encrypted-payload wrapper must itself be unencrypted.');
            }
            $descriptor = $file === null ? null : $graph->entry($file, 'EP');
            $descriptor = $descriptor === null ? null : $graph->dictionary($descriptor);
            if ($descriptor === null) {
                throw new \InvalidArgumentException('An encrypted payload requires its EP descriptor.');
            }
            $this->validateDescriptor($descriptor, $graph);
            $ef = $file === null ? null : $graph->entry($file, 'EF');
            $bindings = $ef === null ? null : $graph->dictionary($ef);
            if (!$fileValue instanceof IndirectReference || $bindings === null || ($graph->entry($bindings, 'F') === null && $graph->entry($bindings, 'UF') === null)) {
                throw new \InvalidArgumentException('An encrypted payload must be an indirectly referenced file specification with an embedded F or UF stream.');
            }
            $names = $graph->entry($graph->catalog, 'Names');
            $names = $names === null ? null : $graph->dictionary($names);
            $embedded = $names === null ? null : $graph->entry($names, 'EmbeddedFiles');
            $files = $embedded === null ? [] : $graph->nameTree($embedded);
            if (count($files) !== 1 || !$graph->sameObject(array_values($files)[0], $fileValue)) {
                throw new \InvalidArgumentException('An encrypted-payload wrapper must name exactly its one payload in EmbeddedFiles.');
            }
            $collection = $graph->entry($graph->catalog, 'Collection');
            $collection = $collection === null ? null : $graph->dictionary($collection);
            $view = $collection === null ? null : $graph->entry($collection, 'View');
            $view = $view === null ? null : $graph->resolve($view);
            $initial = $collection === null ? null : $graph->entry($collection, 'D');
            $initial = $initial === null ? null : $graph->resolve($initial);
            if (!$view instanceof NameType || $view->value !== 'H' || !$initial instanceof StringType || !isset($files[bin2hex($initial->value)])) {
                throw new \InvalidArgumentException('An encrypted-payload wrapper requires hidden Collection.View H and its payload name as Collection.D.');
            }
        }
    }

    public function validateDescriptor(DictionaryType $descriptor, DocumentGraphContext $graph): void
    {
        foreach (['Type', 'Subtype', 'Version'] as $key) {
            $value = $graph->entry($descriptor, $key);
            $value = $value === null ? null : $graph->resolve($value);
            if ($value === null && $key !== 'Subtype') {
                continue;
            }
            if (!$value instanceof NameType || $value->value === '' || ($key === 'Type' && $value->value !== 'EncryptedPayload') || ($key === 'Version' && preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $value->value) !== 1)) {
                throw new \InvalidArgumentException('An EP descriptor requires its named security handler, optional Type EncryptedPayload and optional dotted version name.');
            }
        }
    }
}
