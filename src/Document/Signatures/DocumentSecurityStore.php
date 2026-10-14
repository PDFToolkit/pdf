<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** ISO 32000-2 Table 261, physical PDF pages 601-602; EC3 #448 Completed. */
final class DocumentSecurityStore extends DictionaryType implements VersionedComponent
{
    public function __construct(?ValidationDataReferences $certificates = null, ?ValidationDataReferences $revocationLists = null, ?ValidationDataReferences $ocspResponses = null, ValidationRelatedInformation ...$validation)
    {
        $entries = [new Entry(new NameType('Type'), new NameType('DSS'))];
        foreach ([[ValidationDataKind::Certificate, $certificates], [ValidationDataKind::CertificateRevocationList, $revocationLists], [ValidationDataKind::OnlineCertificateStatus, $ocspResponses]] as [$kind, $references]) {
            if ($references !== null) {
                if ($references->kind !== $kind) {
                    throw new \InvalidArgumentException('The DSS evidence array must have the matching kind.');
                }
                $entries[] = new Entry(new NameType($kind->value), $references);
            }
        }
        if ($validation !== []) {
            $map = [];
            foreach ($validation as $information) {
                foreach ([['Cert', $certificates], ['CRL', $revocationLists], ['OCSP', $ocspResponses]] as [$key, $references]) {
                    $subset = $information->get($key);
                    foreach ($subset instanceof ValidationDataReferences ? $subset->items : [] as $reference) {
                        $found = false;
                        foreach ($references->items ?? [] as $candidate) {
                            $found = $found || ($candidate instanceof IndirectReference && $reference instanceof IndirectReference && $candidate->target() === $reference->target());
                        }
                        if (!$found) {
                            throw new \InvalidArgumentException('All VRI evidence must also occur in the DSS arrays.');
                        }
                    }
                }
                $map[] = new Entry($information->key->name, new IndirectReference($information));
            }
            $entries[] = new Entry(new NameType('VRI'), new DictionaryType(...$map));
        }
        parent::__construct(...$entries);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Document Security Store');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct a new explicit document security store.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('The document security store is immutable.');
    }
}
