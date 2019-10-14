<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.2393749
 */
interface Entry
{
    public const TYPE = 'Type';
    public const VERSION = 'Version';
    public const EXTENSIONS = 'Extensions';
    public const PAGES = 'Pages';
    public const PAGE_LABELS = 'labelling  range';
    public const NAMES = 'Names';
    public const DESTS = 'destinations';
    public const VIEWER_PREFERENCES = 'ViewerPreferences';
    public const PAGE_LAYOUT = 'PageLayout';
    public const PAGE_MODE = 'Pages';
    public const OUTLINES = 'outline hierarchy';
    public const THREADS = 'Pages';
    public const OPEN_ACTION = 'Pages';
    public const AA = 'Pages';
    public const URI = 'Pages';
    public const ACRO_FORM = 'Pages';
    public const METADATA = 'Pages';
    public const STRUCT_TREE_ROOT = 'Pages';
    public const MARK_INFO = 'Pages';
    public const LANG = 'Pages';
    public const SPIDER_INFO = 'Pages';
    public const OUTPUT_INTENTS = 'Pages';
    public const PIECE_INFO = 'Pages';
    public const OC_PROPERTIES = 'Pages';
    public const PERMS = 'Pages';
    public const LEGAL = 'Pages';
    public const REQUIREMENTS = 'Pages';
    public const COLLECTION = 'Pages';
    public const NEEDS_RENDERING = 'Pages';
}
