<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Tests\Support\AppTestCase;

final class RemindersTest extends AppTestCase
{
    public function testBirthdayGiftIsPaidOncePerYear(): void
    {
        $ana = $this->player(1, 'Ana');
        $uid = (int) $ana->userId;
        $gift = $this->config->int('reminders.birthday_gift');
        $this->assertGreaterThan(0, $gift);
        $today = substr($this->app->reminders->formatDate(Clock::now()), 0, 5);   // DD/MM

        $this->cmd($ana, "cumple {$today}");
        $this->app->reminders->tick();
        $this->assertSame($gift, $this->app->wallet->balance($uid));

        // Volver a anotar la fecha de hoy no paga de nuevo en el mismo año.
        $this->cmd($ana, "cumple {$today}");
        $this->app->reminders->tick();
        $this->assertSame($gift, $this->app->wallet->balance($uid));

        Clock::advance(365 * 86400);
        $this->app->reminders->tick();
        $this->assertSame(2 * $gift, $this->app->wallet->balance($uid));
    }

    public function testFebruary29BirthdaysAreCelebratedOnThe28thInCommonYears(): void
    {
        $ana = $this->player(1, 'Ana');
        $uid = (int) $ana->userId;
        $this->cmd($ana, 'cumple 29/02');
        // 2027 no es bisiesto: el 28/02 al mediodía, en la zona del servicio.
        $tz = new \DateTimeZone($this->config->string('service.timezone', 'America/Montevideo'));
        Clock::advance((new \DateTimeImmutable('2027-02-28 12:00:00', $tz))->getTimestamp() - Clock::now());
        $this->app->reminders->tick();
        $this->assertSame($this->config->int('reminders.birthday_gift'), $this->app->wallet->balance($uid));
    }
}
