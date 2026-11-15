<?php

namespace App\Tests\Service;

use App\Entity\VpnChain;
use App\Entity\VpnChainHop;
use App\Entity\VpnConnection;
use App\Repository\VpnChainRepository;
use App\Service\CepExportService;
use App\Service\MtuProbeService;
use App\Service\VpnChainValidator;
use App\Tests\DatabaseWebTestCase;
use App\Vpn\VpnProtocol;

/**
 * Интеграционная проверка реального подбора MTU, бюджета цепочек и валидации всех протоколов.
 */
final class MtuProbeServiceTest extends DatabaseWebTestCase
{
    public function testLiveTcpAndUdpSocketProbeChainBudgetsAndExport(): void
    {
        /** @var MtuProbeService $mtuProbe */
        $mtuProbe = static::getContainer()->get(MtuProbeService::class);
        /** @var VpnChainValidator $validator */
        $validator = static::getContainer()->get(VpnChainValidator::class);
        /** @var CepExportService $exporter */
        $exporter = static::getContainer()->get(CepExportService::class);

        $tcpServer = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($tcpServer, sprintf('TCP listener: %s (%d)', $errstr, $errno));
        $udpEchoProc = $this->startUdpEchoServer(maxPayload: 2000);
        $silentUdp = stream_socket_server('udp://127.0.0.1:0', $uErrno, $uErrstr, STREAM_SERVER_BIND);
        self::assertNotFalse($silentUdp, sprintf('Silent UDP listener: %s (%d)', $uErrstr, $uErrno));

        try {
            $tcpName = stream_socket_get_name($tcpServer, false);
            $silentName = stream_socket_get_name($silentUdp, false);
            self::assertIsString($tcpName);
            self::assertIsString($silentName);
            [, $tcpPortStr] = explode(':', $tcpName);
            [, $silentPortStr] = explode(':', $silentName);
            $tcpPort = (int) $tcpPortStr;
            $udpPort = $udpEchoProc['port'];
            $silentPort = (int) $silentPortStr;

            // 1. Слепой транзит с заданным MTU 1350 -> второй слепой транзит (auto) -> VLESS TCP выход.
            $hop1 = new VpnConnection();
            $hop1->setName('Transit-1');
            $hop1->setProto(VpnProtocol::TRANSIT);
            $hop1->setServer('127.0.0.1:' . $udpPort);
            $hop1->setArgonPathMtu(1350);

            $hop2 = new VpnConnection();
            $hop2->setName('Transit-2');
            $hop2->setProto(VpnProtocol::TRANSIT);
            $hop2->setServer('127.0.0.1:' . $udpPort);
            $hop2->setArgonPathMtu(1400);

            $exitTcp = new VpnConnection();
            $exitTcp->setName('VLESS-Exit');
            $exitTcp->setProto(VpnProtocol::VLESS);
            $exitTcp->setUri(sprintf('vless://11111111-2222-4333-8444-555555555555@127.0.0.1:%d?security=none&type=tcp#exit', $tcpPort));

            $chain = new VpnChain();
            $chain->setName('Цепочка MTU');
            $h1 = new VpnChainHop();
            $h1->setPosition(1);
            $h1->setConnection($hop1);
            $h2 = new VpnChainHop();
            $h2->setPosition(2);
            $h2->setConnection($hop2);
            $h3 = new VpnChainHop();
            $h3->setPosition(3);
            $h3->setConnection($exitTcp);
            $chain->addHop($h1);
            $chain->addHop($h2);
            $chain->addHop($h3);

            $this->em->persist($hop1);
            $this->em->persist($hop2);
            $this->em->persist($exitTcp);
            $this->em->persist($chain);
            $this->em->flush();

            // Проверка зондирования UDP транзита (без предшествующих ограничений).
            $probeHop1 = $mtuProbe->probeConnection($hop1, $chain);
            self::assertTrue($probeHop1['ok'], (string) ($probeHop1['error'] ?? ''));
            self::assertStringContainsString('udp-df', (string) $probeHop1['method']);
            self::assertGreaterThanOrEqual(576, $probeHop1['optimal_path_mtu']);
            self::assertLessThanOrEqual(1500, $probeHop1['optimal_path_mtu']);
            self::assertSame($probeHop1['optimal_path_mtu'] - 64, $probeHop1['inner_tun_mtu']);

            // Бюджет hop2 = 1350 - 64 = 1286; бюджет exitTcp после hop1+hop2 = 1286 - 64 = 1222.
            $probeHop2 = $mtuProbe->probeConnection($hop2, $chain);
            self::assertTrue($probeHop2['ok'], (string) ($probeHop2['error'] ?? ''));
            self::assertSame(1286, $probeHop2['preceding_budget']);
            self::assertSame(1286, $probeHop2['optimal_path_mtu']);

            $probeExit = $mtuProbe->probeConnection($exitTcp, $chain);
            self::assertTrue($probeExit['ok'], (string) ($probeExit['error'] ?? ''));
            self::assertStringContainsString('tcp-mss', (string) $probeExit['method']);
            self::assertGreaterThan(0, $probeExit['tcp_mss']);
            self::assertSame(1222, $probeExit['preceding_budget']);
            self::assertSame(1222, $probeExit['optimal_path_mtu']);
            self::assertLessThan($probeExit['optimal_path_mtu'], $probeExit['inner_tun_mtu']);

            // Анализ всей цепочки.
            $summary = $mtuProbe->analyzeChainMtu($chain);
            self::assertTrue($summary['hasExplicitMtu']);
            self::assertSame(1222, $summary['bottleneckOuterMtu']);
            self::assertCount(3, $summary['hops']);

            // Невалидный диапазон MTU должен отклоняться даже для слепого транзита и VLESS без Argon.
            $badTransit = clone $hop1;
            $badTransit->setArgonPathMtu(400);
            self::assertNotEmpty($validator->validateConnection($badTransit));

            $badVless = new VpnConnection();
            $badVless->setName('VLESS bad MTU');
            $badVless->setProto(VpnProtocol::VLESS);
            $badVless->setUri('vless://11111111-2222-4333-8444-555555555555@example.com:443#bad');
            $badVless->setArgonPathMtu(9999);
            self::assertNotEmpty($validator->validateConnection($badVless));

            // Проверка экспорта argonPathMTU для слепого транзита и VLESS + авто (null -> отсутствует).
            $hop1Serialized = $exporter->serializeConnection($hop1);
            self::assertSame(1350, $hop1Serialized['argonPathMTU']);
            $exitAutoSerialized = $exporter->serializeConnection($exitTcp);
            self::assertArrayNotHasKey('argonPathMTU', $exitAutoSerialized);

            $exitTcp->setArgonPathMtu(1222);
            $exitFixedSerialized = $exporter->serializeConnection($exitTcp);
            self::assertSame(1222, $exitFixedSerialized['argonPathMTU']);

            // Проверка диагностики ошибок (неразрешимый домен и закрытый TCP-порт).
            $unresolvable = $mtuProbe->probeEndpoint('nonexistent.invalid', 443, VpnProtocol::ARGON4, 'udp');
            self::assertFalse($unresolvable['ok']);
            self::assertStringContainsString('Не удалось разрешить', (string) $unresolvable['error']);

            $closedPortConn = stream_socket_server('tcp://127.0.0.1:0');
            self::assertNotFalse($closedPortConn);
            $closedName = (string) stream_socket_get_name($closedPortConn, false);
            [, $closedPortStr] = explode(':', $closedName);
            fclose($closedPortConn);

            $refused = $mtuProbe->probeEndpoint('127.0.0.1', (int) $closedPortStr, VpnProtocol::VLESS, 'tcp');
            self::assertFalse($refused['ok']);
        } finally {
            fclose($tcpServer);
            fclose($silentUdp);
            $this->stopSubprocess($udpEchoProc);
        }
    }

