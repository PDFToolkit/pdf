<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

final readonly class RedactionPlan
{
    /** @var non-empty-list<RedactionPageRequest> */
    public array $pages;

    public function __construct(RedactionPageRequest $first, RedactionPageRequest ...$rest)
    {
        $pages = [$first, ...array_values($rest)];
        $seen = [];
        foreach ($pages as $page) {
            $key = spl_object_id($page->page);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Combine a page’s redaction annotations in one request.');
            }
            $seen[$key] = true;
        }
        $this->pages = $pages;
    }
}
