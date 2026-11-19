<?php

namespace App\Tests\Admin;

use App\Entity\NodeSettings;
use App\Entity\PortForwardRule;
use App\Entity\User;
use App\Entity\VpnChain;
use App\Entity\VpnConnection;
use App\Entity\VpnServerService;
use App\Entity\VpnUser;
use App\Repository\ApiTokenRepository;
use App\Service\CepExportService;
use App\Service\NodeRuntimeExporter;
use App\Tests\DatabaseWebTestCase;
use App\Vpn\VpnProtocol;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VpnAdminTest extends DatabaseWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $runtimeDir = static::getContainer()->get(NodeRuntimeExporter::class)->getRuntimeDir();
        (new Filesystem())->remove($runtimeDir);
    }

    public function testClientModeHidesAndBlocksServerSectionsAndUserCreation(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $menuText = $crawler->filter('.main-sidebar, #sidebar, aside')->text();
        self::assertStringContainsString('Узел, роль и порты', $menuText);
        self::assertStringContainsString('Подключения (все типы)', $menuText);
        self::assertStringContainsString('Цепочки узлов', $menuText);
        self::assertStringNotContainsString('Пользователи VPN / системы', $menuText);
        self::assertStringNotContainsString('Серверные службы', $menuText);

        $this->client->request('GET', '/admin/vpn-node');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Сейчас: Клиент');

        foreach ([
            '/admin/vpn-user',
            '/admin/vpn-user/new',
            '/admin/vpn-server-service',
            '/admin/vpn-server-service/new',
            '/admin/port-forward',
            '/admin/port-forward/new',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, sprintf('Ожидался HTTP 403 в режиме Клиент для %s', $url));
        }
    }

    public function testAllProtocolConnectionTypesCanBeCreatedValidatedAndExported(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        // Проверка валидации: Argon4 без мастер-пароля возвращает 422.
        $crawler = $this->client->request('GET', '/admin/vpn-connection/new');
        $form = $crawler->filter('form[name="VpnConnection"]')->form();
        $form['VpnConnection[name]'] = 'Битый Argon4';
        $form['VpnConnection[proto]']->select(VpnProtocol::ARGON4);
        $form['VpnConnection[server]'] = '203.0.113.10:51820';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'мастер-пароль Argon4');

        // Проверка валидации: слепой транзит запрещает профиль video-call.
        $form['VpnConnection[name]'] = 'Плохой слепой транзит';
        $form['VpnConnection[proto]']->select(VpnProtocol::TRANSIT);
        $form['VpnConnection[password]'] = '';
        $form['VpnConnection[argonBehaviorProfile]']->select('video-call');
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'video-call');

        $samples = [
            ['name' => 'Argon4 v5 VideoCall', 'proto' => VpnProtocol::ARGON4, 'server' => '203.0.113.10:51820', 'password' => 'pass-argon4', 'argonProtocolVersion' => '5', 'argonBehaviorProfile' => 'video-call', 'argonPaddingProfile' => 'balanced', 'argonPathMtu' => '1500'],
            ['name' => 'Слепой транзит', 'proto' => VpnProtocol::TRANSIT, 'server' => '203.0.113.11:51820'],
            ['name' => 'VLESS + Argon4', 'proto' => VpnProtocol::VLESS, 'uri' => 'vless://11111111-2222-4333-8444-555555555555@203.0.113.12:443?security=reality&pbk=pub&sid=1234#vless', 'useArgon' => true, 'argonPassword' => 'underlay-secret'],
            ['name' => 'Hysteria 2 узел', 'proto' => VpnProtocol::HYSTERIA2, 'uri' => 'hysteria2://hy2pass@203.0.113.13:443?sni=cdn.example#hy2'],
            ['name' => 'Hysteria v1 узел', 'proto' => VpnProtocol::HYSTERIA, 'uri' => 'hysteria://203.0.113.14:36712?protocol=udp&auth=hy1pass#hy1'],
            ['name' => 'OpenConnect GP', 'proto' => VpnProtocol::OPENCONNECT, 'server' => 'https://vpn.example.com:443/', 'username' => 'oc-user', 'password' => 'oc-pass', 'openconnectFlavor' => 'gp'],
            ['name' => 'OpenVPN UDP', 'proto' => VpnProtocol::OPENVPN, 'config' => "client\ndev tun\nproto udp\nremote 203.0.113.15 1194\n"],
            ['name' => 'AmneziaWG', 'proto' => VpnProtocol::AMNEZIAWG, 'config' => "[Interface]\nPrivateKey = aaa=\nAddress = 10.66.66.2/32\nJc = 4\n"],
            ['name' => 'SSTP офис', 'proto' => VpnProtocol::SSTP, 'server' => 'sstp.example.com:443', 'username' => 'sstp-u', 'password' => 'sstp-p'],
        ];

        foreach ($samples as $sample) {
            $crawler = $this->client->request('GET', '/admin/vpn-connection/new');
            $form = $crawler->filter('form[name="VpnConnection"]')->form();
            $values = $form->getPhpValues();
            foreach ($sample as $k => $v) {
                $values['VpnConnection'][$k] = is_bool($v) ? ($v ? '1' : null) : $v;
            }
            $this->client->request('POST', $form->getUri(), $values);
            self::assertResponseRedirects(message: sprintf('Не сохранился протокол %s', $sample['proto']));
        }

        $this->em->clear();
        $saved = $this->em->getRepository(VpnConnection::class)->findAll();
        self::assertCount(count(VpnProtocol::ALL), $saved);

        $this->client->request('GET', '/admin/vpn-node/export/profiles.json');
        self::assertResponseIsSuccessful();
        $exported = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(9, $exported['profiles']);

        $byName = [];
        foreach ($exported['profiles'] as $item) {
            $byName[$item['name']] = $item;
        }
        self::assertSame('video-call', $byName['Argon4 v5 VideoCall']['argonBehaviorProfile']);
        self::assertArrayNotHasKey('argonBehaviorProfile', $byName['Слепой транзит']);
        self::assertArrayNotHasKey('password', $byName['Слепой транзит']);
        self::assertTrue($byName['VLESS + Argon4']['useArgon']);
        self::assertSame('gp', $byName['OpenConnect GP']['openconnectFlavor']);
    }

    public function testIntermediateNodeChainsValidationAndActivation(): void
    {
        $blindTransit = new VpnConnection();
        $blindTransit->setName('Слепой хоп 1');
        $blindTransit->setProto(VpnProtocol::TRANSIT);
        $blindTransit->setServer('198.51.100.20:51820');

        $exitArgon = new VpnConnection();
        $exitArgon->setName('Выход Argon4 v5');
        $exitArgon->setProto(VpnProtocol::ARGON4);
        $exitArgon->setServer('198.51.100.30:51820');
        $exitArgon->setPassword('exit-secret');
        $exitArgon->setArgonProtocolVersion(5);
        $exitArgon->setArgonBehaviorProfile('video-call');

        $plainTcpOpenConnect = new VpnConnection();
        $plainTcpOpenConnect->setName('Обычный OpenConnect');
        $plainTcpOpenConnect->setProto(VpnProtocol::OPENCONNECT);
        $plainTcpOpenConnect->setServer('https://oc.example.com/');

        $this->em->persist($blindTransit);
        $this->em->persist($exitArgon);
        $this->em->persist($plainTcpOpenConnect);
        $this->em->flush();

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        // 1. Нельзя оканчивать цепочку на транзит.
        $crawler = $this->client->request('GET', '/admin/vpn-chain/new');
        $form = $crawler->filter('form[name="VpnChain"]')->form();
        $values = $form->getPhpValues();
        $values['VpnChain']['name'] = 'Незаконченная цепочка';
        $values['VpnChain']['hops'] = [
            ['position' => '1', 'connection' => (string) $blindTransit->getId()],
        ];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'не может быть последним узлом цепочки');

        // 2. Слепой транзит не может вести в TCP без Argon4-оболочки.
        $values['VpnChain']['name'] = 'Несовместимая цепочка';
        $values['VpnChain']['hops'] = [
            ['position' => '1', 'connection' => (string) $blindTransit->getId()],
            ['position' => '2', 'connection' => (string) $plainTcpOpenConnect->getId()],
        ];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'слепой транзит без пароля поддерживает только');

        // 3. Корректная цепочка: Слепой транзит -> Argon4 v5 video-call.
        $values['VpnChain']['name'] = 'Маскировка через транзит';
        $values['VpnChain']['hops'] = [
            ['position' => '1', 'connection' => (string) $blindTransit->getId()],
            ['position' => '2', 'connection' => (string) $exitArgon->getId()],
        ];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $this->em->clear();
        $chain = $this->em->getRepository(VpnChain::class)->findOneBy(['name' => 'Маскировка через транзит']);
        self::assertNotNull($chain);
        self::assertSame('Слепой хоп 1 → Выход Argon4 v5', $chain->getHopsSummary());

        // Активация цепочки через кнопку формы на странице списка.
        $indexCrawler = $this->client->request('GET', '/admin/vpn-chain');
        self::assertResponseIsSuccessful();
        $activateForm = $indexCrawler->filter('form[action*="/admin/vpn-chain/' . $chain->getId() . '/activate"]')->form();
        $this->client->submit($activateForm);
        self::assertResponseRedirects('/admin/vpn-chain');

        $this->client->request('GET', '/admin/vpn-node/export/profiles.json');
        $exported = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($chain->getExternalId(), $exported['activeChain']);
        self::assertSame([$blindTransit->getExternalId(), $exitArgon->getExternalId()], $exported['chains'][0]['hops']);
    }

    public function testProvisionNodeAsVpnServerPortForwardAndVpnUsers(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        // Превращаем узел в VPN-сервер с Argon4, VLESS и OpenConnect.
        $crawler = $this->client->request('GET', '/admin/vpn-node');
        $provisionForm = $crawler->filter('form[action="/admin/vpn-node/provision"]')->form();
        $values = $provisionForm->getPhpValues();
        $values['public_host'] = 'vpn.node.example';
        $values['wan_interface'] = 'ens3';
        $values['vpn_subnet_cidr'] = '10.44.0.0/24';
        $values['protocols'] = [VpnProtocol::ARGON4, VpnProtocol::VLESS, VpnProtocol::OPENCONNECT];
        $values['default_username'] = 'sergey';
        $values['grant_system_access'] = '1';
        $this->client->request('POST', '/admin/vpn-node/provision', $values);
        self::assertResponseRedirects('/admin/vpn-node');

        $this->em->clear();
        $settings = $this->em->getRepository(NodeSettings::class)->findOneBy([]);
        self::assertNotNull($settings);
        self::assertSame(NodeSettings::MODE_SERVER, $settings->getMode());
        self::assertSame('vpn.node.example', $settings->getPublicHost());

        self::assertCount(3, $this->em->getRepository(VpnServerService::class)->findAll());
        $sergeyVpn = $this->em->getRepository(VpnUser::class)->findOneBy(['username' => 'sergey']);
        self::assertNotNull($sergeyVpn);
        self::assertNotNull($sergeyVpn->getSystemUser());

        $runtimeDir = static::getContainer()->get(NodeRuntimeExporter::class)->getRuntimeDir();
        self::assertFileExists($runtimeDir . '/argon-server.env');
        self::assertFileExists($runtimeDir . '/xray-config.json');
        self::assertFileExists($runtimeDir . '/ocserv.env');
        self::assertFileExists($runtimeDir . '/desired-state.json');

        $argonEnv = (string) file_get_contents($runtimeDir . '/argon-server.env');
        self::assertStringContainsString("ARGON_LISTEN_ADDR='0.0.0.0:51820'", $argonEnv);
        self::assertStringContainsString("ARGON_OUT_INTERFACE='ens3'", $argonEnv);
        self::assertStringContainsString('ARGON_MODE=tunnel', $argonEnv);
        self::assertStringContainsString('ARGON_PROTOCOL_VERSION=5', $argonEnv);

        // В режиме Сервер все серверные разделы доступны.
        $this->client->request('GET', '/admin/vpn-server-service');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/port-forward');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/vpn-user');
        self::assertResponseIsSuccessful();

        // Создание дополнительного пользователя системы + личный profiles.json.
        $crawler = $this->client->request('GET', '/admin/vpn-user/new');
        $userForm = $crawler->filter('form[name="VpnUser"]')->form();
        $userValues = $userForm->getPhpValues();
        $userValues['VpnUser']['username'] = 'elena';
        $userValues['VpnUser']['displayName'] = 'Елена';
        $userValues['VpnUser']['staticVpnIp'] = '10.44.0.25';
        $userValues['VpnUser']['tunnelPassword'] = 'elena-strong-pass';
        $userValues['VpnUser']['grantSystemAccess'] = '1';
        $userValues['VpnUser']['systemEmail'] = 'elena@sync.local';
        $userValues['VpnUser']['systemRole'] = User::ROLE_ADMIN;
        $this->client->request('POST', $userForm->getUri(), $userValues);
        self::assertResponseRedirects();

        $this->em->clear();
        $elenaSys = $this->em->getRepository(User::class)->findOneBy(['email' => 'elena@sync.local']);
        self::assertNotNull($elenaSys);
        self::assertTrue($elenaSys->isAdmin());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($elenaSys, 'elena-strong-pass'));

        $elenaVpn = $this->em->getRepository(VpnUser::class)->findOneBy(['username' => 'elena']);
        self::assertNotNull($elenaVpn);
        $this->client->request('GET', '/admin/vpn-user/' . $elenaVpn->getId() . '/profiles.json');
        self::assertResponseIsSuccessful();
        $personal = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $personal['profiles']);

        // Проверка проброса портов: конфликт с портом Argon4 (51820/UDP) отклонён 422.
        $crawler = $this->client->request('GET', '/admin/port-forward/new');
        $pfForm = $crawler->filter('form[name="PortForwardRule"]')->form();
        $pfValues = $pfForm->getPhpValues();
        $pfValues['PortForwardRule']['name'] = 'Конфликт с Argon4';
        $pfValues['PortForwardRule']['protocol'] = PortForwardRule::PROTO_UDP;
        $pfValues['PortForwardRule']['listenPort'] = '51820';
        $pfValues['PortForwardRule']['targetIp'] = '10.44.0.25';
        $pfValues['PortForwardRule']['targetPort'] = '51820';
        $this->client->request('POST', $pfForm->getUri(), $pfValues);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'уже занят: служба «Сервис Argon4»');

        // Корректный проброс диапазона портов для игрового сервера клиенту Елена.
        $pfValues['PortForwardRule']['name'] = 'Minecraft на Елену';
        $pfValues['PortForwardRule']['protocol'] = PortForwardRule::PROTO_BOTH;
        $pfValues['PortForwardRule']['listenPort'] = '25565';
        $pfValues['PortForwardRule']['listenPortEnd'] = '25566';
        $pfValues['PortForwardRule']['targetIp'] = '10.44.0.25';
        $pfValues['PortForwardRule']['targetPort'] = '25565';
        $this->client->request('POST', $pfForm->getUri(), $pfValues);
        self::assertResponseRedirects();

        $this->client->request('GET', '/admin/vpn-node/export/port-forward.sh');
        self::assertResponseIsSuccessful();
        $script = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('iptables -t nat -A SYNC_DNAT', $script);
        self::assertStringContainsString('--dport 25565:25566', $script);
        self::assertStringContainsString("'10.44.0.25:25565-25566'", $script);

        // Дублирующий проброс на пересекающийся порт отклонён 422.
        $crawler = $this->client->request('GET', '/admin/port-forward/new');
        $pfForm2 = $crawler->filter('form[name="PortForwardRule"]')->form();
        $pfValues2 = $pfForm2->getPhpValues();
        $pfValues2['PortForwardRule']['name'] = 'Пересечение с Minecraft';
        $pfValues2['PortForwardRule']['protocol'] = PortForwardRule::PROTO_TCP;
        $pfValues2['PortForwardRule']['listenPort'] = '25566';
        $pfValues2['PortForwardRule']['targetIp'] = '10.44.0.25';
        $pfValues2['PortForwardRule']['targetPort'] = '25566';
        $this->client->request('POST', $pfForm2->getUri(), $pfValues2);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'уже занят: проброс порта');

        // Создание и валидация серверного слепого транзита + генерация клиентского профиля.
        $crawler = $this->client->request('GET', '/admin/vpn-server-service/new');
        $svcForm = $crawler->filter('form[name="VpnServerService"]')->form();
        $svcValues = $svcForm->getPhpValues();
        $svcValues['VpnServerService']['name'] = 'Слепой транзит на узле';
        $svcValues['VpnServerService']['proto'] = VpnProtocol::TRANSIT;
        $svcValues['VpnServerService']['listenPort'] = '51822';
        $svcValues['VpnServerService']['transportProto'] = 'udp';
        $svcValues['VpnServerService']['transitBlind'] = '1';
        $this->client->request('POST', $svcForm->getUri(), $svcValues);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'обязательно укажите целевой адрес');

        $svcValues['VpnServerService']['transitTarget'] = '198.51.100.55:51820';
        $this->client->request('POST', $svcForm->getUri(), $svcValues);
        self::assertResponseRedirects();

        $this->em->clear();
        $transitSvc = $this->em->getRepository(VpnServerService::class)->findOneBy(['name' => 'Слепой транзит на узле']);
        self::assertNotNull($transitSvc);
        $svcIndexCrawler = $this->client->request('GET', '/admin/vpn-server-service');
        $clientProfileForm = $svcIndexCrawler->filter('form[action*="/admin/vpn-server-service/' . $transitSvc->getId() . '/create-client-profile"]')->form();
        $this->client->submit($clientProfileForm);
        self::assertResponseRedirects('/admin/vpn-connection');

        // Возвращаем узел в режим Клиент -> серверные разделы снова блокируются (403) и port-forward.sh очищается.
        $crawler = $this->client->request('GET', '/admin/vpn-node');
        $settingsForm = $crawler->filter('form[action="/admin/vpn-node/settings"]')->form();
        $settingsValues = $settingsForm->getPhpValues();
        $settingsValues['mode'] = NodeSettings::MODE_CLIENT;
        $this->client->request('POST', '/admin/vpn-node/settings', $settingsValues);
        self::assertResponseRedirects('/admin/vpn-node');

        $this->client->request('GET', '/admin/vpn-user/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/vpn-node/export/port-forward.sh');
        self::assertStringNotContainsString('iptables -t nat -A SYNC_DNAT', (string) $this->client->getResponse()->getContent());
    }

    public function testDefects1And4ProvisionedKeypairsValidationAndHysteria2Userpass(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        // 1. Мастер развёртывания с Reality (VLESS), AmneziaWG и Hysteria2.
        $crawler = $this->client->request('GET', '/admin/vpn-node');
        self::assertResponseIsSuccessful();
        $provisionForm = $crawler->filter('form[action="/admin/vpn-node/provision"]')->form();
        $values = $provisionForm->getPhpValues();
        $values['public_host'] = 'vpn.defects.test';
        $values['wan_interface'] = 'ens3';
        $values['vpn_subnet_cidr'] = '10.44.0.0/24';
        $values['protocols'] = [
            VpnProtocol::ARGON4,
            VpnProtocol::VLESS,
            VpnProtocol::HYSTERIA2,
            VpnProtocol::AMNEZIAWG,
        ];
        $values['default_username'] = 'alice';
        $values['grant_system_access'] = '1';
        $this->client->request('POST', '/admin/vpn-node/provision', $values);
        self::assertResponseRedirects('/admin/vpn-node');

        $this->em->clear();
        $vlessSvc = $this->em->getRepository(VpnServerService::class)->findOneBy(['proto' => VpnProtocol::VLESS]);
        $awgSvc = $this->em->getRepository(VpnServerService::class)->findOneBy(['proto' => VpnProtocol::AMNEZIAWG]);
        $hy2Svc = $this->em->getRepository(VpnServerService::class)->findOneBy(['proto' => VpnProtocol::HYSTERIA2]);
        $alice = $this->em->getRepository(VpnUser::class)->findOneBy(['username' => 'alice']);
        self::assertNotNull($vlessSvc);
        self::assertNotNull($awgSvc);
        self::assertNotNull($hy2Svc);
        self::assertNotNull($alice);

        // Defect 1a: Reality ключи должны быть настоящей X25519-парой.
        $privRaw = VpnProtocol::decodeX25519Key((string) $vlessSvc->getRealityPrivateKey());
        $pubRaw = VpnProtocol::decodeX25519Key((string) $vlessSvc->getRealityPublicKey());
        self::assertNotNull($privRaw);
        self::assertNotNull($pubRaw);
        self::assertSame(sodium_crypto_scalarmult_base($privRaw), $pubRaw);

        // Валидатор формы отклоняет несоответствующий Reality Public Key (422) и авто-вычисляет при очистке.
        $editCrawler = $this->client->request('GET', '/admin/vpn-server-service/' . $vlessSvc->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $svcForm = $editCrawler->filter('form[name="VpnServerService"]')->form();
        $svcValues = $svcForm->getPhpValues();
        $svcValues['VpnServerService']['realityPublicKey'] = rtrim(strtr(base64_encode(str_repeat("\x11", 32)), '+/', '-_'), '=');
        $this->client->request('POST', $svcForm->getUri(), $svcValues);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Reality Public Key');

        $svcValues['VpnServerService']['realityPublicKey'] = '';
        $this->client->request('POST', $svcForm->getUri(), $svcValues);
        self::assertResponseRedirects();
        $this->em->clear();
        $vlessReloaded = $this->em->getRepository(VpnServerService::class)->find($vlessSvc->getId());
        self::assertNotNull($vlessReloaded);
        self::assertSame($vlessSvc->getRealityPublicKey(), $vlessReloaded->getRealityPublicKey());

        // Экспорт VLESS и проверка pbk= в URI.
        $nodeSettings = $this->em->getRepository(\App\Entity\NodeSettings::class)->findOneBy([]);
        self::assertNotNull($nodeSettings);
        $exporter = static::getContainer()->get(CepExportService::class);
        $vlessConn = $exporter->createConnectionFromServerService($vlessReloaded, $nodeSettings, $alice);
        self::assertStringContainsString('pbk=' . $vlessReloaded->getRealityPublicKey(), (string) $vlessConn->getUri());

        $runtimeDir = static::getContainer()->get(NodeRuntimeExporter::class)->getRuntimeDir();
        $xrayJson = json_decode((string) file_get_contents($runtimeDir . '/xray-config.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($vlessReloaded->getRealityPrivateKey(), $xrayJson['inbounds'][0]['streamSettings']['realitySettings']['privateKey'] ?? null);

        // Defect 1b: AmneziaWG серверный и клиентский X25519 ключи взаимно соответствуют друг другу.
        $awgServerConf = (string) file_get_contents($runtimeDir . '/amneziawg-server.conf');
        self::assertSame(1, preg_match('/^PrivateKey\s*=\s*(\S+)/m', $awgServerConf, $srvMatch));
        self::assertSame(1, preg_match('/^PublicKey\s*=\s*(\S+)/m', $awgServerConf, $peerMatch));
        $srvPrivB64 = $srvMatch[1];
        $expectedPeerPubB64 = $peerMatch[1];

        $awgClientConn = $exporter->createConnectionFromServerService($awgSvc, $nodeSettings, $alice);
        $awgClientConf = (string) $awgClientConn->getConfig();
        self::assertSame(1, preg_match('/^PrivateKey\s*=\s*(\S+)/m', $awgClientConf, $cliPrivMatch));
        self::assertSame(1, preg_match('/^PublicKey\s*=\s*(\S+)/m', $awgClientConf, $cliPeerMatch));
        $cliPrivB64 = $cliPrivMatch[1];
        $expectedSrvPubB64 = $cliPeerMatch[1];

        $srvPrivRaw = base64_decode($srvPrivB64, true);
        $cliPrivRaw = base64_decode($cliPrivB64, true);
        self::assertIsString($srvPrivRaw);
        self::assertIsString($cliPrivRaw);
        self::assertSame(base64_encode(sodium_crypto_scalarmult_base($srvPrivRaw)), $expectedSrvPubB64);
        self::assertSame(base64_encode(sodium_crypto_scalarmult_base($cliPrivRaw)), $expectedPeerPubB64);

        // Defect 4: Hysteria2 URI и hysteria2.yaml используют user:pass (alice:tunnelPassword и default:masterPassword).
        $hy2Yaml = (string) file_get_contents($runtimeDir . '/hysteria2.yaml');
        self::assertStringContainsString('type: userpass', $hy2Yaml);
        self::assertStringContainsString('"' . $alice->getUsername() . '":', $hy2Yaml);
        self::assertStringContainsString($alice->getTunnelPassword(), $hy2Yaml);
        self::assertStringContainsString('default:', $hy2Yaml);
        self::assertStringContainsString((string) $hy2Svc->getMasterPassword(), $hy2Yaml);

        $hy2UserConn = $exporter->createConnectionFromServerService($hy2Svc, $nodeSettings, $alice);
        $expectedUserPrefix = sprintf(
            'hysteria2://%s:%s@vpn.defects.test:',
            rawurlencode($alice->getUsername()),
            rawurlencode($alice->getTunnelPassword()),
        );
        self::assertStringStartsWith($expectedUserPrefix, (string) $hy2UserConn->getUri());
        self::assertSame($alice->getUsername(), $hy2UserConn->getUsername());
        self::assertSame($alice->getTunnelPassword(), $hy2UserConn->getPassword());

        $hy2DefaultConn = $exporter->createConnectionFromServerService($hy2Svc, $nodeSettings);
        $expectedDefaultPrefix = sprintf(
            'hysteria2://default:%s@vpn.defects.test:',
            rawurlencode((string) $hy2Svc->getMasterPassword()),
        );
        self::assertStringStartsWith($expectedDefaultPrefix, (string) $hy2DefaultConn->getUri());
        self::assertSame('default', $hy2DefaultConn->getUsername());
        self::assertSame($hy2Svc->getMasterPassword(), $hy2DefaultConn->getPassword());
    }

    public function testDefects2And3RevokingSystemAccessBlocksTokensAndDisablingVpnRunsApplyHook(): void
    {
        $applyLog = tempnam(sys_get_temp_dir(), 'vpn_apply_test_');
        self::assertIsString($applyLog);
        @unlink($applyLog);
        $applyCmd = sprintf(
            'printf "APPLIED:" >> %s && cat "$VPN_RUNTIME_DIR/desired-state.json" >> %s && printf "\n" >> %s',
            escapeshellarg($applyLog),
            escapeshellarg($applyLog),
            escapeshellarg($applyLog),
        );

        try {
            $_SERVER['VPN_RUNTIME_APPLY_CMD'] = $applyCmd;
            $_ENV['VPN_RUNTIME_APPLY_CMD'] = $applyCmd;
            putenv('VPN_RUNTIME_APPLY_CMD=' . $applyCmd);
            static::ensureKernelShutdown();
            $this->client = static::createClient();
            $this->em = static::getContainer()->get('doctrine')->getManager();

            $admin = $this->createUser('admin@example.com', admin: true);
            $this->client->loginUser($admin);

            // Включаем режим Сервер + автоприменение runtime.
            $crawler = $this->client->request('GET', '/admin/vpn-node');
            $provisionForm = $crawler->filter('form[action="/admin/vpn-node/provision"]')->form();
            $pValues = $provisionForm->getPhpValues();
            $pValues['public_host'] = 'vpn.stop.test';
            $pValues['wan_interface'] = 'ens3';
            $pValues['vpn_subnet_cidr'] = '10.44.0.0/24';
            $pValues['protocols'] = [VpnProtocol::ARGON4, VpnProtocol::VLESS];
            $pValues['default_username'] = 'operator';
            $this->client->request('POST', '/admin/vpn-node/provision', $pValues);
            self::assertResponseRedirects('/admin/vpn-node');

            // Создаём VPN-пользователя с системным доступом и получаем реальный API-токен.
            $crawler = $this->client->request('GET', '/admin/vpn-user/new');
            $uForm = $crawler->filter('form[name="VpnUser"]')->form();
            $uValues = $uForm->getPhpValues();
            $uValues['VpnUser']['username'] = 'bob';
            $uValues['VpnUser']['displayName'] = 'Боб';
            $uValues['VpnUser']['staticVpnIp'] = '10.44.0.30';
            $uValues['VpnUser']['tunnelPassword'] = 'BobSecret!123';
            $uValues['VpnUser']['grantSystemAccess'] = '1';
            $uValues['VpnUser']['systemEmail'] = 'bob@sync.local';
            $uValues['VpnUser']['systemRole'] = 'ROLE_USER';
            $this->client->request('POST', $uForm->getUri(), $uValues);
            self::assertResponseRedirects();

            $this->em->clear();
            $bobVpn = $this->em->getRepository(VpnUser::class)->findOneBy(['username' => 'bob']);
            self::assertNotNull($bobVpn);
            $bobSys = $bobVpn->getSystemUser();
            self::assertNotNull($bobSys);
            self::assertTrue($bobSys->isEnabled());

            $loginOk = $this->api('POST', '/api/auth/login', ['email' => 'bob@sync.local', 'password' => 'BobSecret!123']);
            self::assertResponseStatusCodeSame(200);
            $token1 = (string) ($loginOk['token'] ?? '');
            self::assertNotSame('', $token1);
            $this->api('GET', '/api/me', null, $token1);
            self::assertResponseStatusCodeSame(200);

            // Defect 2a: Снятие галочки grantSystemAccess отзывает API-токены и блокирует повторный вход.
            $this->client->loginUser($admin);
            $editCrawler = $this->client->request('GET', '/admin/vpn-user/' . $bobVpn->getId() . '/edit');
            $editForm = $editCrawler->filter('form[name="VpnUser"]')->form();
            $editValues = $editForm->getPhpValues();
            unset($editValues['VpnUser']['grantSystemAccess']);
            $this->client->request('POST', $editForm->getUri(), $editValues);
            self::assertResponseRedirects();

            $this->em->clear();
            $bobSysAfterRevoke = $this->em->getRepository(User::class)->findOneBy(['email' => 'bob@sync.local']);
            self::assertNotNull($bobSysAfterRevoke);
            self::assertFalse($bobSysAfterRevoke->isEnabled());
            self::assertSame([], $bobSysAfterRevoke->getRoles());
            self::assertCount(0, static::getContainer()->get(ApiTokenRepository::class)->findBy(['user' => $bobSysAfterRevoke]));

            $this->api('GET', '/api/me', null, $token1);
            self::assertResponseStatusCodeSame(401);
            $loginBlocked = $this->api('POST', '/api/auth/login', ['email' => 'bob@sync.local', 'password' => 'BobSecret!123']);
            self::assertResponseStatusCodeSame(401);
            self::assertArrayNotHasKey('token', $loginBlocked);

            // Defect 2b: Повторная выдача доступа -> новый токен -> отключение VpnUser.enabled также блокирует.
            $this->client->loginUser($admin);
            $reCrawler = $this->client->request('GET', '/admin/vpn-user/' . $bobVpn->getId() . '/edit');
            $reForm = $reCrawler->filter('form[name="VpnUser"]')->form();
            $reValues = $reForm->getPhpValues();
            $reValues['VpnUser']['grantSystemAccess'] = '1';
            $reValues['VpnUser']['tunnelPassword'] = 'BobSecret!456';
            $this->client->request('POST', $reForm->getUri(), $reValues);
            self::assertResponseRedirects();

            $loginOk2 = $this->api('POST', '/api/auth/login', ['email' => 'bob@sync.local', 'password' => 'BobSecret!456']);
            self::assertResponseStatusCodeSame(200);
            $token2 = (string) ($loginOk2['token'] ?? '');
            self::assertNotSame('', $token2);

            $this->client->loginUser($admin);
            $disCrawler = $this->client->request('GET', '/admin/vpn-user/' . $bobVpn->getId() . '/edit');
            $disForm = $disCrawler->filter('form[name="VpnUser"]')->form();
            $disValues = $disForm->getPhpValues();
            unset($disValues['VpnUser']['enabled']);
            $this->client->request('POST', $disForm->getUri(), $disValues);
            self::assertResponseRedirects();

            $this->api('GET', '/api/me', null, $token2);
            self::assertResponseStatusCodeSame(401);
            $this->api('POST', '/api/auth/login', ['email' => 'bob@sync.local', 'password' => 'BobSecret!456']);
            self::assertResponseStatusCodeSame(401);

            // Defect 2c: Отключение через UserCrudController тоже отзывает токены и блокирует вход.
            $this->client->loginUser($admin);
            $enCrawler = $this->client->request('GET', '/admin/vpn-user/' . $bobVpn->getId() . '/edit');
            $enForm = $enCrawler->filter('form[name="VpnUser"]')->form();
            $enValues = $enForm->getPhpValues();
            $enValues['VpnUser']['enabled'] = '1';
            $enValues['VpnUser']['tunnelPassword'] = 'BobSecret!789';
            $this->client->request('POST', $enForm->getUri(), $enValues);
            self::assertResponseRedirects();

            $loginOk3 = $this->api('POST', '/api/auth/login', ['email' => 'bob@sync.local', 'password' => 'BobSecret!789']);
            self::assertResponseStatusCodeSame(200);
            $token3 = (string) ($loginOk3['token'] ?? '');

            $this->client->loginUser($admin);
            $uEditCrawler = $this->client->request('GET', '/admin/user/' . $bobSysAfterRevoke->getId() . '/edit');
            $uEditForm = $uEditCrawler->filter('form[name="User"]')->form();
            $uEditValues = $uEditForm->getPhpValues();
            unset($uEditValues['User']['enabled']);
            $this->client->request('POST', $uEditForm->getUri(), $uEditValues);
            self::assertResponseRedirects();

            $this->api('GET', '/api/me', null, $token3);
            self::assertResponseStatusCodeSame(401);
            $this->api('POST', '/api/auth/login', ['email' => 'bob@sync.local', 'password' => 'BobSecret!789']);
            self::assertResponseStatusCodeSame(403);

            // Defect 3: Выключение блока VPN через /admin/modules/save применяет остановку runtime-хуком!
            $runtimeDir = static::getContainer()->get(NodeRuntimeExporter::class)->getRuntimeDir();
            self::assertFileExists($runtimeDir . '/xray-config.json');
            self::assertFileExists($runtimeDir . '/argon-server.env');
            @file_put_contents($applyLog, '');

            $this->client->loginUser($admin);
            $modCrawler = $this->client->request('GET', '/admin/modules');
            self::assertResponseIsSuccessful();
            $modForm = $modCrawler->filter('form[action$="/modules/save"]')->form();
            $modForm['vpn']->untick();
            $this->client->submit($modForm);
            self::assertResponseRedirects('/admin/modules');

            self::assertFileDoesNotExist($runtimeDir . '/xray-config.json');
            self::assertFileDoesNotExist($runtimeDir . '/argon-server.env');
            $desired = json_decode((string) file_get_contents($runtimeDir . '/desired-state.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($desired['vpnModuleEnabled']);
            self::assertFalse($desired['server']['enabled']);
            self::assertSame([], $desired['server']['services']);

            self::assertFileExists($applyLog);
            $applyContent = (string) file_get_contents($applyLog);
            self::assertStringContainsString('APPLIED:', $applyContent);
            self::assertStringContainsString('"vpnModuleEnabled": false', $applyContent);
            self::assertStringContainsString('"enabled": false', $applyContent);
        } finally {
            $_SERVER['VPN_RUNTIME_APPLY_CMD'] = '';
            $_ENV['VPN_RUNTIME_APPLY_CMD'] = '';
            putenv('VPN_RUNTIME_APPLY_CMD');
            if (is_file($applyLog)) {
                @unlink($applyLog);
            }
        }
    }
}
