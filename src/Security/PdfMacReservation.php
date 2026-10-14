<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\ByteRangeReservation;
use PDF\Document\Signatures\SignatureByteRange;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureContentsReservation;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\FileStructure\SerializedRevisionValue;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** ISO/TS 32004:2024, 5.2.3 and 6.5.1, physical PDF pages 12 and 16. */
final readonly class PdfMacReservation
{
    public DictionaryType $dictionary;
    private SignatureContentsReservation $contents;
    private ByteRangeReservation $range;

    public function __construct(private PdfMacTokenComposer $composer)
    {
        $this->contents = new SignatureContentsReservation(new SignatureCapacity($composer->tokenLength()));
        $this->range = new ByteRangeReservation();
        $this->dictionary = new PdfMacAuthCodeReservation($this->contents, $this->range);
    }

    public function trailer(DictionaryType $trailer): DictionaryType
    {
        return $trailer->with(new Entry(new NameType('AuthCode'), $this->dictionary));
    }

    /** Only examine the exact writer-owned trailer, never arbitrary document bytes. */
    public function finalize(string $bytes, int $trailerOffset, string $serializedTrailer): PdfMacFinalization
    {
        if ($trailerOffset < 0 || substr($bytes, $trailerOffset, strlen($serializedTrailer)) !== $serializedTrailer) {
            throw new \LogicException('The PDF MAC reservation must belong to the actual serialized trailer.');
        }
        $context = new SerializationContext();
        $needle = '/AuthCode ' . $this->dictionary->toPdf($context);
        $position = strpos($serializedTrailer, $needle);
        if ($position === false || strpos($serializedTrailer, $needle, $position + 1) !== false) {
            throw new \LogicException('Exactly one managed PDF MAC reservation must occur in this trailer.');
        }
        $prefix = '/AuthCode << /MACLocation /Standalone /MAC ';
        $before = $trailerOffset + $position + strlen($prefix);
        $after = $before + strlen($this->contents->toPdf($context));
        $range = new SignatureByteRange($before, $after, strlen($bytes) - $after);
        $rangeOffset = $after + strlen(' /ByteRange ');
        $filled = substr_replace($bytes, $this->range->filled($range), $rangeOffset, strlen($this->range->toPdf($context)));
        $token = $this->composer->token($range->coveredBytes($filled));
        if (strlen($token) !== $this->contents->capacity->bytes) {
            throw new \LogicException('PDF MAC output must exactly fill its predicted DER capacity without padding.');
        }
        $finalBytes = substr_replace($filled, '<' . strtoupper(bin2hex($token)) . '>', $before, $after - $before);
        $this->composer->verify($token, $range->coveredBytes($finalBytes));
        $authCode = new DictionaryType(
            new Entry(new NameType('MACLocation'), new NameType('Standalone')),
            new Entry(new NameType('MAC'), new HexadecimalString($token)),
            new Entry(new NameType('ByteRange'), $range->object()),
        );
        return new PdfMacFinalization($finalBytes, $authCode);
    }

    /** Freeze the finalized clear xref dictionary together with its unchanged binary records. */
    public function snapshot(SerializedRevisionValue $snapshot, DictionaryType $authCode): SerializedRevisionValue
    {
        $value = $snapshot->value;
        if (!$value instanceof RevisionStreamSnapshot || !$value->dictionary()->has('AuthCode')) {
            return $snapshot;
        }
        $type = $value->dictionary()->get('Type');
        if (!$type instanceof NameType || $type->value !== 'XRef') {
            return $snapshot;
        }
        return new SerializedRevisionValue(new RevisionStreamSnapshot(
            $value->dictionary()->with(new Entry(new NameType('AuthCode'), $authCode)),
            $value->encodedBytes,
            $value->markedContentFacts,
            $value->metadataMarkedContentFacts,
        ));
    }
}
