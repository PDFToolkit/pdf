<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/**
 * Fail closed at the common incremental writer boundary.
 * ISO 32000-2 12.8.2/12.8.6, physical pages 588–592/607–608;
 * PDF32000_2008.pdf, physical pages 478–482. Mathematical verification precedes
 * policy use; this is deliberately not a classifier for arbitrary PDF changes.
 */
final readonly class IncrementalSignaturePermissions
{
    public function validate(FileRevision $current): void
    {
        $previous = $current->previous ?? throw new \LogicException('Incremental permissions require an actual previous revision.');
        $protected = false;
        $seen = [];
        $signatures = [];
        $rules = new SignatureDocumentSigningRules();
        for ($revision = $previous; $revision !== null; $revision = $revision->previous) {
            if (!$revision->isNativeEmission()) {
                throw new \LogicException('Permission history requires native emitted revisions, not supplied snapshots.');
            }
            $graph = new FrozenSignatureGraph($revision);
            $perms = $graph->resolve($graph->catalog->get('Perms'));
            if ($perms !== null && !$perms instanceof DictionaryType) {
                throw new \LogicException('Historical catalog permissions are an actual dictionary.');
            }
            if ($perms instanceof DictionaryType && ($graph->resolve($perms->get('UR')) !== null || $graph->resolve($perms->get('UR3')) !== null)) {
                throw new \LogicException('Historical usage rights require an authorized granting identity and a complete rights-change classifier; native incremental writing cannot grant those rights.');
            }
            $protected = $protected || ($perms instanceof DictionaryType && $graph->resolve($perms->get('DocMDP')) !== null);
            foreach ($revision->objects() as $record) {
                $dictionary = $record->dictionary;
                if ($dictionary === null || !$dictionary->has('ByteRange') || !$dictionary->has('Contents')) {
                    continue;
                }
                $type = $dictionary->get('Type');
                $filter = $dictionary->get('Filter');
                $format = $dictionary->get('SubFilter');
                if (!($type instanceof NameType && in_array($type->value, ['Sig', 'DocTimeStamp'], true))
                    && !($filter instanceof NameType && $format instanceof NameType && SignatureFormat::tryFrom($format->value) !== null)
                    && $graph->bindings($record->identifier) === []) {
                    continue; // Private extensions may use these two key names without being signatures.
                }
                $contents = $dictionary->get('Contents');
                if ($contents instanceof SignatureContentsReservation) {
                    // An empty reservation may already belong to an unsigned field.
                    // It contributes no authority or historical restriction.
                    continue;
                }
                $methods = $rules->methods($graph, $dictionary);
                if (isset($methods['UR'])) {
                    throw new \LogicException('Historical usage-rights transforms are not an authenticated native change authority.');
                }
                $protected = $protected || isset($methods['DocMDP']) || isset($methods['FieldMDP']);
                $range = $dictionary->get('ByteRange');
                if (!$contents instanceof StringType || !$range instanceof ArrayType || count($range->items) !== 4
                    || !$range->items[2] instanceof IntegerType || !$range->items[3] instanceof IntegerType) {
                    throw new \LogicException('Native signature history retains its actual Contents and complete-file ByteRange.');
                }
                $after = $range->items[2]->integer();
                $length = $range->items[3]->integer();
                if ($after < 0 || $length < 0 || $after > PHP_INT_MAX - $length) {
                    throw new \LogicException('Native signature history has a bounded complete-file ByteRange.');
                }
                $key = (string) $record->identifier . '@' . ($after + $length);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $source = $rules->signedGraph($graph, $after + $length)->revision;
                $proof = $source->verifiedSignature;
                if ($proof !== null && (string) $proof->identifier === (string) $record->identifier) {
                    $proof->validateBinding($source);
                } else {
                    $proof = null;
                }
                $signatures[$key] = $proof;
            }
        }
        if (!$protected) {
            return;
        }
        foreach ($signatures as $proof) {
            if ($proof === null) {
                throw new \LogicException('Protected incremental history requires mathematical verification of every actual completed signature; opaque finalization or a pending reservation is not authority.');
            }
        }
        if ($signatures === []) {
            throw new \LogicException('Declared catalog permissions have no authenticated native signature authority.');
        }
        $graph = new FrozenSignatureGraph($current);
        $prior = new FrozenSignatureGraph($previous);
        $history = new SignaturePermissionHistory($graph);
        $allowDss = (new EffectivePdfVersion($previous->header, $prior->catalog))->value === PdfVersion::PDF20;
        $pending = [];
        foreach ($current->objects() as $record) {
            if ($record->value instanceof PendingSignatureDictionary) {
                $pending[] = $record->value;
            }
        }
        if (count($pending) > 1) {
            throw new \LogicException('Protected native incremental signing introduces exactly one signature reservation.');
        }
        if ($pending === []) {
            if (!$allowDss) {
                throw new \LogicException('Protected native revisions support only proven signing; DSS maintenance requires PDF 2.0.');
            }
            $history->assertDssMaintenance();
            (new SignatureRevisionDelta())->validate($graph, null, null);
            return;
        }
        $signature = $pending[0];
        $identifier = $current->binding($signature)->identifier;
        $bindings = $graph->bindings($identifier);
        if (count($bindings) !== 1) {
            throw new \LogicException('Protected signing has one actual terminal field binding.');
        }
        if ($signature instanceof PendingDocumentTimestamp) {
            if (!$allowDss) {
                throw new \LogicException('Document timestamp maintenance requires PDF 2.0.');
            }
            $history->assertDocumentTimestamp($graph, $bindings[0], $signature);
        } else {
            $history->assertSigning($bindings[0]);
        }
        // The entire delta is proved even for P2/P3 and FieldMDP-only histories.
        (new SignatureRevisionDelta())->validate($graph, $bindings[0], $signature, $allowDss);
    }
}
