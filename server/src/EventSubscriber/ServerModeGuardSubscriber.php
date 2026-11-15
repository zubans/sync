<?php

namespace App\EventSubscriber;

use App\Security\RequiresServerMode;
use App\Service\VpnNodeSettingsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Блокирует доступ к серверным разделам админки (пользователи, службы VPN, проброс портов),
 * пока устройство работает в режиме «Клиент».
 */
final class ServerModeGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly VpnNodeSettingsService $settingsService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();
        if (is_array($controller)) {
            [$object, $method] = $controller;
        } elseif (is_object($controller) && method_exists($controller, '__invoke')) {
            [$object, $method] = [$controller, '__invoke'];
        } else {
            return;
        }

        $refClass = new \ReflectionClass($object);
        $classAttrs = $refClass->getAttributes(RequiresServerMode::class);
        $methodAttrs = $refClass->hasMethod($method)
            ? $refClass->getMethod($method)->getAttributes(RequiresServerMode::class)
            : [];

        $attr = $methodAttrs[0] ?? $classAttrs[0] ?? null;
        if ($attr === null) {
            return;
        }

        /** @var RequiresServerMode $instance */
        $instance = $attr->newInstance();
        $this->settingsService->assertServerMode($instance->message);
    }
}
