<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\NameType;

/** ISO 32000-1, 14.8.4, tables 333–340, PDF pages 591–600. */
enum StandardStructureType: string
{
    case Document = 'Document';
    case Part = 'Part';
    case Art = 'Art';
    case Sect = 'Sect';
    case Div = 'Div';
    case BlockQuote = 'BlockQuote';
    case Caption = 'Caption';
    case TOC = 'TOC';
    case TOCI = 'TOCI';
    case Index = 'Index';
    case NonStruct = 'NonStruct';
    case Private = 'Private';
    case P = 'P';
    case H = 'H';
    case H1 = 'H1';
    case H2 = 'H2';
    case H3 = 'H3';
    case H4 = 'H4';
    case H5 = 'H5';
    case H6 = 'H6';
    case L = 'L';
    case LI = 'LI';
    case Lbl = 'Lbl';
    case LBody = 'LBody';
    case Table = 'Table';
    case TR = 'TR';
    case TH = 'TH';
    case TD = 'TD';
    case THead = 'THead';
    case TBody = 'TBody';
    case TFoot = 'TFoot';
    case Span = 'Span';
    case Quote = 'Quote';
    case Note = 'Note';
    case Reference = 'Reference';
    case BibEntry = 'BibEntry';
    case Code = 'Code';
    case Link = 'Link';
    case Annot = 'Annot';
    case Ruby = 'Ruby';
    case RB = 'RB';
    case RT = 'RT';
    case RP = 'RP';
    case Warichu = 'Warichu';
    case WT = 'WT';
    case WP = 'WP';
    case Figure = 'Figure';
    case Formula = 'Formula';
    case Form = 'Form';

    public function name(): NameType
    {
        return new NameType($this->value);
    }

    public function grouping(): bool
    {
        return in_array($this, [self::Document, self::Part, self::Art, self::Sect, self::Div, self::BlockQuote, self::Caption, self::TOC, self::TOCI, self::Index, self::NonStruct, self::Private], true);
    }
}
