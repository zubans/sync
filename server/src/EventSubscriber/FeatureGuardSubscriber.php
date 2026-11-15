<?php

namespace App\EventSubscriber;

use App\Security\FeatureDisabledException;
use App\Security\RequiresFeature;
use App\Service\FeatureManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Защищает API и экраны админки, помеченные #[RequiresFeature], возвращая HTTP 503 при отключённом блоке.
 */
final class FeatureGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly FeatureManager $features)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 10],
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();
        if (\is_array($controller)) {
            [$object, $method] = $controller;
        } elseif (\is_object($controller) && method_exists($controller, '__invoke')) {
            [$object, $method] = [$controller, '__invoke'];
        } else {
            return;
        }

        $refClass = new \ReflectionClass($object);
        $attrs = [
            ...$refClass->getAttributes(RequiresFeature::class),
            ...($refClass->hasMethod($method) ? $refClass->getMethod($method)->getAttributes(RequiresFeature::class) : []),
        ];

        foreach ($attrs as $attr) {
            /** @var RequiresFeature $instance */
            $instance = $attr->newInstance();
            $this->features->assertEnabled($instance->feature, $instance->message);
        }
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if (!$e instanceof FeatureDisabledException) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with($path, '/api')) {
            $event->setResponse(new JsonResponse([
                'error' => $e->getMessage(),
                'feature' => $e->feature,
            ], 503));
        }
    }
}
