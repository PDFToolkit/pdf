<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\ParentClass;
use PDF\StringClass;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/**
 * PDF includes eight basic types of objects:
 * - Boolean values
 * - Integer and Real numbers
 * - Strings
 * - Names
 * - Arrays
 * - Dictionaries
 * - Streams
 * - and the null object
 *
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1953078
 */
interface BasicType extends StringClass, ParentClass
{
}
