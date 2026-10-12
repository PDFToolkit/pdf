<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\Object\NameType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentStreamScopeTest extends TestCase
{
    public function testProperlyNestedTextAndMarkedContentScopesAreAccepted(): void
    {
        $content = new ContentStreams(
            new BeginMarkedContent(new NameType('P')),
            new BeginTextObject(),
            new BeginMarkedContent(new NameType('Span')),
            new EndMarkedContent(),
            new EndTextObject(),
            new EndMarkedContent(),
        );
        self::assertSame("/P BMC\nBT\n/Span BMC\nEMC\nET\nEMC\n", (string) $content);
    }

    #[DataProvider('crossedScopes')]
    public function testScopesCannotCross(ContentStreams $content): void
    {
        $this->expectException(\LogicException::class);
        $content->validate();
    }

    /** @return iterable<string, array{ContentStreams}> */
    public static function crossedScopes(): iterable
    {
        yield 'marked content closes inside text' => [new ContentStreams(
            new BeginMarkedContent(new NameType('P')),
            new BeginTextObject(),
            new EndMarkedContent(),
            new EndTextObject(),
        )];
        yield 'text closes inside marked content' => [new ContentStreams(
            new BeginTextObject(),
            new BeginMarkedContent(new NameType('P')),
            new EndTextObject(),
            new EndMarkedContent(),
        )];
    }
}
