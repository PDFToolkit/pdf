<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\ContentStreamsResources\Operators\MarkedContentPointWithProperties;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Native AF instructions frozen before emission, including other permitted meanings of the AF tag. */
final readonly class AssociatedFilesMarkedContentFacts
{
    /** @var list<AssociatedFilesMarkedContentUse> */
    public array $uses;
    public ContentStreams $content;

    public function __construct(ContentStreams $content)
    {
        $uses = [];
        $operators = [];
        $replaced = false;
        foreach ($content->operators as $operator) {
            $symbol = $operator->symbol();
            $tag = $operator->operands[0] ?? null;
            if (($operator instanceof BeginAssociatedFilesMarkedContent || $operator instanceof BeginMarkedContentWithProperties || $operator instanceof MarkedContentPointWithProperties) && $tag instanceof NameType && $tag->value === 'AF') {
                $properties = $operator->operands[1] ?? null;
                if (!$properties instanceof NameType && !$properties instanceof DictionaryType) {
                    throw new \InvalidArgumentException('An AF property list must be a dictionary or a resource name.');
                }
                $use = new AssociatedFilesMarkedContentUse($symbol, $properties, $operator instanceof BeginAssociatedFilesMarkedContent);
                $uses[] = $use;
                if ($properties instanceof DictionaryType) {
                    // These exact frozen operands produce both the emission and its retained facts.
                    $operator = $symbol === 'BDC'
                        ? new BeginMarkedContentWithProperties($tag, $use->properties)
                        : new MarkedContentPointWithProperties($tag, $use->properties);
                    $replaced = true;
                }
            }
            $operators[] = $operator;
        }
        $this->uses = $uses;
        $type = $content::class;
        $this->content = $replaced ? new $type(...$operators) : $content;
    }

    public function declaresAssociation(): bool
    {
        foreach ($this->uses as $use) {
            if ($use->declared) {
                return true;
            }
            if ($use->properties instanceof DictionaryType) {
                $files = $use->properties->get('MCAF');
                if ($files !== null && !$files instanceof NullType) {
                    return true;
                }
            }
        }
        return false;
    }
}
