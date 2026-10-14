<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\DocumentGraphContext;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** ISO 32000-2 Table255 / 12.8.5, physical pp.584–585,605–607; no implicit PKI validation. */
final readonly class DocumentTimestampGraphValidation
{
    public function validate(BasicType $object, DictionaryType $dictionary, DocumentGraphContext $graph, EffectivePdfVersion $version, ?FileRevision $previous = null): void
    {
        $type = $graph->entry($dictionary, 'Type');
        $subfilter = $graph->entry($dictionary, 'SubFilter');
        $resolvedType = $type === null ? null : $graph->resolve($type);
        $resolvedSubfilter = $subfilter === null ? null : $graph->resolve($subfilter);
        $timestamp = $resolvedType instanceof NameType && $resolvedType->value === 'DocTimeStamp';
        $rfc3161 = $resolvedSubfilter instanceof NameType && $resolvedSubfilter->value === 'ETSI.RFC3161';
        if (!$timestamp && !$rfc3161) {
            return;
        }
        $version->require(PdfVersion::PDF20, 'Document timestamp dictionaries');
        if (!$type instanceof NameType || $type->value !== 'DocTimeStamp' || !$subfilter instanceof NameType || $subfilter->value === '') {
            throw new \InvalidArgumentException('Document timestamps require direct Type DocTimeStamp and SubFilter names; ETSI.RFC3161 cannot identify a Sig dictionary.');
        }
        foreach ($dictionary->entries() as $entry) {
            if ($graph->entry($dictionary, $entry->key) !== null && $entry->value instanceof IndirectReference) {
                throw new \InvalidArgumentException('All byte-range document timestamp dictionary values must be direct objects.');
            }
        }
        // ETSI.RFC3161 is the recommended standard format. Registered custom
        // subfilters remain representable, with no invented backend or trust.
        if ($rfc3161) {
            foreach (['Reference', 'Changes', 'Cert'] as $forbidden) {
                if ($graph->entry($dictionary, $forbidden) !== null) {
                    throw new \InvalidArgumentException('ETSI.RFC3161 document timestamps cannot contain /' . $forbidden . '.');
                }
            }
        }
        $filter = $graph->entry($dictionary, 'Filter');
        $contents = $graph->entry($dictionary, 'Contents');
        $range = $graph->entry($dictionary, 'ByteRange');
        if (!$filter instanceof NameType || $filter->value === '' || !$contents instanceof StringType || $contents->value === '' || !$range instanceof ArrayType || count($range) !== 4) {
            throw new \InvalidArgumentException('Document timestamps require a direct Filter name, nonempty hexadecimal Contents and four ByteRange integers.');
        }
        $values = [];
        foreach ($range->items as $number) {
            if (!$number instanceof IntegerType) {
                throw new \InvalidArgumentException('Document timestamp ByteRange contains four direct integers.');
            }
            $values[] = $number->integer();
        }
        // A native reservation is an explicit construction phase. Its zero
        // offsets are replaced by PreparedSignature using actual object spans.
        if ($object instanceof PendingDocumentTimestamp && $contents === $object->contents && $values === [0, 0, 0, 0]) {
            return;
        }
        if (!$contents instanceof HexadecimalString || $values[0] !== 0 || $values[1] <= 0) {
            throw new \InvalidArgumentException('A finalized document timestamp uses hexadecimal Contents and covers the file starting at byte zero.');
        }
        $signed = new SignatureByteRange($values[1], $values[2], $values[3]);
        if ($signed->afterOffset - $signed->before !== strlen($contents->toPdf(new SerializationContext()))) {
            throw new \InvalidArgumentException('A document timestamp ByteRange excludes exactly its complete hexadecimal Contents token.');
        }
        if ($previous !== null && !$graph->isCurrent($object)) {
            $this->historicalBytes($object, $contents, $signed, $graph, $previous);
        }
    }

    private function historicalBytes(BasicType $object, HexadecimalString $contents, SignatureByteRange $range, DocumentGraphContext $graph, FileRevision $previous): void
    {
        $identifier = $graph->identifier($object);
        if ($identifier === null) {
            return;
        }
        $length = $range->afterOffset + $range->afterLength;
        for ($revision = $previous; $revision !== null; $revision = $revision->previous) {
            if (strlen($revision->bytes) !== $length) {
                continue;
            }
            try {
                $span = $revision->span($identifier);
            } catch (\OutOfBoundsException $error) {
                throw new \InvalidArgumentException('A document timestamp requires its retained ordinary indirect-object span.', previous: $error);
            }
            if ($range->before < $span->offset->value || $range->afterOffset > $span->offset->value + $span->length
                || substr($revision->bytes, $range->before, $range->afterOffset - $range->before) !== $contents->toPdf(new SerializationContext())) {
                throw new \InvalidArgumentException('Retained document timestamp ByteRange must exclude its actual frozen Contents token.');
            }
            return;
        }
        throw new \InvalidArgumentException('Retained document timestamp ByteRange must cover an entire known signed revision.');
    }
}
