<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\StringClass;

/**
 * The first line of a PDF file shall be a header consisting of the 5
 * characters %PDF– followed by a version number of the form 1.N,
 * where N is a digit between 0 and 7.
 *
 * A conforming reader shall accept files with any of the following headers:
 * - %PDF–1.0
 * - %PDF–1.1
 * - %PDF–1.2
 * - %PDF–1.3
 * - %PDF–1.4
 * - %PDF–1.5
 * - %PDF–1.6
 * - %PDF–1.7
 *
 * Beginning with PDF 1.4, the Version entry in the document’s catalog dictionary
 * (located via the Root entry in the file’s trailer, as described in 7.5.5, "File Trailer"),
 * if present, shall be used instead of the version specified in the Header.
 *
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1843918
 */
class FileHeader implements StringClass
{
    /** @var float */
    private $version;

    public function __construct(float $version = 1.4)
    {
        $this->version = $version;
    }

    public function __toString(): string
    {
        return sprintf('%%PDF–%s', $this->version);
    }
}
