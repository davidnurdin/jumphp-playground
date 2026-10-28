<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\LogoutException;

/** "Log out" from a page older than the session (a CSRF token that no longer matches): back home, not a 403. */
final class StaleLogout implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -1]];    // after the firewall turned it into a 403
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->getThrowable()->getPrevious() instanceof LogoutException) {
            $event->setResponse(new RedirectResponse('/'));
        }
    }
}
