<?php

namespace App\Security;

/**
 * Помечает контроллер или метод как относящийся к отключаемому модулю / ИИ-функции.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequiresFeature
{
    public function __construct(
        public readonly string $feature,
        public readonly ?string $message = null,
    ) {
    }
}
