<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\Definition\SignatureFieldTable232Definition;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** An unsigned or explicitly supplied signature container; CMS/ByteRange signing is separate. */
final class SignatureField extends TerminalField
{
    public function __construct(?PartialFieldName $name = null, ?CommonFieldFlags $flags = null, ?SignatureFieldLock $lock = null, ?IndirectReference $value = null, ?IndirectReference $seedValue = null, FieldTypeDeclaration $declaration = FieldTypeDeclaration::Explicit, Entry ...$entries)
    {
        $managed = [];
        foreach (['Lock' => $lock === null ? null : new IndirectReference($lock), 'V' => $value, 'SV' => $seedValue] as $key => $item) {
            if ($item !== null) {
                $managed[] = new Entry(new NameType($key), $item);
            }
        }
        parent::__construct(new SignatureFieldTable232Definition(), $name, FieldType::Signature, $declaration, $flags, ...[...$managed, ...$entries]);
    }

    public function validate(): void
    {
        parent::validate();
        if (($this->flags() & ~7) !== 0) {
            throw new \InvalidArgumentException('Signature fields use the common field flags.');
        }
        $value = $this->effective('V');
        if ($value !== null && (!$value instanceof IndirectReference || !$value->target() instanceof DictionaryType)) {
            throw new \InvalidArgumentException('A signature value must reference its signature dictionary.');
        }
        $lock = $this->get('Lock');
        if ($lock instanceof IndirectReference && $lock->target() instanceof SignatureFieldLock) {
            $lock->target()->validate();
        }
        $seed = $this->get('SV');
        if ($seed !== null && (!$seed instanceof IndirectReference || !$seed->target() instanceof DictionaryType)) {
            throw new \InvalidArgumentException('Signature seed values must reference a dictionary.');
        }
    }
}
