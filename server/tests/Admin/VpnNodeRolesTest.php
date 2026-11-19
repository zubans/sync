<?php

namespace App\Tests\Admin;

use App\Entity\NodeSettings;
use App\Entity\RelayRoute;
use App\Entity\VpnChain;
use App\Entity\VpnChainHop;
use App\Entity\VpnConnection;
use App\Entity\VpnServerService;
use App\Service\CepExportService;
use App\Service\NodePortPlan;
use App\Service\NodeRuntimeExporter;
use App\Service\PortForwardValidator;
use App\Service\VpnChainRouteDescriber;
use App\Service\VpnNodeSettingsService;
use App\Tests\DatabaseWebTestCase;
use App\Vpn\VpnProtocol;
use Symfony\Component\Filesystem\Filesystem;

final class VpnNodeRolesTest extends DatabaseWebTestCase
{
    private string $runtimeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeDir = static::getContainer()->get(NodeRuntimeExporter::class)->getRuntimeDir();
        (new Filesystem())->remove($this->runtimeDir);
    }

    public function testRelayRoleShowsRelaySectionAndHidesServerSections(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $this->switchMode(NodeSettings::MODE_RELAY);

        $crawler = $this->client->request('GET', '/admin');
        $menuText = $crawler->filter('.main-sidebar, #sidebar, aside')->text();
        self::assertStringContainsString('VPN / CEP — Узел на цепочке', $menuText);
        self::assertStringContainsString('Пропуск трафика', $menuText);
        self::assertStringNotContainsString('Серверные службы', $menuText);

        $this->client->request('GET', '/admin/relay-route');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/vpn-server-service');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/vpn-node');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Сейчас: Узел на цепочке');
        self::assertSelectorTextContains('body', 'Порты узла');

        $this->switchMode(NodeSettings::MODE_CLIENT);
        $this->client->request('GET', '/admin/relay-route');
        self::assertResponseStatusCodeSame(403);
    }

    public function testServiceFormGroupsFieldsByProtocol(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $this->switchMode(NodeSettings::MODE_SERVER);

        $crawler = $this->client->request('GET', '/admin/vpn-server-service/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Приём обфусцированного трафика (обёртка Argon4)');
        self::assertGreaterThan(0, $crawler->filter('.js-proto-section.js-proto-vless')->count());
        self::assertGreaterThan(0, $crawler->filter('.js-proto-section.js-proto-transit')->count());
        self::assertStringContainsString("querySelectorAll('.js-proto-section')", (string) $this->client->getResponse()->getContent());
    }

    public function testRelayRoutesProduceBlindArgonProcessDnatRulesAndPortPlan(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $this->switchMode(NodeSettings::MODE_RELAY);

        $this->submitRelayRoute([
            'name' => 'К серверу в Амстердаме',
            'method' => RelayRoute::METHOD_ARGON_BLIND,
            'listenPort' => '40000',
            'targetHost' => 'ams.example.com',
            'targetPort' => '51821',
        ]);
        self::assertResponseRedirects();
        $this->submitRelayRoute([
            'name' => 'VLESS напрямую',
            'method' => RelayRoute::METHOD_DNAT,
            'transportProto' => 'tcp',
            'listenPort' => '8443',
            'targetHost' => '203.0.113.7',
            'targetPort' => '443',
        ]);
        self::assertResponseRedirects();

        $blind = $this->em->getRepository(RelayRoute::class)->findOneBy(['listenPort' => 40000]);
        self::assertNotNull($blind);
        $env = (string) file_get_contents(sprintf('%s/services/argon-relay-%d.env', $this->runtimeDir, $blind->getId()));
        self::assertStringContainsString("ARGON_LISTEN_ADDR='0.0.0.0:40000'", $env);
        self::assertStringContainsString('ARGON_MODE=transit', $env);
        self::assertStringContainsString('ARGON_TRANSIT_BLIND=1', $env);
        self::assertStringContainsString("ARGON_TRANSIT_TARGET='ams.example.com:51821'", $env);

        $script = (string) file_get_contents($this->runtimeDir . '/port-forward.sh');
        self::assertStringContainsString("--dport 8443 -j DNAT --to-destination '203.0.113.7:443'", $script);
        self::assertStringContainsString("-d '203.0.113.7' --dport 8443 -j MASQUERADE", $script);

        $state = json_decode((string) file_get_contents($this->runtimeDir . '/desired-state.json'), true);
        $ports = array_map(static fn (array $p): string => $p['port'] . '/' . $p['transport'], $state['ports']);
        self::assertSame(['8443/tcp', '40000/both'], $ports);
        self::assertTrue($state['relay']['enabled']);

        // Тот же входной порт второй раз занять нельзя.
        $this->submitRelayRoute([
            'name' => 'Дубль',
            'method' => RelayRoute::METHOD_DNAT,
            'transportProto' => 'udp',
            'listenPort' => '40000',
            'targetHost' => '203.0.113.8',
            'targetPort' => '51820',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Порт 40000/UDP уже занят: пропуск трафика «К серверу в Амстердаме»');

        // DNAT требует IPv4, а не домен.
        $this->submitRelayRoute([
            'name' => 'DNAT на домен',
            'method' => RelayRoute::METHOD_DNAT,
            'listenPort' => '40001',
            'targetHost' => 'next.example.com',
            'targetPort' => '443',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'задаётся IPv4-адресом');

        $this->client->request('GET', '/admin/vpn-node');
        self::assertSelectorTextContains('body', 'без расшифровки → ams.example.com:51821');
    }

    public function testArgonWrapDecryptsIntoServiceAndClientProfileUsesWrapPort(): void
    {
        $this->switchMode(NodeSettings::MODE_SERVER);
        $settings = static::getContainer()->get(VpnNodeSettingsService::class)->getSettings();
        $settings->setPublicHost('vpn.node.example');

        $vless = new VpnServerService();
        $vless->setName('VLESS');
        $vless->setProto(VpnProtocol::VLESS);
        $vless->setListenPort(443);
        $vless->setTransportProto('tcp');
        $vless->setArgonWrapEnabled(true);
        $vless->setArgonWrapPort(51821);
        $vless->setArgonWrapPassword('wrap-secret');
        $this->em->persist($vless);
        $this->em->flush();

        $validator = static::getContainer()->get(PortForwardValidator::class);
        self::assertSame([], $validator->validateServerService($vless));

        static::getContainer()->get(NodeRuntimeExporter::class)->sync();
        $env = (string) file_get_contents(sprintf('%s/services/argon-wrap-%d.env', $this->runtimeDir, $vless->getId()));
        self::assertStringContainsString("ARGON_LISTEN_ADDR='0.0.0.0:51821'", $env);
        self::assertStringContainsString('ARGON_MODE=transit', $env);
        self::assertStringContainsString('ARGON_TRANSIT_BLIND=0', $env);
        self::assertStringContainsString("ARGON_TRANSIT_TARGET='127.0.0.1:443'", $env);
        self::assertStringContainsString("ARGON_MASTER_PASSWORD='wrap-secret'", $env);

        $plan = static::getContainer()->get(NodePortPlan::class)->build();
        self::assertSame([[443, 'tcp', 'service'], [51821, 'both', 'argon_wrap']], array_map(
            static fn (array $e): array => [$e['start'], $e['transport'], $e['kind']],
            $plan,
        ));

        $export = static::getContainer()->get(CepExportService::class);
        $conn = $export->createConnectionFromServerService($vless, $settings);
        self::assertTrue($conn->isUseArgon());
        self::assertSame(51821, $conn->getArgonPort());
        self::assertSame('wrap-secret', $conn->getArgonPassword());
        $json = $export->serializeConnection($conn);
        self::assertTrue($json['useArgon']);
        self::assertSame(51821, $json['argonPort']);

        // Порт обёртки не может совпадать с портом самой службы.
        $vless->setArgonWrapPort(443);
        self::assertContains(
            'Порт обёртки Argon4 (443) совпадает с портом самой службы: обёртке нужен отдельный порт.',
            $validator->validateServerService($vless),
        );
    }

    public function testDefaultArgonPortIsNotExported(): void
    {
        $conn = new VpnConnection();
        $conn->setName('VLESS');
        $conn->setProto(VpnProtocol::VLESS);
        $conn->setUri('vless://u@example.com:443?security=tls');
        $conn->setUseArgon(true);
        $conn->setArgonPassword('p');

        $json = static::getContainer()->get(CepExportService::class)->serializeConnection($conn);
        self::assertArrayNotHasKey('argonPort', $json);
        self::assertSame(VpnProtocol::ARGON_DEFAULT_PORT, $conn->getEffectiveArgonPort());
    }

    public function testChainRouteShowsWhereTrafficIsObfuscated(): void
    {
        $blind = $this->connection('Слепой узел', VpnProtocol::TRANSIT, server: 'relay.example.com:40000');
        $wrapped = $this->connection('VLESS в Argon4', VpnProtocol::VLESS, uri: 'vless://u@exit.example.com:443?security=tls');
        $wrapped->setUseArgon(true);
        $wrapped->setArgonPort(51821);
        $wrapped->setArgonPassword('wrap');
        $plain = $this->connection('VLESS без обёртки', VpnProtocol::VLESS, uri: 'vless://u@exit.example.com:443?security=tls');

        $describer = static::getContainer()->get(VpnChainRouteDescriber::class);

        $segments = $describer->describe($this->chain('Обфусцированная', $blind, $wrapped));
        self::assertCount(3, $segments);
        self::assertSame('relay.example.com:40000/UDP', $segments[0]['address']);
        self::assertTrue($segments[0]['obfuscated']);
        self::assertNull($segments[0]['decryptedAt'], 'слепой узел не расшифровывает');
        self::assertSame('exit.example.com:51821/UDP', $segments[1]['address']);
        self::assertSame('Argon4, внутри — VLESS', $segments[1]['wire']);
        self::assertSame('Узел 2 «VLESS в Argon4»', $segments[1]['decryptedAt']);
        self::assertSame('Интернет', $segments[2]['to']);
        self::assertFalse($describer->hasPlainSegment($this->chain('Обфусцированная 2', $blind, $wrapped)));

        $plainChain = $this->chain('Без обфускации', $plain);
        self::assertTrue($describer->hasPlainSegment($plainChain));
        self::assertSame('VLESS без обфускации Argon4', $describer->describe($plainChain)[0]['wire']);
    }

    private function switchMode(string $mode): void
    {
        // После запроса клиент перезагружает ядро, поэтому сохраняем через менеджер текущего контейнера.
        $container = static::getContainer();
        $container->get(VpnNodeSettingsService::class)->getSettings()->setMode($mode);
        $container->get('doctrine')->getManager()->flush();
    }

    /** @param array<string, string> $fields */
    private function submitRelayRoute(array $fields): void
    {
        $crawler = $this->client->request('GET', '/admin/relay-route/new');
        $form = $crawler->filter('form[name="RelayRoute"]')->form();
        $values = $form->getPhpValues();
        foreach ($fields as $key => $value) {
            $values['RelayRoute'][$key] = $value;
        }
        $this->client->request('POST', $form->getUri(), $values);
    }

    private function connection(string $name, string $proto, ?string $server = null, ?string $uri = null): VpnConnection
    {
        $conn = new VpnConnection();
        $conn->setName($name);
        $conn->setProto($proto);
        $conn->setServer($server);
        $conn->setUri($uri);

        return $conn;
    }

    private function chain(string $name, VpnConnection ...$connections): VpnChain
    {
        $chain = new VpnChain();
        $chain->setName($name);
        foreach ($connections as $position => $conn) {
            $hop = new VpnChainHop();
            $hop->setConnection($conn);
            $hop->setPosition($position);
            $chain->addHop($hop);
        }

        return $chain;
    }
}
