<?php declare(strict_types=1);

namespace Nova\Search\Tests\Unit;

use Nova\Search\Service\SimilarBuilder;
use PHPUnit\Framework\TestCase;

final class SimilarTest extends TestCase
{
    public function testGroupsByLeadingDigitsAndSortsBySales(): void
    {
        $rows = [
            ['id' => 'a', 'code' => '621 Bremsbacken', 'sales' => 5],
            ['id' => 'b', 'code' => '621', 'sales' => 20],
            ['id' => 'c', 'code' => '621 Bremsbacken hinten', 'sales' => 1],
            ['id' => 'd', 'code' => '622', 'sales' => 9],
            ['id' => 'e', 'code' => '', 'sales' => 3],
        ];
        $groups = SimilarBuilder::group($rows, 12);

        self::assertSame(['b', 'c'], $groups['a']);
        self::assertSame(['a', 'c'], $groups['b']);
        self::assertArrayNotHasKey('d', $groups, 'allein in seiner Teileart');
        self::assertArrayNotHasKey('e', $groups);
        self::assertSame(['b'], SimilarBuilder::group($rows, 1)['c']);
    }
}