    public function testUdpProbeRejectsSilentBlackholeAndDetects1280Bottleneck(): void
    {
        self::bootKernel();
        /** @var \App\Repository\VpnChainRepository $chainRepo */
        $chainRepo = self::getContainer()->get(\App\Repository\VpnChainRepository::class);
        $service = new MtuProbeService($chainRepo, '', '');

        // 1. Тихий UDP-сокет (blackhole) не должен возвращать ложный optimal_path_mtu=1500.
        $silentUdp = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        self::assertIsResource($silentUdp, "Не удалось открыть тихий UDP-сокет: {$errstr}");
        $silentPort = (int) parse_url('udp://' . stream_socket_get_name($silentUdp, false), PHP_URL_PORT);

        // 2. UDP echo-сервер с порогом полезной нагрузки 1252 Б (+28 Б IP/UDP = 1280 Б внешнего Path MTU).
        $bottleneckProc = $this->startUdpEchoServer(maxPayload: 1252);

        try {
            $silentRes = $service->probeEndpoint(
                host: '127.0.0.1',
                port: $silentPort,
                proto: VpnProtocol::AMNEZIAWG,
                transport: 'udp',
            );
            self::assertFalse($silentRes['ok'], 'Тихий UDP-blackhole обязан возвращать ok=false без подстановки 1500.');
            self::assertSame(0, $silentRes['optimal_path_mtu']);

            $bottleneckRes = $service->probeEndpoint(
                host: '127.0.0.1',
                port: $bottleneckProc['port'],
                proto: VpnProtocol::AMNEZIAWG,
                transport: 'udp',
            );
            self::assertTrue($bottleneckRes['ok'], $bottleneckRes['message'] ?? '');
            self::assertSame('udp-df', $bottleneckRes['method']);
            self::assertSame(1280, $bottleneckRes['optimal_path_mtu']);
            self::assertSame(1200, $bottleneckRes['inner_tun_mtu']);

            $transitBottleneckRes = $service->probeEndpoint(
                host: '127.0.0.1',
                port: $bottleneckProc['port'],
                proto: VpnProtocol::TRANSIT,
                transport: 'udp',
            );
            self::assertTrue($transitBottleneckRes['ok'], $transitBottleneckRes['message'] ?? '');
            self::assertSame('udp-df', $transitBottleneckRes['method']);
            self::assertSame(1280, $transitBottleneckRes['optimal_path_mtu']);
        } finally {
            fclose($silentUdp);
            $this->stopSubprocess($bottleneckProc);
        }
    }

