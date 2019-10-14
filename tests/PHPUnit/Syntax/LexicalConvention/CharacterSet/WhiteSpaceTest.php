<?php

declare(strict_types=1);

namespace PHPUnit\Syntax\LexicalConvention\CharacterSet;

use PDF\Syntax\LexicalConvention\CharacterSet\WhiteSpace;
use PHPUnit\Framework\TestCase;

class WhiteSpaceTest extends TestCase
{
    /**
     * @dataProvider tokenProvider
     */
    public function test_whitespace_characters($expected, $token)
    {
        $this->assertEquals($expected, $token);
    }

    public function tokenProvider()
    {
        return [
            'HORIZONTAL TAB' => ["\t", WhiteSpace::HORIZONTAL_TAB()],
            'HORIZONTAL TAB (HT)' => ["\t", WhiteSpace::HT()],
            'LINE FEED' => ["\n", WhiteSpace::LINE_FEED()],
            'LINE FEED (LF)' => ["\n", WhiteSpace::LINE_FEED()],
            'FORM FEED' => [chr(12), WhiteSpace::FORM_FEED()],
            'FORM FEED (FF)' => [chr(12), WhiteSpace::FF()],
            'CARRIAGE RETURN' => ["\r", WhiteSpace::CARRIAGE_RETURN()],
            'CARRIAGE RETURN (CR)' => ["\r", WhiteSpace::CR()],
            'SPACE' => [' ', WhiteSpace::SPACE()],
            'SPACE (SP)' => [' ', WhiteSpace::SP()],
            'EOF' => ["\r\n", WhiteSpace::EOF()],
        ];
    }
}
