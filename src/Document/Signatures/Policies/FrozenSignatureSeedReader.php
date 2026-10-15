<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Signatures\FrozenSignatureGraph;
use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** Convert actual frozen seed dictionaries to the native condition model without consulting mutable aliases. */
final readonly class FrozenSignatureSeedReader
{
    public function __construct(private FrozenSignatureGraph $graph)
    {
    }

    public function seed(DictionaryType $dictionary): SignatureSeedValue
    {
        $this->type($dictionary, 'SV');
        $bits = $this->flags($dictionary, 511);
        $flags = array_values(array_filter(SignatureSeedFlag::cases(), static fn (SignatureSeedFlag $flag): bool => ($bits & $flag->value) !== 0));
        $entries = [];
        foreach ($dictionary->entries() as $entry) {
            $key = $entry->key->value;
            if (in_array($key, ['Type', 'Ff'], true)) {
                continue;
            }
            $value = $this->graph->resolve($entry->value);
            if ($value === null) {
                continue;
            }
            if (in_array($key, ['Reasons', 'LegalAttestation'], true)) {
                $value = $this->texts($value);
            }
            if ($key === 'AppearanceFilter') {
                $value = $this->text($value);
            }
            if ($key === 'Cert') {
                if (!$value instanceof DictionaryType) {
                    throw new \InvalidArgumentException('Cert is a certificate seed dictionary.');
                }
                $value = $this->certificate($value);
            }
            if ($key === 'MDP') {
                $permission = $value instanceof DictionaryType ? $this->graph->resolve($value->get('P')) : null;
                if (!$value instanceof DictionaryType || ($permission !== null && !$permission instanceof IntegerType)) {
                    throw new \InvalidArgumentException('Seed MDP P is an integer in its dictionary.');
                }
                $value = new SignatureSeedMdp($permission);
            }
            if ($key === 'TimeStamp') {
                $url = $value instanceof DictionaryType ? $this->graph->resolve($value->get('URL')) : null;
                $required = $value instanceof DictionaryType ? $this->graph->resolve($value->get('Ff')) : null;
                if (!$url instanceof StringType || ($required !== null && (!$required instanceof IntegerType || !in_array($required->integer(), [0, 1], true)))) {
                    throw new \InvalidArgumentException('Timestamp seeds have an ASCII URL and Ff0 or1.');
                }
                $value = new SignatureTimeStampSeed(new AsciiString($url->value), $required instanceof IntegerType && $required->integer() === 1);
            }
            $entries[] = new Entry($entry->key, $value);
        }
        $version = new \PDF\Syntax\FileStructure\EffectivePdfVersion($this->graph->revision->header, $this->graph->catalog);
        $entries[] = $version->value === \PDF\Syntax\FileStructure\PdfVersion::PDF20 ? SignatureSeedSemantics::Pdf20 : SignatureSeedSemantics::Pdf17;
        return new SignatureSeedValue(new SignatureSeedFlags(...$flags), ...$entries);
    }

    public function certificate(DictionaryType $dictionary): SignatureCertificateSeedValue
    {
        $this->type($dictionary, 'SVCert');
        $bits = $this->flags($dictionary, 111);
        $flags = array_values(array_filter(CertificateSeedFlag::cases(), static fn (CertificateSeedFlag $flag): bool => ($bits & $flag->value) !== 0));
        $entries = [];
        foreach ($dictionary->entries() as $entry) {
            if (in_array($entry->key->value, ['Type', 'Ff'], true)) {
                continue;
            }
            $value = $this->graph->resolve($entry->value);
            if ($value === null) {
                continue;
            }
            if ($entry->key->value === 'SubjectDN') {
                if (!$value instanceof ArrayType) {
                    throw new \InvalidArgumentException('SubjectDN is an array.');
                }
                $names = [];
                foreach ($value->items as $item) {
                    $item = $this->graph->resolve($item);
                    if (!$item instanceof DictionaryType) {
                        throw new \InvalidArgumentException('Each SubjectDN is a dictionary.');
                    }
                    $attributes = [];
                    foreach ($item->entries() as $attribute) {
                        $attributes[] = new Entry($attribute->key, $this->text($this->graph->resolve($attribute->value)));
                    }
                    $names[] = new CertificateSubjectName(...$attributes);
                }
                $value = new ArrayType(...$names);
            }
            $entries[] = new Entry($entry->key, $value);
        }
        return new SignatureCertificateSeedValue(new CertificateSeedFlags(...$flags), ...$entries);
    }

    public function lock(DictionaryType $dictionary): SignatureFieldLock
    {
        $this->type($dictionary, 'SigFieldLock');
        $action = $this->graph->resolve($dictionary->get('Action'));
        $fields = $this->graph->resolve($dictionary->get('Fields'));
        $permissions = $this->graph->resolve($dictionary->get('P'));
        if (!$action instanceof NameType || SignatureLockAction::tryFrom($action->value) === null) {
            throw new \InvalidArgumentException('The frozen signature lock has a known Action.');
        }
        if ($permissions !== null) {
            if (!$permissions instanceof NumericType || !in_array($permissions->value, [1, 2, 3, 1.0, 2.0, 3.0], true)) {
                throw new \InvalidArgumentException('Adobe lock P has integral value1,2 or3.');
            }
            $permissions = new IntegerType((int) $permissions->value);
        }
        return new SignatureFieldLock(SignatureLockAction::from($action->value), $fields === null ? null : $this->texts($fields), $permissions);
    }

    private function flags(DictionaryType $dictionary, int $known): int
    {
        $flags = $this->graph->resolve($dictionary->get('Ff'));
        if ($flags === null) {
            return 0;
        }
        if (!$flags instanceof IntegerType || $flags->integer() < 0 || ($flags->integer() & ~$known) !== 0) {
            throw new \LogicException('This native signature parser cannot honor unknown or reserved seed flags.');
        }
        return $flags->integer();
    }

    private function type(DictionaryType $dictionary, string $expected): void
    {
        $type = $this->graph->resolve($dictionary->get('Type'));
        if ($type !== null && (!$type instanceof NameType || $type->value !== $expected)) {
            throw new \InvalidArgumentException('The frozen signature policy dictionary has an incorrect Type.');
        }
    }

    private function text(mixed $value): TextString
    {
        if (!$value instanceof StringType) {
            throw new \InvalidArgumentException('A signature policy text is a PDF text string.');
        }
        return new TextString((new TextStringDecoder())->decode($value));
    }

    private function texts(mixed $value): ArrayType
    {
        if (!$value instanceof ArrayType) {
            throw new \InvalidArgumentException('Signature policy text choices are an array.');
        }
        return new ArrayType(...array_map(fn ($item): TextString => $this->text($this->graph->resolve($item)), $value->items));
    }
}
