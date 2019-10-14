<?php

declare(strict_types=1);

namespace PDF;

use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/**
 * A basic conforming PDF file shall be constructed of following four elements:
 * - A one-line header identifying the version of the PDF specification to which the file conforms
 * - A body containing the objects that make up the document contained in the file
 * - A cross-reference table containing information about the indirect objects in the file
 * - A trailer giving the location of the cross-reference table and of certain special objects within the body of the file
 *
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.2274524
 */
class FileStructure implements StringClass
{
    /** @var FileHeader */
    private $header;

    /** @var FileBody */
    private $body;

    public function __construct(FileHeader $header, FileBody $body)
    {
        $this->header = $header;
        $this->body = $body;
    }

    public function __toString(): string
    {
        return <<<PDF_FILE
{$this->header}
{$this->body}
PDF_FILE;
    }
}
