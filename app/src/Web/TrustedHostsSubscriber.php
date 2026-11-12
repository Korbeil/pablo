<?php

declare(strict_types=1);

namespace Pablo\Web;

use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the trusted hosts (TrustedHosts) on every dashboard request, before
 * anything reads the Host header.
 *
 * framework.trusted_hosts can only hold the loopback names: the container is
 * cached, so a value read from ~/.pablo/config.yaml at compile time would
 * freeze. The user's `dashboard_trusted_hosts` is read here instead, per
 * request, so an edit applies on the next one. An invalid value fails
 * closed (loopback only); `pablo web` reports it at startup.
 */
final class TrustedHostsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly GlobalConfig $globalConfig)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Ahead of the router (32) and anything else that may call getHost().
        return [KernelEvents::REQUEST => ['apply', 2048]];
    }

    public function apply(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        try {
            $extra = $this->globalConfig->dashboardTrustedHosts();
        } catch (PabloError) {
            $extra = [];
        }
        Request::setTrustedHosts(TrustedHosts::patterns($extra));
    }
}
