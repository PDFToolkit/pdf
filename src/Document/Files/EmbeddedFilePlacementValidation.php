<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\Annotations\AnnotationPageIndex;
use PDF\Document\DocumentGraphContext;
use PDF\Document\Navigation\NavigationLimits;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** EC3 #481, physical pages 153/656: effective embedded streams are reached through Filespec EF/RF. */
final readonly class EmbeddedFilePlacementValidation
{
    public function __construct(private NavigationLimits $limits = new NavigationLimits(maxNodes: 1_000_000))
    {
    }

    public function validate(DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        if ($version->value !== PdfVersion::PDF20) {
            return;
        }
        /** @var list<array{BasicType,EmbeddedFileReferenceRole}> $pending */
        $pending = [[$graph->catalog, EmbeddedFileReferenceRole::Ordinary]];
        /** @var array<string,true> $seen */
        $seen = [];
        /** @var array<string,true> $embedded */
        $embedded = [];
        /** @var array<string,true> $otherUses */
        $otherUses = [];
        while ($pending !== []) {
            [$value, $role] = array_pop($pending);
            $scope = $value instanceof IndirectReference ? $graph : $graph->forDirectValue($value);
            $identifier = $scope->identifier($value);
            $identity = $identifier === null ? 'direct:' . spl_object_id($value) : (string) $identifier;
            $resolved = $scope->resolve($value);
            $dictionary = $scope->dictionary($value);
            if ($resolved instanceof StreamObject && $dictionary !== null) {
                $streamIdentifier = $graph->identifier($value);
                $identity = $streamIdentifier === null ? $identity : (string) $streamIdentifier;
                $type = $scope->entry($dictionary, 'Type');
                $type = $type === null ? null : $scope->resolve($type);
                if ($role === EmbeddedFileReferenceRole::FileStream || $type instanceof NameType && $type->value === 'EmbeddedFile') {
                    $embedded[$identity] = true;
                }
                if ($role !== EmbeddedFileReferenceRole::FileStream) {
                    $otherUses[$identity] = true;
                }
            }
            $key = $identity . ':' . $role->name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (count($seen) > $this->limits->maxNodes) {
                throw new \LengthException('Embedded-file placement exceeds the explicit document construction budget.');
            }
            if ($dictionary !== null) {
                $type = $scope->entry($dictionary, 'Type');
                $type = $type === null ? null : $scope->resolve($type);
                $fileSpecification = $type instanceof NameType && $type->value === 'Filespec';
                foreach ($dictionary->entries() as $entry) {
                    $childRole = match (true) {
                        $fileSpecification && $entry->key->value === 'EF' => EmbeddedFileReferenceRole::EmbeddedBindings,
                        $fileSpecification && $entry->key->value === 'RF' => EmbeddedFileReferenceRole::RelatedBindings,
                        $role === EmbeddedFileReferenceRole::EmbeddedBindings => EmbeddedFileReferenceRole::FileStream,
                        $role === EmbeddedFileReferenceRole::RelatedBindings => EmbeddedFileReferenceRole::RelatedPairs,
                        default => EmbeddedFileReferenceRole::Ordinary,
                    };
                    $pending[] = [$entry->value, $childRole];
                }
            } elseif ($resolved instanceof ArrayType) {
                foreach ($resolved->items as $index => $item) {
                    $pending[] = [$item, $role === EmbeddedFileReferenceRole::RelatedPairs && $index % 2 === 1 ? EmbeddedFileReferenceRole::FileStream : EmbeddedFileReferenceRole::Ordinary];
                }
            }
        }
        if (array_intersect_key($embedded, $otherUses) !== []) {
            throw new \InvalidArgumentException('Embedded file streams may only be referenced through Filespec EF or RF entries (EC3 #481).');
        }
        foreach ((new AnnotationPageIndex($graph, $this->limits))->annotations as $annotation) {
            $scope = $annotation->graph;
            $subtype = $scope->entry($annotation->dictionary, 'Subtype');
            $subtype = $subtype === null ? null : $scope->resolve($subtype);
            if (!$subtype instanceof NameType || $subtype->value !== 'FileAttachment') {
                continue;
            }
            $reference = $scope->entry($annotation->dictionary, 'FS');
            $file = $reference instanceof IndirectReference ? $scope->dictionary($reference) : null;
            $ef = $file === null ? null : $scope->entry($file, 'EF');
            $bindings = $ef === null ? null : $scope->dictionary($ef);
            $hasStream = false;
            if ($file !== null && $bindings !== null) {
                (new FileSpecificationRules())->validate($file, $scope);
                foreach ($bindings->entries() as $binding) {
                    $hasStream = $hasStream || $scope->resolve($binding->value) instanceof StreamObject;
                }
            }
            if (!$hasStream) {
                throw new \InvalidArgumentException('A FileAttachment annotation requires a file specification containing an embedded file stream (EC3 #481).');
            }
        }
    }
}
