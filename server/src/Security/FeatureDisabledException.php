<?php

namespace App\Security;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Исключение при обращении к отключённому в админке функциональному блоку или ИИ-функции (HTTP 503).
 */
final class FeatureDisabledException extends ServiceUnavailableHttpException
{
    public function __construct(
        public readonly string $feature,
        string $message,
    ) {
        parent::__construct(null, $message);
    }
}
