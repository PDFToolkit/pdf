<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** ISO12.8.1/12.8.2.2, PDF475/478: actual ownership, first certification and incremental signing. */
final readonly class SignatureDocumentSigningRules
{
    public function validate(FrozenSignatureGraph $graph, ObjectIdentifier $selected): void
    {
        $fields = $graph->bindings($selected);
        if (count($fields) > 1) {
            throw new \LogicException('A policy signing operation selects one unambiguous signature field.');
        }
        $candidate = $graph->revision->binding($selected)->dictionary ?? throw new \InvalidArgumentException('The signature reservation has a dictionary snapshot.');
        $candidateMethods = $this->methods($graph, $candidate);
        $permissions = $graph->resolve($graph->catalog->get('Perms'));
        if ($permissions !== null && !$permissions instanceof DictionaryType) {
            throw new \InvalidArgumentException('The actual catalog permissions are a dictionary.');
        }
        $docTarget = $permissions instanceof DictionaryType ? $permissions->get('DocMDP') : null;
        $urTarget = $permissions instanceof DictionaryType ? $permissions->get('UR3') : null;
        if ($graph->resolve($docTarget) === null) {
            $docTarget = null;
        }
        if ($graph->resolve($urTarget) === null) {
            $urTarget = null;
        }
        foreach (['DocMDP' => $docTarget, 'UR3' => $urTarget] as $method => $target) {
            if ($target === null) {
                continue;
            }
            if (!$target instanceof IndirectReference) {
                throw new \InvalidArgumentException('Permissions signatures have actual indirect identities.');
            }
            $record = $graph->record($target);
            $signature = $record->dictionary ?? throw new \InvalidArgumentException('A permissions entry identifies a signature dictionary.');
            $methods = $this->methods($graph, $signature);
            if (!isset($methods[$method === 'UR3' ? 'UR' : 'DocMDP'])) {
                throw new \InvalidArgumentException('The permissions target contains the matching signature transform.');
            }
            if ($method === 'DocMDP' && count($graph->bindings($record->identifier)) !== 1) {
                throw new \InvalidArgumentException('The actual DocMDP signature belongs to one field in the actual AcroForm.');
            }
            if ($method === 'UR3' && $graph->bindings($record->identifier) !== []) {
                throw new \InvalidArgumentException('A UR3 signature belongs to Perms and not to a form field.');
            }
        }
        if (isset($candidateMethods['DocMDP']) && count($fields) !== 1) {
            throw new \InvalidArgumentException('Certification signatures are values of signature fields.');
        }
        if (isset($candidateMethods['UR'])) {
            if ($fields !== [] || !$urTarget instanceof IndirectReference || (string) $graph->record($urTarget)->identifier !== (string) $selected) {
                throw new \InvalidArgumentException('The selected usage-rights signature is the actual Perms/UR3 target outside the field tree.');
            }
        }
        $certifications = isset($candidateMethods['DocMDP']) ? 1 : 0;
        $usageRights = isset($candidateMethods['UR']) ? 1 : 0;
        $usageSeen = [];
        $seen = [];
        for ($revision = $graph->revision; $revision !== null; $revision = $revision->previous) {
            $historical = $revision === $graph->revision ? $graph : new FrozenSignatureGraph($revision);
            foreach ($historical->fields() as $field) {
                $reference = $field->effective('V');
                if (!$reference instanceof IndirectReference || $historical->resolve($reference) === null) {
                    continue;
                }
                $record = $historical->record($reference);
                $dictionary = $record->dictionary ?? throw new \InvalidArgumentException('The effective field value identifies a signature dictionary.');
                if ($dictionary->get('Contents') instanceof SignatureContentsReservation) {
                    continue;
                }
                $this->assertCompleted($dictionary, strlen($revision->bytes));
                $end = $this->signedEnd($dictionary);
                $key = (string) $record->identifier . '@' . $end;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $source = $this->signedGraph($graph, $end);
                $signedDictionary = $source->revision->binding($record->identifier)->dictionary ?? throw new \InvalidArgumentException('The signed snapshot retains the actual signature dictionary.');
                if (isset($this->methods($source, $signedDictionary)['DocMDP'])) {
                    ++$certifications;
                }
                if (isset($candidateMethods['DocMDP'])) {
                    throw new \LogicException('A document has at most one certification, and it must be its first signed field.');
                }
                if ($end === strlen($graph->revision->bytes)) {
                    throw new \LogicException('Apply each later signature in a new incremental revision; this revision already contains a completed signature.');
                }
            }
            $historicalPermissions = $historical->resolve($historical->catalog->get('Perms'));
            $usageReference = $historicalPermissions instanceof DictionaryType ? $historicalPermissions->get('UR3') : null;
            if ($usageReference instanceof IndirectReference && $historical->resolve($usageReference) !== null) {
                $usageRecord = $historical->record($usageReference);
                $usageDictionary = $usageRecord->dictionary ?? throw new \InvalidArgumentException('The actual UR3 target is a signature dictionary.');
                if (!$usageDictionary->get('Contents') instanceof SignatureContentsReservation) {
                    $this->assertCompleted($usageDictionary, strlen($revision->bytes));
                    $end = $this->signedEnd($usageDictionary);
                    $key = (string) $usageRecord->identifier . '@' . $end;
                    if (!isset($usageSeen[$key])) {
                        $usageSeen[$key] = true;
                        $source = $this->signedGraph($graph, $end);
                        $signedUsage = $source->revision->binding($usageRecord->identifier)->dictionary ?? throw new \InvalidArgumentException('The signed snapshot retains the actual UR3 signature.');
                        if (!isset($this->methods($source, $signedUsage)['UR']) || $source->bindings($usageRecord->identifier) !== []) {
                            throw new \InvalidArgumentException('Historical UR3 signatures contain the UR transform and belong outside the field tree.');
                        }
                        ++$usageRights;
                    }
                }
            }
        }
        if ($certifications > 1) {
            throw new \LogicException('A document contains at most one certification signature.');
        }
        if ($usageRights > 2) {
            throw new \LogicException('A document contains at most two usage-rights signatures across its actual revision history.');
        }
        if (isset($candidateMethods['DocMDP']) && $docTarget !== null && (!$docTarget instanceof IndirectReference || (string) $graph->record($docTarget)->identifier !== (string) $selected)) {
            throw new \InvalidArgumentException('A present catalog Perms/DocMDP entry targets the actual selected certification.');
        }
        foreach ($graph->revision->objects() as $record) {
            if ((string) $record->identifier === (string) $selected) {
                continue;
            }
            $dictionary = $record->dictionary;
            $type = $dictionary?->get('Type');
            $contents = $dictionary?->get('Contents');
            if ($dictionary !== null && $type instanceof NameType && $type->value === 'Sig' && $contents instanceof StringType && !$contents instanceof SignatureContentsReservation) {
                $this->assertCompleted($dictionary, strlen($graph->revision->bytes));
                if ($this->signedEnd($dictionary) === strlen($graph->revision->bytes)) {
                    throw new \LogicException('Apply each later signature in a new incremental revision; this revision already contains a completed signature.');
                }
            }
        }
    }

    /** @return array<string, DictionaryType> */
    public function methods(FrozenSignatureGraph $graph, DictionaryType $signature): array
    {
        $references = $signature->get('Reference');
        if ($references === null) {
            return [];
        }
        if (!$references instanceof ArrayType) {
            throw new \InvalidArgumentException('Byte-range signature Reference is a direct array.');
        }
        $methods = [];
        foreach ($references->items as $reference) {
            if (!$reference instanceof DictionaryType) {
                throw new \InvalidArgumentException('Signature references are direct dictionaries.');
            }
            $method = $reference->get('TransformMethod');
            if (!$method instanceof NameType || !in_array($method->value, ['DocMDP', 'FieldMDP', 'UR'], true) || isset($methods[$method->value])) {
                throw new \InvalidArgumentException('A native signature has distinct known transform methods.');
            }
            $parameters = $reference->get('TransformParams');
            if ($parameters !== null && !$parameters instanceof DictionaryType) {
                throw new \InvalidArgumentException('Transform parameters are direct dictionaries.');
            }
            if ($method->value === 'FieldMDP') {
                $data = $reference->get('Data');
                if (!$data instanceof IndirectReference) {
                    throw new \InvalidArgumentException('FieldMDP has explicit indirect Data.');
                }
                $target = $graph->resolve($data);
                if ($target !== $graph->catalog && $target !== $graph->form) {
                    throw new \InvalidArgumentException('FieldMDP Data identifies this actual catalog or AcroForm.');
                }
            }
            $methods[$method->value] = $parameters ?? new DictionaryType();
            if ($method->value === 'DocMDP') {
                $this->docMdpPermissions($graph, $methods[$method->value]);
            }
        }
        if (isset($methods['UR']) && count($methods) !== 1) {
            throw new \InvalidArgumentException('A usage-rights signature is separate from approval/certification.');
        }
        return $methods;
    }

    /** EC3 #152, physical p589: PDF2 Table257 narrows P from number to integer. */
    public function docMdpPermissions(FrozenSignatureGraph $source, DictionaryType $parameters): IntegerType
    {
        $value = $parameters->get('P') ?? new IntegerType(2);
        if (!$value instanceof NumericType || !in_array($value->value, [1, 2, 3, 1.0, 2.0, 3.0], true)) {
            throw new \InvalidArgumentException('Actual DocMDP permissions are 1, 2 or 3.');
        }
        if ((new EffectivePdfVersion($source->revision->header, $source->catalog))->value === PdfVersion::PDF20
            && !$value instanceof IntegerType) {
            throw new \InvalidArgumentException('PDF 2.0 DocMDP P requires an integer object (EC3 #152).');
        }
        return new IntegerType((int) $value->value);
    }

    private function assertCompleted(DictionaryType $signature, int $size): void
    {
        $contents = $signature->get('Contents');
        if (!$contents instanceof StringType || trim($contents->value, "\0") === '' || $this->signedEnd($signature) > $size) {
            throw new \InvalidArgumentException('Existing signed fields have a nonempty value and a valid historical ByteRange.');
        }
    }

    public function signedGraph(FrozenSignatureGraph $graph, int $end): FrozenSignatureGraph
    {
        for ($revision = $graph->revision; $revision !== null; $revision = $revision->previous) {
            if (strlen($revision->bytes) === $end) {
                if (!str_starts_with($graph->revision->bytes, $revision->bytes)) {
                    throw new \InvalidArgumentException('Signature history belongs to the actual emitted document prefix.');
                }
                return $revision === $graph->revision ? $graph : new FrozenSignatureGraph($revision);
            }
        }
        throw new \LogicException('Signature analysis requires the actual generated snapshot at its signed ByteRange end; external revisions need a normalized reader history.');
    }

    private function signedEnd(DictionaryType $signature): int
    {
        $range = $signature->get('ByteRange');
        if (!$range instanceof ArrayType || count($range->items) < 4 || count($range->items) % 2 !== 0) {
            throw new \InvalidArgumentException('A completed field signature has paired ByteRange offsets/lengths.');
        }
        $end = 0;
        foreach (array_chunk($range->items, 2) as [$offset, $length]) {
            if (!$offset instanceof IntegerType || !$length instanceof IntegerType || $offset->integer() < $end || $length->integer() < 0 || $offset->integer() > PHP_INT_MAX - $length->integer()) {
                throw new \InvalidArgumentException('A historical signature range is ordered and bounded.');
            }
            $end = $offset->integer() + $length->integer();
        }
        return $end;
    }
}
