<?php declare(strict_types=1);

namespace Nova\Search\Tests\Unit;

use Nova\Search\Service\FeedbackText;
use PHPUnit\Framework\TestCase;

final class FeedbackTextTest extends TestCase
{
    public function testCleanRemovesControlAndFormatCharacters(): void
    {
        self::assertSame('Vergaser fehlt', FeedbackText::clean("  Vergaser\r\n\tfehlt\u{202E}\0 "));
        self::assertSame('a b', FeedbackText::clean("a\u{2028}b"));
    }

    public function testCleanRejectsEmptyAndInvalid(): void
    {
        self::assertNull(FeedbackText::clean(''));
        self::assertNull(FeedbackText::clean("   \n "));
        self::assertNull(FeedbackText::clean(null));
        self::assertNull(FeedbackText::clean(['x']));
        self::assertNull(FeedbackText::clean("\xC3\x28"));
        self::assertNull(FeedbackText::clean(str_repeat('a', FeedbackText::MAX_LENGTH * 8 + 1)));
    }

    public function testCleanCutsToMaxLength(): void
    {
        $text = FeedbackText::clean(str_repeat('ä', 500));

        self::assertNotNull($text);
        self::assertSame(FeedbackText::MAX_LENGTH, mb_strlen($text));
    }

    public function testHtmlIsKeptAsText(): void
    {
        self::assertSame('<b>x</b>', FeedbackText::clean('<b>x</b>'));
    }

    public function testRatingAndSource(): void
    {
        self::assertSame('down', FeedbackText::rating('down'));
        self::assertNull(FeedbackText::rating('DOWN'));
        self::assertNull(FeedbackText::rating(1));
        self::assertSame('app', FeedbackText::source('app', [FeedbackText::SOURCE_APP]));
        self::assertNull(FeedbackText::source('page', [FeedbackText::SOURCE_APP]));
    }
}
