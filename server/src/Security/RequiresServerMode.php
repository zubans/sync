<?php

namespace App\Security;

/**
 * Помечает контроллеры и действия админки, доступные исключительно когда текущий узел
 * переведён в режим «VPN-сервер» (NodeSettings::MODE_SERVER).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequiresServerMode
{
    public function __construct(
        public readonly string $message = 'Раздел доступен только в режиме «Сервер»',
    ) {
    }
}
