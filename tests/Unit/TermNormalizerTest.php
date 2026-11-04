<?php declare(strict_types=1);

namespace Nova\Search\Tests\Unit;

use Nova\Search\Service\EventRecorder;
use Nova\Search\Service\TermNormalizer;
use PHPUnit\Framework\TestCase;

final class TermNormalizerTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('bremse vorne', TermNormalizer::normalize("  BREMSE \t vorne "));
        self::assertSame(TermNormalizer::MAX_LENGTH, mb_strlen(TermNormalizer::normalize(str_repeat('a', 300))));
    }

    public function testTrackable(): void
    {
        self::assertTrue(TermNormalizer::isTrackable('vergaser'));
        self::assertTrue(TermNormalizer::isTrackable('2cv'));
        self::assertFalse(TermNormalizer::isTrackable('a'));
        self::assertFalse(TermNormalizer::isTrackable('kunde@example.com'));
        self::assertFalse(TermNormalizer::isTrackable('https://example.com'));
        self::assertFalse(TermNormalizer::isTrackable('0170 1234567'));
        self::assertFalse(TermNormalizer::isTrackable('!!!'));
        self::assertFalse(TermNormalizer::isTrackable(str_repeat('a', 61)));
    }

    public function testLikelyCorrection(): void
    {
        self::assertTrue(EventRecorder::isLikelyCorrection('vergser', 'vergaser'));
        self::assertFalse(EventRecorder::isLikelyCorrection('bremse', 'auspuff'));
        // Weitertippen ist keine Korrektur
        self::assertFalse(EventRecorder::isLikelyCorrection('verga', 'vergaser'));
        // andere Zahlen = anderes Teil
        self::assertFalse(EventRecorder::isLikelyCorrection('spiegel t2', 'spiegel t3'));
    }
}
