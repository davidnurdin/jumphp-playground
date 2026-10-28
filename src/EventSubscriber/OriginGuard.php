<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Keeps the two origins apart: on the games origin only the play_* routes answer (no page of the portal, so no
 * session cookie there), and each origin gets its own security headers.
 */
final class OriginGuard implements EventSubscriberInterface
{
    public function __construct(private string $playHost, private string $appUrl, private ?Profiler $profiler = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 31],    // just after the router
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    private function isPlay(string $host): bool
    {
        return strcasecmp($host, $this->playHost) === 0;
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string)$request->attributes->get('_route');
        if (!$event->isMainRequest() || !$this->isPlay($request->getHost())) {
            return;
        }
        // dev: no debug toolbar injected into the games' pages
        $this->profiler?->disable();
        if ($route !== '' && !str_starts_with($route, 'play_')) {
            throw new NotFoundHttpException();
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if ($this->isPlay($event->getRequest()->getHost())) {
            // the portal shows these pages in a frame; search engines index the portal's page, not these
            $headers->set('Content-Security-Policy', "frame-ancestors 'self' " . rtrim($this->appUrl, '/'));
            $headers->set('X-Robots-Tag', 'noindex');
            $headers->remove('Set-Cookie');
            return;
        }
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }
    }
}
