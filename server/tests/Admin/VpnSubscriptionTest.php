<?php

namespace App\Tests\Admin;

use App\Entity\VpnConnection;
use App\Entity\VpnSubscription;
use App\Repository\VpnConnectionRepository;
use App\Service\CepExportService;
use App\Service\NodeRuntimeExporter;
use App\Service\VpnSubscriptionService;
use App\Tests\DatabaseWebTestCase;
use App\Vpn\SubscriptionParser;
use App\Vpn\VpnProtocol;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VpnSubscriptionTest extends DatabaseWebTestCase
{
    private const URL = 'https://sub.example.com/abc';
    private const UUID = '11111111-2222-3333-4444-555555555555';

    /** @var list<string> что отдаёт «панель» на следующие запросы */
    private array $bodies = [];
    /** @var list<array<string, mixed>> заголовки запросов к «панели» */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new Filesystem())->remove(static::getContainer()->get(NodeRuntimeExporter::class)->getRuntimeDir());
        // ядро не пересоздаётся между запросами, иначе подменённая «панель» пропала бы
        $this->client->disableReboot();
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = $options['normalized_headers'] ?? [];
            $body = array_shift($this->bodies) ?? '';

            return new MockResponse($body, ['response_headers' => [
                'profile-title' => 'base64:' . base64_encode('Мой провайдер'),
                'subscription-userinfo' => 'upload=0; download=1073741824; total=0; expire=0',
                'x-hwid-limit' => 'true',
            ]]);
        });
        $container = static::getContainer();
        $container->set(VpnSubscriptionService::class, new VpnSubscriptionService(
            $http, $container->get(EntityManagerInterface::class), $container->get(VpnConnectionRepository::class), 'test-secret',
        ));
    }

    private static function links(string $usaHost): string
    {
        return base64_encode(implode("\n", [
            'vless://' . self::UUID . '@pool.example.com:443?type=xhttp&security=tls&path=%2Fp#%F0%9F%87%A9%F0%9F%87%AA%20Frankfurt%20',
            'vless://' . self::UUID . '@' . $usaHost . ':443?type=grpc&mode=multi&serviceName=g&security=tls#USA',
            'trojan://secret@t.example.com:443#Trojan',
        ]));
    }

    public function testParserMatchesClients(): void
    {
        $plain = implode("\n", [
            'vless://u@a.example.com:443#X',
            'hy2://p@b.example.com:8443',
            'vless://u@c.example.com:443#X',
        ]);
        $urlSafe = rtrim(strtr(base64_encode($plain), '+/', '-_'), '=');
        foreach ([$plain, base64_encode($plain), $urlSafe] as $body) {
            self::assertSame(['X', 'b.example.com:8443', 'X (2)'], array_column(SubscriptionParser::parse($body), 'name'));
        }
        self::assertSame(VpnProtocol::HYSTERIA2, SubscriptionParser::protocolOf('hy2://p@b:1'));
        self::assertNull(SubscriptionParser::protocolOf('trojan://p@b:1'));
        self::assertTrue(SubscriptionParser::isStub(SubscriptionParser::parse('vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1#stub')));
        // то же значение считают клиенты CEP на macOS и Android для seed «ABC»
        self::assertSame('4fc80a53394536936f76a8e55471e683', SubscriptionParser::hwid('ABC'));
        self::assertSame('использовано 1.0 ГБ из ∞', SubscriptionParser::userInfo('upload=0; download=1073741824; total=0; expire=0'));
    }

    public function testSubscriptionCreatesConnectionRefreshesLinkAndExports(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin');
        self::assertStringContainsString('Подписки провайдеров', $crawler->filter('.main-sidebar, #sidebar, aside')->text());

        // новая подписка сразу скачивается с HWID узла
        $this->bodies[] = self::links('usa.example.com');
        $crawler = $this->client->request('GET', '/admin/vpn-subscription/new');
        $form = $crawler->filter('form[name="VpnSubscription"]')->form();
        $form['VpnSubscription[url]'] = self::URL;
        $this->client->submit($form);
        self::assertResponseRedirects();
        $sub = $this->em->getRepository(VpnSubscription::class)->findOneBy(['url' => self::URL]);
        self::assertSame('Мой провайдер', $sub->getName());
        self::assertSame(['🇩🇪 Frankfurt', 'USA', 'Trojan'], array_column($sub->getServers(), 'name'));
        self::assertSame('использовано 1.0 ГБ из ∞', $sub->getInfo());
        $headers = $this->requests[0];
        self::assertSame(['User-Agent: ' . SubscriptionParser::USER_AGENT], $headers['user-agent']);
        self::assertSame(['x-hwid: ' . SubscriptionParser::hwid('server:test-secret')], $headers['x-hwid']);

        // карточка подписки: серверы и кнопка «Создать подключение» только для поддерживаемых
        $crawler = $this->client->request('GET', '/admin/vpn-subscription/' . $sub->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'CEP этот протокол не поддерживает');
        self::assertCount(2, $crawler->filter('form[action$="/connection"]'));

        $usaForm = $crawler->filter('form[action$="/connection"]')->eq(1)->form();
        $this->client->submit($usaForm);
        self::assertResponseRedirects();
        $conn = $this->em->getRepository(VpnConnection::class)->findOneBy(['subscriptionServer' => 'USA']);
        self::assertSame(VpnProtocol::VLESS, $conn->getProto());
        self::assertStringContainsString('@usa.example.com:443', (string) $conn->getUri());
        self::assertSame($sub->getExternalId(), $conn->getSubscriptionId());

        // провайдер сменил адрес сервера — обновление подписки обновляет ссылку подключения
        $this->bodies[] = self::links('usa2.example.com');
        $crawler = $this->client->request('GET', '/admin/vpn-subscription/' . $sub->getId());
        $this->client->submit($crawler->filter('form[action*="/refresh"]')->first()->form());
        self::assertResponseRedirects();
        $this->em->clear();
        $conn = $this->em->getRepository(VpnConnection::class)->find($conn->getId());
        self::assertStringContainsString('@usa2.example.com:443', (string) $conn->getUri());

        // смена сервера в форме подключения подставляет ссылку и протокол выбранного сервера
        $crawler = $this->client->request('GET', '/admin/vpn-connection/' . $conn->getId() . '/edit');
        $form = $crawler->filter('form[name="VpnConnection"]')->form();
        $form['VpnConnection[subscriptionServer]']->select('🇩🇪 Frankfurt');
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->em->clear();
        $conn = $this->em->getRepository(VpnConnection::class)->find($conn->getId());
        self::assertSame('🇩🇪 Frankfurt', $conn->getSubscriptionServer());
        self::assertStringContainsString('type=xhttp', (string) $conn->getUri());

        // profiles.json для клиентов CEP: подписка и выбор сервера в их формате
        $payload = static::getContainer()->get(CepExportService::class)->buildStorePayload(
            static::getContainer()->get(\App\Service\VpnNodeSettingsService::class)->getSettings(),
            [$conn], [], null,
        );
        self::assertSame($sub->getExternalId(), $payload['subscriptions'][0]['id']);
        self::assertSame(self::URL, $payload['subscriptions'][0]['url']);
        self::assertIsFloat($payload['subscriptions'][0]['updatedAt']);
        self::assertSame('🇩🇪 Frankfurt', $payload['profiles'][0]['subscriptionServer']);
        self::assertSame($sub->getExternalId(), $payload['profiles'][0]['subscriptionID']);
    }

    public function testStubFromPanelIsExplainedAndDeleteKeepsConnection(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $this->bodies[] = base64_encode('vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1#Приложение не поддерживается');
        $crawler = $this->client->request('GET', '/admin/vpn-subscription/new');
        $form = $crawler->filter('form[name="VpnSubscription"]')->form();
        $form['VpnSubscription[url]'] = self::URL;
        $form['VpnSubscription[hwid]'] = 'registered-device';
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'лимит устройств');
        self::assertSame(['x-hwid: registered-device'], $this->requests[0]['x-hwid']);

        // удаление подписки оставляет подключения с последней ссылкой, но без привязки
        $sub = $this->em->getRepository(VpnSubscription::class)->findOneBy(['url' => self::URL]);
        $sub->setServers(SubscriptionParser::parse(self::links('usa.example.com')));
        $this->em->flush();
        $conn = static::getContainer()->get(VpnSubscriptionService::class)->createConnection($sub, 'USA');
        $crawler = $this->client->request('GET', '/admin/vpn-subscription');
        $token = $crawler->filter('form[method="post"] input[name="token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/vpn-subscription/' . $sub->getId() . '/delete', ['token' => $token]);
        self::assertResponseRedirects();
        $this->em->clear();
        $conn = $this->em->getRepository(VpnConnection::class)->find($conn->getId());
        self::assertNull($conn->getSubscriptionId());
        self::assertStringContainsString('usa.example.com', (string) $conn->getUri());
    }
}
