<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\StreamObject;

/** A file-scoped security operation, shared by the ISO and Adobe handlers. */
interface FileEncryption
{
    public function dictionary(): DictionaryType;
    public function metadata(): EncryptMetadata;
    public function validateFile(FileHeader $header, DictionaryType $catalog, FileIdentifier $identifier): void;
    public function encryptString(string $bytes, ObjectIdentifier $identifier): string;
    public function encryptStream(string $bytes, ObjectIdentifier $identifier, StreamObject $stream): string;
    /** @return list<Filter> The decoding pipeline advertised for this file security operation. */
    public function streamFilters(StreamObject $stream): array;
}
