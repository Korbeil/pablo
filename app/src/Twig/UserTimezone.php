<?php

declare(strict_types=1);

namespace Pablo\Twig;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes the dashboard render absolute times in the browser's local timezone.
 *
 * State and stamps are stored in UTC on purpose; only the presentation layer
 * converts. The page writes a `pablo_tz` cookie (an IANA identifier) from
 * Intl.DateTimeFormat().resolvedOptions().timeZone; this listener validates it
 * and points PHP's default timezone at it for the rest of the request, so Twig's
 * |date filters and PollSchedule's DateTimeZone(date_default_timezone_get())
 * all render in the user's local time with a single hook.
 *
 * Runs on every web request — including Live Component polling re-renders — but
 * never on the CLI, so terminal output is untouched.
 */
final class UserTimezone implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'setTimezone'];
    }

    public function setTimezone(RequestEvent $event): void
    {
        $tz = $event->getRequest()->cookies->get('pablo_tz');
        if (null === $tz || !\in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        date_default_timezone_set($tz);
    }
}
