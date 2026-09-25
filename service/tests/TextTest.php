<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Util\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testSplitBytesRespectsLimitAndWords(): void
    {
        $text = str_repeat('palabra ñandú ', 40);
        $parts = Text::splitBytes($text, 50);
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(50, strlen($p));
            $this->assertTrue(mb_check_encoding($p, 'UTF-8'));
        }
        $this->assertSame(trim(preg_replace('/\s+/', ' ', $text)), implode(' ', $parts));
    }

    public function testSplitBytesCutsHugeWordsSafely(): void
    {
        $parts = Text::splitBytes(str_repeat('ñ', 100), 15);
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(15, strlen($p));
            $this->assertTrue(mb_check_encoding($p, 'UTF-8'));
        }
    }

    public function testParseAmount(): void
    {
        $this->assertSame(500, Text::parseAmount('500'));
        $this->assertSame(1500, Text::parseAmount('1.500'));
        $this->assertSame(2000, Text::parseAmount('2k'));
        $this->assertSame(1500, Text::parseAmount('1.5k'));
        $this->assertSame(1000000, Text::parseAmount('1m'));
        $this->assertNull(Text::parseAmount('-5'));
        $this->assertNull(Text::parseAmount('0'));
        $this->assertNull(Text::parseAmount('abc'));
    }

    public function testParseWhen(): void
    {
        $now = (new \DateTimeImmutable('2026-09-25 10:00:00', new \DateTimeZone('America/Montevideo')))->getTimestamp();
        $this->assertSame([$now + 1800, 1], Text::parseWhen(['30m', 'x'], $now));
        $this->assertSame([$now + 9000, 1], Text::parseWhen(['2h30m'], $now));
        $this->assertSame([$now + 86400, 1], Text::parseWhen(['1d'], $now));
        $xmas = (new \DateTimeImmutable('2026-12-25 18:00:00', new \DateTimeZone('America/Montevideo')))->getTimestamp();
        $this->assertSame([$xmas, 2], Text::parseWhen(['25/12', '18:00', 'regalo'], $now));
        $tomorrow9 = (new \DateTimeImmutable('2026-09-26 09:00:00', new \DateTimeZone('America/Montevideo')))->getTimestamp();
        $this->assertSame([$tomorrow9, 1], Text::parseWhen(['09:00'], $now));
        $this->assertNull(Text::parseWhen(['31/02'], $now));
        $this->assertNull(Text::parseWhen(['mañana'], $now));
    }

    public function testChatSafeRemovesEmojis(): void
    {
        $this->assertSame('hola ñandú ', Text::chatSafe('hola ñandú 😀'));
    }
}
