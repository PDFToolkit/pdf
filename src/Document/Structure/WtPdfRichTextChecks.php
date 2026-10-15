<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** WTPDF 8.10.3.3, physical page 46: executable plain/rich textual equivalence. */
final readonly class WtPdfRichTextChecks
{
    public function __construct(private WtPdfRichTextParser $parser, private int $maximumBytes = 1048576, private int $maximumParents = 100)
    {
        if ($maximumBytes < 1 || $maximumParents < 1) {
            throw new \InvalidArgumentException('Rich-text inspection requires a positive byte budget.');
        }
    }

    /** @return list<WtPdfFinding> */
    public function inspect(DictionaryType $field): array
    {
        $type = $this->inherited($field, 'FT');
        if (!$type instanceof NameType || $type->value !== 'Tx' || !$field->has('RV')) {
            return [];
        }
        $plain = $this->inherited($field, 'V');
        $rich = $this->value($field->get('RV'));
        $fail = static fn (string $message): array => [new WtPdfFinding('8.10.3.3', 46, WtPdfFindingStatus::Failed, $message)];
        if (!$plain instanceof StringType) {
            return $fail('A rich-text field requires its canonical plain-text V value.');
        }
        $decoder = new TextStringDecoder(PdfVersion::PDF20);
        if ($rich instanceof StringType) {
            $xml = $decoder->decode($rich);
        } elseif ($rich instanceof StreamObject && is_string($rich->data) && $rich->filters === []) {
            $xml = $rich->data;
        } else {
            return [new WtPdfFinding('8.10.3.3', 46, WtPdfFindingStatus::AdditionalValidationRequired, 'Normalize the rich-text stream to decoded XML before checking RV/V equivalence.')];
        }
        if (strlen($xml) > $this->maximumBytes || strlen($plain->value) > $this->maximumBytes) {
            throw new \OverflowException('Rich/plain field values exceed their explicit byte budget.');
        }
        try {
            $text = $this->parser->plainText($xml);
        } catch (WtPdfRichTextUnsupported $error) {
            return [new WtPdfFinding('8.10.3.3', 46, WtPdfFindingStatus::AdditionalValidationRequired, $error->getMessage())];
        } catch (\InvalidArgumentException $error) {
            return $fail($error->getMessage());
        }
        $expected = str_replace(["\r\n", "\r"], "\n", $decoder->decode($plain));
        return $text === $expected ? [] : $fail('RV rich text and V plain text are not textually equivalent.');
    }

    private function value(?BasicType $value): ?BasicType
    {
        $value = $value instanceof IndirectReference ? $value->target() : $value;
        return $value instanceof NullType ? null : $value;
    }

    private function inherited(DictionaryType $field, string $name): ?BasicType
    {
        $seen = new \SplObjectStorage();
        for ($node = $field; ;) {
            if ($seen->offsetExists($node) || count($seen) >= $this->maximumParents) {
                throw new \InvalidArgumentException('Rich-text inheritance requires a bounded, acyclic field parent chain.');
            }
            $seen->attach($node);
            $value = $this->value($node->get($name));
            if ($value !== null) {
                return $value;
            }
            $parent = $this->value($node->get('Parent'));
            if (!$parent instanceof DictionaryType) {
                return null;
            }
            $node = $parent;
        }
    }
}
