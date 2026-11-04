<?php declare(strict_types=1);

namespace Nova\Search\Tests\Unit;

use Nova\Search\Service\TrafficLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrafficLimiterTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function keys(): iterable
    {
        yield 'IPv4 unverändert' => ['192.0.2.17', '192.0.2.17'];
        yield 'IPv6 auf /64' => ['2001:db8:1234:5678:9abc:def0:1:2', '2001:db8:1234:5678::/64'];
        yield 'IPv6 kurz geschrieben' => ['2001:db8::1', '2001:db8::/64'];
        yield 'IPv4-gemappt' => ['::ffff:192.0.2.17', '192.0.2.17'];
        yield 'leer' => ['', 'unknown'];
        yield 'null' => [null, 'unknown'];
        yield 'ungültig bleibt' => ['nicht-ip', 'nicht-ip'];
    }

    #[DataProvider('keys')]
    public function testClientKey(?string $ip, string $expected): void
    {
        self::assertSame($expected, TrafficLimiter::clientKey($ip));
    }

    public function testAddressesOfOneNetworkShareABudget(): void
    {
        self::assertSame(
            TrafficLimiter::clientKey('2001:db8:aa:bb:1::1'),
            TrafficLimiter::clientKey('2001:db8:aa:bb:ffff:ffff:ffff:ffff')
        );
        self::assertNotSame(
            TrafficLimiter::clientKey('2001:db8:aa:bb::1'),
            TrafficLimiter::clientKey('2001:db8:aa:bc::1')
        );
    }

    public function testUpperCaseIsNormalized(): void
    {
        self::assertSame(TrafficLimiter::clientKey('2001:DB8::ABCD'), TrafficLimiter::clientKey('2001:db8::abcd'));
    }
}
