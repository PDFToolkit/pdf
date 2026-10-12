<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

/** Common base for catalog entries, with the exact PDF keys from table 28. */
abstract class Entry extends \PDF\Syntax\Object\DictionaryObject\Entry
{
    public const TYPE = 'Type';
    public const VERSION = 'Version';
    public const EXTENSIONS = 'Extensions';
    public const PAGES = 'Pages';
    public const PAGE_LABELS = 'PageLabels';
    public const NAMES = 'Names';
    public const DESTS = 'Dests';
    public const VIEWER_PREFERENCES = 'ViewerPreferences';
    public const PAGE_LAYOUT = 'PageLayout';
    public const PAGE_MODE = 'PageMode';
    public const OUTLINES = 'Outlines';
    public const THREADS = 'Threads';
    public const OPEN_ACTION = 'OpenAction';
    public const AA = 'AA';
    public const URI = 'URI';
    public const ACRO_FORM = 'AcroForm';
    public const METADATA = 'Metadata';
    public const STRUCT_TREE_ROOT = 'StructTreeRoot';
    public const MARK_INFO = 'MarkInfo';
    public const LANG = 'Lang';
    public const SPIDER_INFO = 'SpiderInfo';
    public const OUTPUT_INTENTS = 'OutputIntents';
    public const PIECE_INFO = 'PieceInfo';
    public const OC_PROPERTIES = 'OCProperties';
    public const PERMS = 'Perms';
    public const LEGAL = 'Legal';
    public const REQUIREMENTS = 'Requirements';
    public const COLLECTION = 'Collection';
    public const NEEDS_RENDERING = 'NeedsRendering';
}
