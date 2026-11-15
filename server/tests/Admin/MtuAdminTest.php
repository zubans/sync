<?php

namespace App\Tests\Admin;

use App\Entity\VpnConnection;
use App\Tests\DatabaseWebTestCase;
use App\Vpn\VpnProtocol;

/**
 * Веб-тесты админки «Подобрать MTU» для сохранённых подключений и формы preview.
 */
final class MtuAdminTest extends DatabaseWebTestCase
{
    public function testPerNodeProbeActionAndPreviewEndpoint(): void
    {
        $tcpServer = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($tcpServer, sprintf('TCP listener: %s (%d)', $errstr, $errno));

        try {
            $tcpName = (string) stream_socket_get_name($tcpServer, false);
            [, $tcpPortStr] = explode(':', $tcpName);
            $tcpPort = (int) $tcpPortStr;

            $conn = new VpnConnection();
            $conn->setName('VLESS Admin Probe');
            $conn->setProto(VpnProtocol::VLESS);
            $conn->setUri(sprintf('vless://11111111-2222-4333-8444-555555555555@127.0.0.1:%d?security=none&type=tcp#test', $tcpPort));
            $this->em->persist($conn);
            $this->em->flush();

            $this->client->loginUser($this->createUser('admin@example.com', admin: true));

            // 1. В списке узлов отображается "Авто" и кнопка формы "Подобрать MTU".
            $indexCrawler = $this->client->request('GET', '/admin/vpn-connection');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Авто');
            $probeForm = $indexCrawler->filter('form[action*="/admin/vpn-connection/' . $conn->getId() . '/probe-mtu"]')->form();
            $this->client->submit($probeForm);
            self::assertResponseRedirects('/admin/vpn-connection');

            $this->em->clear();
            $updated = $this->em->getRepository(VpnConnection::class)->find($conn->getId());
            self::assertNotNull($updated);
            self::assertNotNull($updated->getArgonPathMtu());
            self::assertGreaterThanOrEqual(576, $updated->getArgonPathMtu());
            self::assertLessThanOrEqual(1500, $updated->getArgonPathMtu());

            // 2. В profiles.json подобранный argonPathMTU теперь сериализуется.
            $this->client->request('GET', '/admin/vpn-node/export/profiles.json');
            self::assertResponseIsSuccessful();
            $exported = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($updated->getArgonPathMtu(), $exported['profiles'][0]['argonPathMTU']);

            // 3. Страница создания/редактирования содержит кнопку "Подобрать MTU" и CSRF для AJAX preview.
            $editCrawler = $this->client->request('GET', '/admin/vpn-connection/' . $conn->getId() . '/edit');
            self::assertResponseIsSuccessful();
            $editHtml = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('/admin/vpn-connection/probe-mtu-preview', $editHtml);
            self::assertStringContainsString('Подобрать MTU', $editHtml);

            // Вытаскиваем CSRF токен из сгенерированного виджета и тестируем preview POST JSON.
            self::assertSame(1, preg_match('/const csrfToken = ("[^"]+");/', $editHtml, $m));
            $csrfToken = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);

            // Плохой CSRF должен дать 403.
            $this->client->request(
                'POST',
                '/admin/vpn-connection/probe-mtu-preview',
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'wrong-token'],
                content: json_encode(['proto' => VpnProtocol::VLESS, 'uri' => $conn->getUri()], JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(403);

            // Корректный CSRF и живой endpoint возвращают optimal_path_mtu.
            $this->client->request(
                'POST',
                '/admin/vpn-connection/probe-mtu-preview',
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrfToken],
                content: json_encode([
                    'proto' => VpnProtocol::VLESS,
                    'uri' => $conn->getUri(),
                    'connectionId' => $conn->getId(),
                ], JSON_THROW_ON_ERROR),
            );
            self::assertResponseIsSuccessful();
            $previewData = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($previewData['ok']);
            self::assertGreaterThanOrEqual(576, $previewData['optimal_path_mtu']);
            self::assertGreaterThan(0, $previewData['tcp_mss']);
        } finally {
            fclose($tcpServer);
        }
    }
}
