<?php

declare(strict_types=1);

namespace PDF\Document\RichMedia;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;

/** A named RichMedia asset's relative URI and F/UF binding; ISO2 13.7.2.3.2, p724. */
final readonly class RichMediaAssetName
{
    public string $text;

    public function __construct(string $encoded)
    {
        $this->text = (new TextStringDecoder(PdfVersion::PDF20))->decode(new LiteralString($encoded));
        if (preg_match('~^[A-Za-z0-9._\\~!$&\'()+,;=/?#@%\[\]-]{1,255}$~D', $this->text) !== 1
            || str_ends_with($this->text, '.') || preg_match('/%(?![0-9A-Fa-f]{2})/', $this->text) !== 0
            || substr_count($this->text, '#') > 1) {
            throw new \InvalidArgumentException('A RichMedia asset name requires a 1–255 character relative URI, valid escapes, no forbidden filename characters and no trailing dot.');
        }
    }

    public function validate(DictionaryType $file, DocumentGraphContext $graph): void
    {
        foreach (['F', 'UF'] as $key) {
            $entry = $graph->entry($file, $key);
            $value = $entry === null ? null : $graph->resolve($entry);
            if (!$value instanceof StringType || (new TextStringDecoder(PdfVersion::PDF20))->decode($value) !== $this->text) {
                throw new \InvalidArgumentException('A named RichMedia asset requires matching name-tree, F and UF text strings.');
            }
        }
    }
}
