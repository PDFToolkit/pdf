<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\Signatures\SignatureFormat;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** The selected external handler declares supported formats and its seed-parser capability explicitly. */
final readonly class SignatureHandlerCapabilities
{
    /** @var list<SignatureFormat> */
    public array $formats;
    public function __construct(public NameType $handler, public NumericType $seedParserCapability, SignatureFormat ...$formats)
    {
        if ($handler->value === '' || $formats === [] || $seedParserCapability->value < 0) {
            throw new \InvalidArgumentException('A signature handler declares its name, formats and a nonnegative parser capability.');
        }
        $seen = [];
        foreach ($formats as $format) {
            if (isset($seen[$format->value])) {
                throw new \InvalidArgumentException('Signature handler formats are distinct.');
            }
            $seen[$format->value] = true;
        }
        $this->formats = array_values($formats);
    }
    public function supports(SignatureFormat $format): bool
    {
        return in_array($format, $this->formats, true);
    }
}
