<?php

declare(strict_types=1);

namespace PHPUnit;

use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Pages;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\StreamObject;
use PDF\Text\Font;
use PDF\Text\TextObject;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * @throws \PDF\Exception\AlreadyExistsException
     */
    public function testASimplePDF()
    {
        die(
        var_dump(
            sprintf(
                '%s',
                new FileStructure(
                    new FileHeader(),
                    (new FileBody())
                    ->addObject(
                        (new PageTreeNode)->addKid(
                            (new PageObject())
                            ->setmediaBox(new Rectangle(0, 0, 612, 792))
                            ->setContents(new ContentStreams(new StreamObject(new TextObject('Super !', new Font(Font::HELVETICA, 12)))))
                        )
                    )
/**
->addObject(
(new PageTreeNode())
->addKid(new PageObject())
->addKid(new PageObject())
)
->addObject(
(new PageTreeNode())
->addKid(
(new PageTreeNode())
->addKid(new PageObject())

)
)
 */
                )
            )
        )
        );
    }
}
