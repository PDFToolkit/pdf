<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\Definition\TextFieldTable229Definition;
use PDF\Specification\TableDefinition;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NullType;

/** Text-field construction and intrinsic flag/value constraints, ISO pp.450–452. */
class TextField extends TerminalField
{
    public function __construct(?PartialFieldName $name = null, ?TextFieldFlags $flags = null, TextString|FieldTextStream|NullType|null $value = null, ?IntegerType $maximumLength = null, FieldTypeDeclaration $declaration = FieldTypeDeclaration::Explicit, Entry ...$entries)
    {
        $managed = [];
        if ($value !== null) {
            $managed[] = new Entry(new NameType('V'), $value instanceof FieldTextStream ? new IndirectReference($value) : $value);
        }
        if ($maximumLength !== null) {
            $managed[] = new Entry(new NameType('MaxLen'), $maximumLength);
        }
        parent::__construct($this->textDefinition(), $name, FieldType::Text, $declaration, $flags, ...[...$managed, ...$entries]);
    }

    protected function textDefinition(): TableDefinition
    {
        return new TextFieldTable229Definition();
    }

    public function validate(): void
    {
        parent::validate();
        $flags = $this->flags();
        $mask = 7;
        foreach (TextFieldFlag::cases() as $flag) {
            $mask |= $flag->value;
        }
        if (($flags & ~$mask) !== 0) {
            throw new \InvalidArgumentException('Text flags contain bits defined for another field type.');
        }
        $maximum = $this->effective('MaxLen');
        if ($maximum !== null && (!$maximum instanceof IntegerType || $maximum->value < 0)) {
            throw new \InvalidArgumentException('Text maximum length must be nonnegative.');
        }
        if (($flags & TextFieldFlag::Comb->value) !== 0 && (!$maximum instanceof IntegerType || $maximum->value < 1 || ($flags & (TextFieldFlag::Multiline->value | TextFieldFlag::Password->value | TextFieldFlag::FileSelect->value)) !== 0)) {
            throw new \InvalidArgumentException('Comb requires positive MaxLen and excludes Multiline, Password and FileSelect.');
        }
        foreach (['V', 'DV'] as $key) {
            $value = $this->effective($key);
            if ($value instanceof IndirectReference && $value->target() instanceof FieldTextStream) {
                $value = $value->target()->text;
            }
            if ($value !== null && !$value instanceof TextString) {
                throw new \InvalidArgumentException('Text field values must be explicitly encoded text strings.');
            }
            if ($value instanceof TextString && $maximum instanceof IntegerType && preg_match_all('/./us', $value->text) > $maximum->value) {
                throw new \InvalidArgumentException('Text field value exceeds MaxLen in Unicode characters.');
            }
        }
        if (($flags & TextFieldFlag::RichText->value) !== 0 && $this->effective('V') !== null) {
            $rich = $this->get('RV');
            $rich = $rich instanceof \PDF\Syntax\Object\IndirectReference ? $rich->target() : $rich;
            if (!$rich instanceof StringType && !$rich instanceof StreamObject) {
                throw new \InvalidArgumentException('A valued RichText field requires an explicit RV XML string or text stream.');
            }
        }
    }
}
