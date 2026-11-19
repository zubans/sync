<?php

namespace App\Twig;

use App\Entity\VpnConnection;
use App\Entity\VpnSubscription;
use App\Repository\VpnConnectionRepository;
use App\Vpn\SubscriptionParser;
use App\Vpn\VpnProtocol;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Функции карточки подписки: протокол и адрес сервера, подключения, выбравшие сервер. */
final class VpnSubscriptionExtension extends AbstractExtension
{
    public function __construct(private readonly VpnConnectionRepository $connections)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('subscription_server_protocol', static fn (string $uri): ?string => ($p = SubscriptionParser::protocolOf($uri)) !== null ? VpnProtocol::label($p) : null),
            new TwigFunction('subscription_server_address', SubscriptionParser::address(...)),
            new TwigFunction('subscription_connections', $this->connectionsFor(...)),
        ];
    }

    /** @return list<VpnConnection> */
    public function connectionsFor(VpnSubscription $subscription, string $serverName): array
    {
        return $this->connections->findBy(['subscriptionId' => $subscription->getExternalId(), 'subscriptionServer' => $serverName], ['name' => 'ASC']);
    }
}
