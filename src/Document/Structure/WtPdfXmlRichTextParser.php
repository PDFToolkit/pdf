<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** Bounded XML rich text, with no DTD, entity expansion, external resources or parser-global state. */
final readonly class WtPdfXmlRichTextParser implements WtPdfRichTextParser
{
    public function __construct(private int $maximumBytes = 1048576, private int $maximumNodes = 100000, private int $maximumDepth = 100)
    {
        if (min($maximumBytes, $maximumNodes, $maximumDepth) < 1) {
            throw new \InvalidArgumentException('Rich-text parsing requires explicit positive budgets.');
        }
    }

    public function plainText(string $xml): string
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new \LogicException('The explicitly selected XML rich-text backend requires ext-dom.');
        }
        if (strlen($xml) > $this->maximumBytes) {
            throw new \OverflowException('Rich-text bytes exceed their explicit budget.');
        }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new \InvalidArgumentException('Rich text must not declare a DTD or entities.');
        }
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $document->doctype !== null) {
            throw new \InvalidArgumentException('Rich text is not well-formed, DTD-free XML.');
        }
        $root = $document->documentElement;
        if ($root === null || $root->localName !== 'body') {
            throw new \InvalidArgumentException('PDF rich text requires an XHTML body root.');
        }
        $nodes = 0;
        $text = $this->read($root, 0, $nodes);
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function read(\DOMNode $node, int $depth, int &$nodes): string
    {
        if (++$nodes > $this->maximumNodes || $depth > $this->maximumDepth) {
            throw new \OverflowException('Rich-text XML exceeds its explicit structural budget.');
        }
        if ($node instanceof \DOMText) {
            return $node->nodeValue ?? '';
        }
        if ($node instanceof \DOMComment) {
            return '';
        }
        if (!$node instanceof \DOMElement || !in_array($node->namespaceURI, [null, '', 'http://www.w3.org/1999/xhtml'], true) || !in_array($node->localName, ['body', 'p', 'span', 'b', 'i', 'strong', 'em', 'sub', 'sup', 'br'], true)) {
            throw new WtPdfRichTextUnsupported('Unsupported rich-text node or namespace; equivalence cannot be inferred.');
        }
        if ($node->hasAttribute('style') && preg_match('/(?:^|;)\s*(?:display|visibility|white-space|text-transform|content|direction|unicode-bidi)\s*:/i', $node->getAttribute('style')) === 1) {
            throw new WtPdfRichTextUnsupported('Rich-text CSS with textual or visibility effects requires an explicit richer parser.');
        }
        foreach ($node->attributes as $attribute) {
            if (str_starts_with(strtolower($attribute->nodeName), 'on') || in_array(strtolower($attribute->localName ?? $attribute->nodeName), ['src', 'href'], true)) {
                throw new \InvalidArgumentException('Active or external rich-text content is forbidden in the native equivalence backend.');
            }
        }
        if ($node->localName === 'br') {
            return "\n";
        }
        $text = '';
        $previousParagraph = false;
        foreach ($node->childNodes as $child) {
            if ($node->localName === 'body' && $child instanceof \DOMText && trim($child->nodeValue ?? '') === '') {
                // XML formatting between paragraphs is not an additional displayed paragraph.
                continue;
            }
            $paragraph = $child instanceof \DOMElement && $child->localName === 'p';
            if ($paragraph && $previousParagraph) {
                $text .= "\n";
            }
            $text .= $this->read($child, $depth + 1, $nodes);
            $previousParagraph = $paragraph;
        }
        return $text;
    }
}
