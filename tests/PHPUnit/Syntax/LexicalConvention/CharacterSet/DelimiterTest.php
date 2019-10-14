<?php

declare(strict_types=1);

namespace PHPUnit\Syntax\LexicalConvention\CharacterSet;

use PDF\Syntax\LexicalConvention\CharacterSet\Delimiter;
use PHPUnit\Framework\TestCase;

class DelimiterTest extends TestCase
{
    /**
     * @dataProvider tokenProvider
     */
    public function test_delimiter_characters($expected, $token)
    {
        $this->assertEquals($expected, $token);
    }

    public function tokenProvider()
    {
        return [
            'LEFT_PARENTHESIS' => ['(', Delimiter::LEFT_PARENTHESIS()],
            'RIGHT_PARENTHESIS' => [')', Delimiter::RIGHT_PARENTHESIS()],
            'LESS_THAN_SIGN' => ['<', Delimiter::LESS_THAN_SIGN()],
            'GREATER_THAN_SIGN' => ['>', Delimiter::GREATER_THAN_SIGN()],
            'LEFT_SQUARE_BRACKET' => ['[', Delimiter::LEFT_SQUARE_BRACKET()],
            'RIGHT_SQUARE_BRACKET' => [']', Delimiter::RIGHT_SQUARE_BRACKET()],
            'LEFT_CURLY_BRACKET' => ['{', Delimiter::LEFT_CURLY_BRACKET()],
            'RIGHT_CURLY_BRACKET' => ['}', Delimiter::RIGHT_CURLY_BRACKET()],
            'SOLIDUS' => ['/', Delimiter::SOLIDUS()],
            'PERCENT_SIGN' => ['%', Delimiter::PERCENT_SIGN()],
        ];
    }
}
