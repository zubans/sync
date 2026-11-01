<?php

namespace App\Service;

/**
 * Провайдер векторов отказал по лимиту (Gemini, HTTP 429). [retryAfter] — через сколько секунд повторить,
 * как сказал провайдер, или null, если не сказал.
 */
final class NewsEmbeddingRateLimit extends \RuntimeException
{
    public function __construct(string $message, public readonly ?float $retryAfter)
    {
        parent::__construct($message);
    }
}