    public function testAuthenticatedTransitProbePassesNextHopTargetWithoutTcpFallback(): void
    {
        $goBin = trim((string) shell_exec('which go 2>/dev/null'));
        if ($goBin === '') {
            self::markTestSkipped('Go toolchain не установлен в окружении.');
        }

        self::bootKernel();
        /** @var \App\Repository\VpnChainRepository $chainRepo */
        $chainRepo = self::getContainer()->get(\App\Repository\VpnChainRepository::class);

        $tmpDir = sys_get_temp_dir() . '/mtu_transit_probe_' . bin2hex(random_bytes(4));
        if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            self::fail("Не удалось создать временный каталог {$tmpDir}");
        }

        $cepCliBin = $tmpDir . '/cep-cli';
        $serverBin = $tmpDir . '/argon-tunnel-server';
        $serverProc = null;
        $nextHopSock = null;
        $transitSock = null;

        try {
            $repoRoot = dirname(__DIR__, 3);
            (new \Symfony\Component\Process\Process([$goBin, 'build', '-o', $cepCliBin, './cmd/cep-cli'], $repoRoot . '/cep/argon4'))
                ->setTimeout(120)
                ->mustRun();
            (new \Symfony\Component\Process\Process([$goBin, 'build', '-o', $serverBin, './cmd/argon-tunnel-server'], $repoRoot . '/tunnel-server'))
                ->setTimeout(120)
                ->mustRun();

            // Целевой порт следующего узла (разрешённый в -transit-allow) + порт самого транзита.
            $nextHopSock = stream_socket_server('udp://127.0.0.1:0', $e1, $m1, STREAM_SERVER_BIND);
            self::assertIsResource($nextHopSock, "Не удалось выделить UDP-порт следующего узла: {$m1}");
            $allowedPort = (int) parse_url('udp://' . stream_socket_get_name($nextHopSock, false), PHP_URL_PORT);

            $transitSock = stream_socket_server('udp://127.0.0.1:0', $e2, $m2, STREAM_SERVER_BIND);
            self::assertIsResource($transitSock, "Не удалось выделить UDP-порт транзита: {$m2}");
            $transitPort = (int) parse_url('udp://' . stream_socket_get_name($transitSock, false), PHP_URL_PORT);
            fclose($transitSock);
            $transitSock = null;

            $password = 'transit-secret-' . bin2hex(random_bytes(4));
            $allowedTarget = '127.0.0.1:' . $allowedPort;

            $serverProc = $this->startSubprocess([
                $serverBin,
                '-listen', '127.0.0.1:' . $transitPort,
                '-mode', 'transit',
                '-password', $password,
                '-protocol-version', '5',
                '-transit-allow', $allowedTarget,
            ], $tmpDir);

            // Дожидаемся открытия UDP/TCP сокета транзитного сервера (tcp-порт также слушается в -mode transit).
            $ready = false;
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $probeConn = @stream_socket_client('tcp://127.0.0.1:' . $transitPort, $ce, $cm, 0.2);
                if (is_resource($probeConn)) {
                    fclose($probeConn);
                    $ready = true;
                    break;
                }
                usleep(50000);
            }
            self::assertTrue($ready, 'Транзитный argon-tunnel-server не успел подняться на порту ' . $transitPort);

            $service = new MtuProbeService($chainRepo, '', $cepCliBin);

