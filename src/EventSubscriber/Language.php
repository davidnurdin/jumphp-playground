<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The language of a page: ?lang=fr|en (the flags, remembered in a cookie), else the cookie, else the browser's
 * (Accept-Language: French, else English), else French.
 */
final class Language implements EventSubscriberInterface
{
    public const LOCALES = ['fr', 'en'];
    private const COOKIE = 'jumphp_lang';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 20],     // before the LocaleListener (16)
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $asked = $request->query->get('lang');
        $remembered = $request->cookies->get(self::COOKIE);
        $locale = match (true) {
            in_array($asked, self::LOCALES, true) => $asked,
            in_array($remembered, self::LOCALES, true) => $remembered,
            // a browser in neither language gets English; no Accept-Language at all (a robot), French
            !$request->headers->has('Accept-Language') => 'fr',
            default => $request->getPreferredLanguage(['en', 'fr']) ?? 'en',
        };
        $request->attributes->set('_locale', $locale);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $asked = $request->query->get('lang');
        if (in_array($asked, self::LOCALES, true) && $asked !== $request->cookies->get(self::COOKIE)) {
            $response->headers->setCookie(Cookie::create(self::COOKIE, $asked, '+1 year', '/', null, null, false, false, Cookie::SAMESITE_LAX));
        }
        $response->setVary(['Accept-Language', 'Cookie'], false);
    }
}
