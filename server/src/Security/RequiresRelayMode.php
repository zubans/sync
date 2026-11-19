<?php

namespace App\Security;

/**
 * Помечает разделы админки, доступные узлу, который принимает трафик извне и пропускает его
 * дальше по цепочке: роли «Узел на цепочке» (NodeSettings::MODE_RELAY) и «VPN-сервер».
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequiresRelayMode
{
    public function __construct(
        public readonly string $message = 'Раздел доступен только в ролях «Узел на цепочке» и «VPN-сервер»',
    ) {
    }
}
