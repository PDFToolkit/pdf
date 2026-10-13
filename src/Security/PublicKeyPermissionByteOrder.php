<?php

declare(strict_types=1);

namespace PDF\Security;

/**
 * ISO2008 7.6.4.3 p.74 prints least-significant first. Most-significant first
 * follows the later ISO-approved correction: pdf-issues.pdfa.org/32000-2-2020/clause07.html, issue196.
 * The handler requires an explicit choice rather than silently changing the retained source wording.
 */
enum PublicKeyPermissionByteOrder: string
{
    case IsoLeastSignificantFirst = 'V';
    case MostSignificantFirst = 'N';
}