            $transitConn = new VpnConnection();
            $transitConn->setName('Auth Transit Hop');
            $transitConn->setProto(VpnProtocol::TRANSIT);
            $transitConn->setServer('127.0.0.1:' . $transitPort);
            $transitConn->setPassword($password);
            $transitConn->setArgonProtocolVersion(5);

            $exitConn = new VpnConnection();
            $exitConn->setName('Exit Node');
            $exitConn->setProto(VpnProtocol::VLESS);
            $exitConn->setServer('127.0.0.1:' . $allowedPort);
            $exitConn->setUri('vless://00000000-0000-4000-8000-000000000001@' . $allowedTarget . '?type=tcp#Exit');

            $chain = new VpnChain();
            $chain->setName('Transit Chain');
            $hop1 = new VpnChainHop();
            $hop1->setPosition(1);
            $hop1->setConnection($transitConn);
            $hop2 = new VpnChainHop();
            $hop2->setPosition(2);
            $hop2->setConnection($exitConn);
            $chain->addHop($hop1);
            $chain->addHop($hop2);

            // 1. В составе цепочки MtuProbeService обязан передать Target следующего узла и успешно пройти argon4_plpmtud.
            $okRes = $service->probeConnection($transitConn, $chain);
            self::assertTrue($okRes['ok'], $okRes['message'] ?? '');
            self::assertSame('argon4_plpmtud', $okRes['method']);
            self::assertGreaterThanOrEqual(1280, $okRes['optimal_path_mtu']);

            // 2. Без следующего узла сервер отклоняет запрос без Target; переключения на нерелевантный TCP MSS быть не должно.
            $failRes = $service->probeConnection($transitConn, null);
            self::assertFalse($failRes['ok'], 'Транзит без next-hop Target не должен получать подменный успех через TCP MSS.');
            self::assertSame(0, $failRes['tcp_mss']);
            self::assertSame(0, $failRes['optimal_path_mtu']);
        } finally {
            if (is_resource($transitSock)) {
                fclose($transitSock);
            }
            if (is_resource($nextHopSock)) {
                fclose($nextHopSock);
            }
            if ($serverProc !== null) {
                $this->stopSubprocess($serverProc);
            }
            @unlink($cepCliBin);
            @unlink($serverBin);
            @rmdir($tmpDir);
        }
    }

    /**
     * Запускает реальный UDP echo-сервер в отдельном процессе PHP с ограничением размера UDP payload.
     *
     * @return array{proc: resource, pipes: array<int, resource>, port: int}
     */
    private function startUdpEchoServer(int $maxPayload = 2000): array
    {
        $code = sprintf(
            '$s = stream_socket_server("udp://127.0.0.1:0", $e, $m, STREAM_SERVER_BIND);'
            . 'if (!$s) { exit(1); }'
            . 'fwrite(STDOUT, stream_socket_get_name($s, false) . "\n");'
            . 'fflush(STDOUT);'
            . 'while (true) {'
            . '  $pkt = @stream_socket_recvfrom($s, 65535, 0, $peer);'
            . '  if ($pkt === false || $pkt === "") { usleep(1000); continue; }'
            . '  if (strlen($pkt) > %d) { continue; }'
            . '  @stream_socket_sendto($s, $pkt, 0, $peer);'
            . '}',
            $maxPayload,
        );

        $proc = $this->startSubprocess([PHP_BINARY, '-r', $code]);
        stream_set_blocking($proc['pipes'][1], true);
        $line = trim((string) fgets($proc['pipes'][1]));
        if ($line === '' || !str_contains($line, ':')) {
            $this->stopSubprocess($proc);
            self::fail('Не удалось запустить вспомогательный UDP echo-сервер.');
        }

        $port = (int) parse_url('udp://' . $line, PHP_URL_PORT);
        self::assertGreaterThan(0, $port);
        $proc['port'] = $port;

        return $proc;
    }

    /**
     * @param list<string> $cmd
     * @return array{proc: resource, pipes: array<int, resource>, port: int}
     */
    private function startSubprocess(array $cmd, ?string $cwd = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            self::fail('Не удалось запустить процесс: ' . implode(' ', $cmd));
        }

        return [
            'proc' => $proc,
            'pipes' => $pipes,
            'port' => 0,
        ];
    }

    /**
     * @param array{proc: resource, pipes: array<int, resource>, port?: int} $procInfo
     */
    private function stopSubprocess(array $procInfo): void
    {
        foreach ($procInfo['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($procInfo['proc'])) {
            @proc_terminate($procInfo['proc'], 15);
            @proc_close($procInfo['proc']);
        }
    }
}
