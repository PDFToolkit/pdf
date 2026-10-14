<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Validate actual DSS/VRI relationships and signed-revision ordering, including raw dictionaries. */
final readonly class DssGraphValidation
{
    public function validate(DocumentGraphContext $graph, EffectivePdfVersion $version, ?FileRevision $previous = null): void
    {
        $value = $graph->entry($graph->catalog, 'DSS');
        if ($value === null) {
            return;
        }
        $version->require(PdfVersion::PDF20, 'Document Security Store');
        $store = $graph->dictionary($value) ?? throw new \InvalidArgumentException('Catalog.DSS identifies a dictionary.');
        $type = $graph->entry($store, 'Type');
        if ($type !== null && (!$type instanceof NameType || $type->value !== 'DSS')) {
            throw new \InvalidArgumentException('DSS.Type is DSS when present.');
        }
        $arrays = [];
        foreach (ValidationDataKind::cases() as $kind) {
            $arrays[$kind->value] = $this->references($store, $kind->value, $graph);
        }
        $vriValue = $graph->entry($store, 'VRI');
        if ($vriValue === null) {
            return;
        }
        $map = $graph->dictionary($vriValue) ?? throw new \InvalidArgumentException('DSS.VRI identifies a dictionary.');
        if ($previous === null && count($map) !== 0) {
            throw new \LogicException('VRI dictionaries are introduced only in an incremental revision after their signature.');
        }
        $known = [];
        if ($previous !== null) {
            $records = [];
            for ($revision = $previous; $revision !== null; $revision = $revision->previous) {
                $historical = new FrozenSignatureGraph($revision);
                foreach ($historical->fields() as $field) {
                    $signature = $field->effective('V');
                    if ($signature instanceof IndirectReference && $historical->resolve($signature) !== null) {
                        $records[] = $historical->record($signature);
                    }
                }
                $permissions = $historical->resolve($historical->catalog->get('Perms'));
                $usage = $permissions instanceof DictionaryType ? $permissions->get('UR3') : null;
                if ($usage instanceof IndirectReference && $historical->resolve($usage) !== null) {
                    $records[] = $historical->record($usage);
                }
            }
            foreach ($records as $record) {
                $signature = $record->dictionary;
                $format = $signature?->get('SubFilter');
                $contents = $signature?->get('Contents');
                $range = $signature?->get('ByteRange');
                if (!$format instanceof NameType || !in_array($format->value, ['adbe.pkcs7.detached', 'adbe.pkcs7.sha1', 'ETSI.CAdES.detached', 'ETSI.RFC3161'], true)
                    || !$contents instanceof StringType || !$range instanceof ArrayType || count($range->items) !== 4) {
                    continue;
                }
                try {
                    $content = new SignatureContentsValue($contents);
                    $cms = (new \PDF\Security\SignatureDerReader())->element($content->value->bytes)->requireTag(48)->children();
                    if (!isset($cms[0]) || $cms[0]->encoding !== (new \PDF\Security\CmsDerEncoding())->oid('1.2.840.113549.1.7.2')) {
                        continue;
                    }
                } catch (\InvalidArgumentException|\OverflowException) {
                    continue;
                }
                $parts = [];
                foreach ($range->items as $part) {
                    if (!$part instanceof IntegerType || $part->integer() < 0) {
                        continue 2;
                    }
                    $parts[] = $part->integer();
                }
                if ($parts[0] !== 0 || $parts[2] <= $parts[1] || $parts[2] > strlen($previous->bytes) || $parts[3] > strlen($previous->bytes) - $parts[2]) {
                    continue;
                }
                if (substr($previous->bytes, $parts[1], $parts[2] - $parts[1]) !== '<' . strtoupper(bin2hex($contents->value)) . '>') {
                    continue;
                }
                $known[strtoupper(sha1($contents->value))] = true;
            }
        }
        foreach ($map->entries() as $entry) {
            if (preg_match('/\A[0-9A-F]{40}\z/', $entry->key->value) !== 1 || !isset($known[$entry->key->value])) {
                throw new \InvalidArgumentException('Each VRI key identifies actual completed Contents from an earlier revision, including padding.');
            }
            $information = $graph->dictionary($entry->value) ?? throw new \InvalidArgumentException('A VRI map value identifies a dictionary.');
            $type = $graph->entry($information, 'Type');
            if ($type !== null && (!$type instanceof NameType || $type->value !== 'VRI')) {
                throw new \InvalidArgumentException('A validation information Type is VRI when present.');
            }
            foreach (ValidationDataKind::cases() as $kind) {
                foreach ($this->references($information, $kind->vriKey(), $graph) as $reference) {
                    $found = false;
                    foreach ($arrays[$kind->value] as $candidate) {
                        $found = $found || $graph->sameObject($reference, $candidate);
                    }
                    if (!$found) {
                        throw new \InvalidArgumentException('VRI evidence references must also occur in the corresponding DSS arrays.');
                    }
                }
            }
            $timestamp = $graph->entry($information, 'TS');
            if ($timestamp !== null && (!$timestamp instanceof IndirectReference || !$graph->resolve($timestamp) instanceof StreamObject)) {
                throw new \InvalidArgumentException('VRI.TS is an indirect DER timestamp stream.');
            }
            $created = $graph->entry($information, 'TU');
            if ($created !== null && (!$created instanceof StringType || preg_match('/\AD:[0-9]{4}(?:[0-9]{2}){0,5}(?:Z|[+-][0-9]{2}\x27?(?:[0-9]{2}\x27?)?)?\z/', $created->value) !== 1)) {
                throw new \InvalidArgumentException('VRI.TU is a PDF date string.');
            }
        }
    }

    /** @return list<IndirectReference> */
    private function references(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): array
    {
        $entry = $graph->entry($dictionary, $key);
        if ($entry === null) {
            return [];
        }
        $array = $graph->resolve($entry);
        if (!$array instanceof ArrayType || $array->items === []) {
            throw new \InvalidArgumentException('Validation evidence arrays are nonempty; empty entries are omitted.');
        }
        $references = [];
        foreach ($array->items as $reference) {
            if (!$reference instanceof IndirectReference || !$graph->resolve($reference) instanceof StreamObject) {
                throw new \InvalidArgumentException('Validation evidence is stored in indirect streams.');
            }
            foreach ($references as $previous) {
                if ($graph->sameObject($reference, $previous)) {
                    throw new \InvalidArgumentException('A validation evidence array cannot repeat an indirect identity.');
                }
            }
            $references[] = $reference;
        }
        return $references;
    }
}
