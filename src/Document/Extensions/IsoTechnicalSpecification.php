<?php

declare(strict_types=1);

namespace PDF\Document\Extensions;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;

/** Extension declarations specified by the respective technical specification, Table 1. */
enum IsoTechnicalSpecification: int
{
    case HashAlgorithms = 32001;
    case DigitalSignatures = 32002;
    case AesGcm = 32003;
    case IntegrityProtection = 32004;

    public function dictionary(): DictionaryType
    {
        $year = match ($this) {
            self::HashAlgorithms, self::DigitalSignatures => 2022,
            self::AesGcm => 2023,
            self::IntegrityProtection => 2024,
        };
        $identifier = match ($this) {
            self::HashAlgorithms => 45874,
            self::DigitalSignatures => 45875,
            self::AesGcm => 45876,
            self::IntegrityProtection => 45877,
        };
        return new DictionaryType(
            new Entry(new NameType('Type'), new NameType('DeveloperExtensions')),
            new Entry(new NameType('BaseVersion'), new NameType('2.0')),
            new Entry(new NameType('ExtensionLevel'), new IntegerType($this->value)),
            new Entry(new NameType('ExtensionRevision'), new LiteralString(':' . $year)),
            new Entry(new NameType('URL'), new LiteralString('https://www.iso.org/standard/' . $identifier . '.html')),
        );
    }

    public function validate(DictionaryType $catalog): void
    {
        $extensions = $catalog->get('Extensions');
        $iso = $extensions instanceof DictionaryType ? $extensions->get('ISO_') : null;
        if ($iso instanceof DictionaryType && in_array($this, [self::AesGcm, self::IntegrityProtection], true)) {
            throw new \LogicException('This ISO technical specification requires an array entry under the ISO_ prefix.');
        }
        $declarations = $iso instanceof ArrayType ? $iso->items : ($iso instanceof DictionaryType ? [$iso] : []);
        foreach ($declarations as $declaration) {
            if (!$declaration instanceof DictionaryType) {
                continue;
            }
            $level = $declaration->get('ExtensionLevel');
            if ($level instanceof IntegerType && $level->value === $this->value) {
                foreach ($this->dictionary()->entries() as $expected) {
                    $actual = $declaration->get($expected->key);
                    $wanted = $expected->value;
                    if (($wanted instanceof NameType && (!$actual instanceof NameType || $actual->value !== $wanted->value))
                        || ($wanted instanceof StringType && (!$actual instanceof StringType || $actual->value !== $wanted->value))) {
                        throw new \LogicException('The ISO extension declaration has an invalid /' . $expected->key->value . ' value.');
                    }
                }
                return;
            }
        }
        throw new \LogicException('The catalog requires the ISO_ extension declaration for ISO/TS ' . $this->value . '.');
    }
}
