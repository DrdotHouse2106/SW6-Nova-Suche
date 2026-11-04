<?php declare(strict_types=1);

namespace Nova\Search\Tests\Unit;

use Nova\Search\Search\WordParts;
use PHPUnit\Framework\TestCase;

final class WordPartsTest extends TestCase
{
    public function testEligible(): void
    {
        self::assertTrue(WordParts::eligible('vergaser'));
        self::assertTrue(WordParts::eligible('türen'));
        self::assertFalse(WordParts::eligible('tür'));
        self::assertFalse(WordParts::eligible('2cv6a'));
    }

    public function testSplitCandidates(): void
    {
        $candidates = WordParts::splitCandidates('vergasersatz');

        self::assertSame(['vergaser', 'satz'], $candidates['vergaser']);
        self::assertSame(['verg', 'asersatz'], $candidates['verg']);
        // längster erster Teil zuerst
        self::assertSame('vergaser', array_key_first($candidates));
        // Rest mindestens 4 Zeichen
        self::assertArrayNotHasKey('vergasersa', $candidates);
    }

    public function testLinkingSIsRemoved(): void
    {
        $candidates = WordParts::splitCandidates('überholungssatz');

        self::assertSame(['überholung', 'satz'], $candidates['überholung']);
        self::assertSame(['überholungs', 'satz'], $candidates['überholungs']);
    }

    public function testNoSplitForShortWordsOrNumbers(): void
    {
        self::assertSame([], WordParts::splitCandidates('bremsen'));
        self::assertSame([], WordParts::splitCandidates('vergaser2cv'));
    }

    public function testCacheKeyIsPsr6Safe(): void
    {
        $key = WordParts::cacheKey("0123|60|vergaser{}()/\\@:");

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', $key);
        self::assertNotSame($key, WordParts::cacheKey('0123|60|vergase'));
    }
}
