<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** The identity of Catalog/Metadata, the document-level EncryptMetadata exception. */
final readonly class DocumentMetadataExemption
{
    public function __construct(public StreamObject $stream)
    {
        foreach (['Type' => 'Metadata', 'Subtype' => 'XML'] as $key => $expected) {
            $value = $stream->dictionary()->get($key);
            if ($value instanceof IndirectReference) {
                $value = $value->target();
            }
            if (!$value instanceof NameType || $value->value !== $expected) {
                throw new \InvalidArgumentException('Document-level metadata must be a /Type /Metadata /Subtype /XML stream.');
            }
        }
    }
}
