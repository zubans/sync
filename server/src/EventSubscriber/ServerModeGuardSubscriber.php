<?php

namespace App\EventSubscriber;

use App\Security\RequiresRelayMode;
use App\Security\RequiresServerMode;
use App\Service\VpnNodeSettingsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Блокирует доступ к разделам админки, недоступным в текущей роли узла: серверные разделы
 * (пользователи, службы VPN, проброс портов) — вне роли «VPN-сервер», правила пропуска
 * трафика — в роли «Клиент».
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

        $server = $this->findAttribute($refClass, $method, RequiresServerMode::class);
        if ($server instanceof RequiresServerMode) {
            $this->settingsService->assertServerMode($server->message);

            return;
        }

        $relay = $this->findAttribute($refClass, $method, RequiresRelayMode::class);
        if ($relay instanceof RequiresRelayMode) {
            $this->settingsService->assertRelayCapable($relay->message);
        }
    }

    /**
     * @template T of object
     * @param \ReflectionClass<object> $refClass
     * @param class-string<T>          $attributeClass
     * @return T|null
     */
    private function findAttribute(\ReflectionClass $refClass, string $method, string $attributeClass): ?object
    {
        $methodAttrs = $refClass->hasMethod($method)
            ? $refClass->getMethod($method)->getAttributes($attributeClass)
            : [];
        $attr = $methodAttrs[0] ?? $refClass->getAttributes($attributeClass)[0] ?? null;

        return $attr?->newInstance();
    }
}
