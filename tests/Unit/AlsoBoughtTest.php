<?php declare(strict_types=1);

namespace Nova\Search\Tests\Unit;

use Nova\Search\Service\AlsoBoughtBuilder;
use PHPUnit\Framework\TestCase;

final class AlsoBoughtTest extends TestCase
{
    public function testRankSumsVariantsAndPicksMostBoughtVariant(): void
    {
        $rows = [
            ['source' => 'a', 'target_parent' => 'b', 'target' => 'b1', 'orders' => 1],
            ['source' => 'a', 'target_parent' => 'b', 'target' => 'b2', 'orders' => 3],
            ['source' => 'a', 'target_parent' => 'c', 'target' => 'c', 'orders' => 2],
            ['source' => 'a', 'target_parent' => 'd', 'target' => 'd', 'orders' => 1],
        ];

        self::assertSame(['a' => ['b2', 'c', 'd']], AlsoBoughtBuilder::rank($rows, 1, 12));
        self::assertSame(['a' => ['b2', 'c']], AlsoBoughtBuilder::rank($rows, 2, 12));
        self::assertSame(['a' => ['b2']], AlsoBoughtBuilder::rank($rows, 1, 1));
        self::assertSame([], AlsoBoughtBuilder::rank($rows, 5, 12));
    }
}
