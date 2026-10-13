<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\FileStructure;
use PDF\Security\DocumentMetadataExemption;
use PDF\Security\FileEncryption;
use PDF\Security\FileIdentifier;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Initial-file security boundary; hint streams use normal stream encryption (F.4, PDF page 694). */
final readonly class LinearizationSecurity
{
    public ?DictionaryType $trailerEntries;
    private ?DocumentMetadataExemption $metadataExemption;

    public function __construct(FileStructure $file, DictionaryType $catalog, public ?FileEncryption $encryption = null)
    {
        $entries = $file->trailerEntries;
        $metadataExemption = null;
        if ($encryption !== null) {
            $identifier = $entries?->get('ID');
            if (!$identifier instanceof ArrayType || count($identifier->items) !== 2
                || !$identifier->items[0] instanceof StringType || !$identifier->items[1] instanceof StringType
                || strlen($identifier->items[0]->value) !== 16 || strlen($identifier->items[1]->value) !== 16) {
                throw new \LogicException('An encrypted initial linearized file requires two direct 16-byte file identifiers.');
            }
            $encryption->validateFile($file->header, $catalog, new FileIdentifier($identifier->items[0]));
            $this->validatePlaintextTrailer($entries);
            $entries = $entries->with(new Entry(new NameType('Encrypt'), new IndirectReference($encryption->dictionary())));
            if (!$encryption->metadata()->value && $catalog->has('Metadata')) {
                $metadata = $catalog->get('Metadata');
                if (!$metadata instanceof IndirectReference || !$metadata->target() instanceof StreamObject) {
                    throw new \LogicException('Catalog Metadata must reference the document-level metadata stream.');
                }
                $metadataExemption = new DocumentMetadataExemption($metadata->target());
            }
        }
        $this->trailerEntries = $entries;
        $this->metadataExemption = $metadataExemption;
    }

    public function context(): SerializationContext
    {
        return new SerializationContext(
            new ObjectNumber(),
            new ObjectIdentifierRemapping(),
            ...[...($this->encryption === null ? [] : [$this->encryption]), ...($this->metadataExemption === null ? [] : [$this->metadataExemption])]
        );
    }

    private function validatePlaintextTrailer(DictionaryType $entries): void
    {
        $pending = [];
        foreach ($entries->entries() as $entry) {
            if ($entry->key->value !== 'ID') {
                $pending[] = $entry->value;
            }
        }
        $seen = new ObjectIdentitySet();
        while ($pending !== []) {
            $value = array_pop($pending);
            if ($value instanceof IndirectReference || $seen->has($value)) {
                continue;
            }
            $seen->add($value);
            if ($value instanceof StringType) {
                throw new \LogicException('Encrypted files require indirect trailer strings outside ID.');
            }
            if ($value instanceof ArrayType) {
                array_push($pending, ...$value->items);
            } elseif ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    $pending[] = $entry->value;
                }
            }
        }
    }
}
