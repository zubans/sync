<?php

namespace App\Service;

use App\Entity\VpnConnection;
use App\Entity\VpnSubscription;
use App\Repository\VpnConnectionRepository;
use App\Vpn\SubscriptionParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Подписки провайдеров: загрузка списка серверов, подключения с выбранным сервером и обновление их ссылок.
 * Панели с лимитом устройств (Remnawave) без заголовка x-hwid отдают заглушку, поэтому узел шлёт свой HWID
 * или HWID, заданный в подписке.
 */
final class VpnSubscriptionService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly VpnConnectionRepository $connections,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    /** HWID этого узла: из секрета приложения, поэтому постоянный, пока тот же APP_SECRET. */
    public function deviceHwid(): string
    {
        return SubscriptionParser::hwid('server:' . $this->appSecret);
    }

    public function hwidFor(VpnSubscription $subscription): string
    {
        return $subscription->getHwid() ?? $this->deviceHwid();
    }

    /**
     * Скачивает список серверов и обновляет ссылки подключений, выбравших серверы этой подписки.
     *
     * @return int сколько подключений получили новую ссылку
     *
     * @throws \RuntimeException с понятным текстом для администратора
     */
    public function refresh(VpnSubscription $subscription): int
    {
        try {
            $response = $this->httpClient->request('GET', $subscription->getUrl(), [
                'timeout' => 45,
                'headers' => [
                    'User-Agent' => SubscriptionParser::USER_AGENT,
                    'x-hwid' => $this->hwidFor($subscription),
                    'x-device-os' => 'Linux',
                    'x-ver-os' => php_uname('r'),
                    'x-device-model' => 'CEP admin',
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('сервер подписки ответил %d', $status));
            }
            $body = $response->getContent();
            $headers = $response->getHeaders();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('не удалось скачать подписку: ' . $e->getMessage(), 0, $e);
        }

        $servers = SubscriptionParser::parse($body);
        if ($servers === []) {
            throw new \RuntimeException('в ответе нет ни одного сервера');
        }
        if (SubscriptionParser::isStub($servers)) {
            throw new \RuntimeException(($headers['x-hwid-limit'][0] ?? '') === 'true'
                ? 'панель не приняла устройство: возможно, исчерпан лимит устройств подписки. Укажите HWID уже зарегистрированного устройства или освободите место у провайдера'
                : sprintf('панель вернула заглушку вместо серверов: «%s»', $servers[0]['name']));
        }

        $title = SubscriptionParser::title($headers['profile-title'][0] ?? null);
        // имя из панели — только пока подписку не переименовали (оно ещё равно хосту адреса)
        if ($title !== null && $title !== '' && ($subscription->getName() === '' || $subscription->getName() === parse_url($subscription->getUrl(), PHP_URL_HOST))) {
            $subscription->setName($title);
        }
        $subscription->setServers($servers);
        $subscription->setInfo(SubscriptionParser::userInfo($headers['subscription-userinfo'][0] ?? null));
        $subscription->setRefreshedAt(new \DateTimeImmutable());

        $updated = 0;
        foreach ($this->connections->findBy(['subscriptionId' => $subscription->getExternalId()]) as $conn) {
            if ($this->applyServer($conn, $subscription, $conn->getSubscriptionServer() ?? '')) {
                ++$updated;
            }
        }
        $this->em->flush();

        return $updated;
    }

    /**
     * Ставит подключению ссылку и протокол выбранного сервера (по имени, затем по прежнему host:port).
     * false — сервера в подписке нет или CEP его протокол не поддерживает; подключение остаётся как было.
     */
    public function applyServer(VpnConnection $conn, VpnSubscription $subscription, string $serverName): bool
    {
        $server = SubscriptionParser::match($subscription->getServers(), $serverName, $conn->getUri());
        $proto = $server !== null ? SubscriptionParser::protocolOf($server['uri']) : null;
        if ($server === null || $proto === null) {
            return false;
        }
        $conn->setSubscriptionId($subscription->getExternalId());
        $conn->setSubscriptionServer($server['name']);
        $conn->setProto($proto);
        $conn->setUri($server['uri']);

        return true;
    }

    /** Новое подключение с выбранным сервером подписки. */
    public function createConnection(VpnSubscription $subscription, string $serverName): VpnConnection
    {
        $conn = new VpnConnection();
        if (!$this->applyServer($conn, $subscription, $serverName)) {
            throw new \RuntimeException(sprintf('сервера «%s» нет в подписке или CEP не поддерживает его протокол', $serverName));
        }
        $conn->setName($conn->getSubscriptionServer() ?? $serverName);
        $this->em->persist($conn);
        $this->em->flush();

        return $conn;
    }

    /** Подключения остаются с последней ссылкой, но больше не обновляются. */
    public function detachConnections(VpnSubscription $subscription): void
    {
        foreach ($this->connections->findBy(['subscriptionId' => $subscription->getExternalId()]) as $conn) {
            $conn->setSubscriptionId(null);
        }
    }
}
