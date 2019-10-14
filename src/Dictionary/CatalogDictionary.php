<?php

declare(strict_types=1);

namespace PDF\Dictionary;

use PDF\StringClass;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.2393749
 */
class CatalogDictionary implements Dictionary, StringClass
{
    /**
     * @var string
     */
    public $type;

    /**
     * @var string
     */
    public $version;

    /**
     * @var Dictionary
     */
    public $extensions;

    /**
     * @var Dictionary
     */
    public $pages;

    /**
     * @var NumberTree
     */
    public $pageLabels;

    /**
     * @var Dictionary
     */
    public $names;

    /**
     * @var Dictionary
     */
    public $dests;

    /**
     * @var Dictionary
     */
    public $viewerPreferences;

    /**
     * @var string
     */
    public $pageLayout;

    /**
     * @var string
     */
    public $pageMode;

    /**
     * @var Dictionary
     */
    public $outlines;

    /**
     * @var []
     */
    public $threads;

    /**
     * @var Dictionary[]|[]
     */
    public $openAction;

    /**
     * @var Dictionary[]
     */
    public $AA;

    /**
     * @var string
     */
    public $URI;

    /**
     * @var string
     */
    public $acroForm;

    /**
     * @var string
     */
    public $metadata;

    /**
     * @var string
     */
    public $structTreeRoot;

    /**
     * @var string
     */
    public $markInfo;

    /**
     * @var string
     */
    public $lang;

    /**
     * @var string
     */
    public $spiderInfo;

    /**
     * @var string
     */
    public $outputIntents;

    /**
     * @var string
     */
    public $pieceInfo;

    /**
     * @var string
     */
    public $oCProperties;

    /**
     * @var string
     */
    public $perms;

    /**
     * @var string
     */
    public $legal;

    /**
     * @var string
     */
    public $requirements;

    /**
     * @var string
     */
    public $collection;

    /**
     * @var string
     */
    public $needsRendering;

    public function __construct()
    {
        
    }

    public function __toString(): string
    {
        
    }
}