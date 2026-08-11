<?php

declare(strict_types=1);

namespace Pablo\Tests\Twig;

use Pablo\Twig\UserTimezone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class UserTimezoneTest extends TestCase
{
    private string $saved;

    protected function setUp(): void
    {
        $this->saved = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->saved);
    }

    private function fire(?string $cookie): string
    {
        $request = new Request();
        $request->cookies->set('pablo_tz', $cookie);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $listener = new UserTimezone();
        $listener->setTimezone(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        return date_default_timezone_get();
    }

    public function testValidIanaIdentifierSetsDefaultTimezone(): void
    {
        self::assertSame('Europe/Paris', $this->fire('Europe/Paris'));
    }

    public function testUnknownIdentifierLeavesDefaultUntouched(): void
    {
        self::assertSame('UTC', $this->fire('Not/AZone'));
    }

    public function testMissingCookieLeavesDefaultUntouched(): void
    {
        self::assertSame('UTC', $this->fire(null));
    }
}
