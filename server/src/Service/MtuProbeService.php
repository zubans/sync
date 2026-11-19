<?php

namespace App\Service;

use App\Entity\VpnChain;
use App\Entity\VpnConnection;
use App\Repository\VpnChainRepository;
use App\Vpn\VpnProtocol;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Реальное зондирование оптимального MTU для каждого узла VPN/CEP.
 *
 * Комбинирует бюджет предшествующих транзитов в цепочке, интерфейсный MTU,
 * TCP MSS (TCP_MAXSEG), UDP Don't Fragment (IP_MTU_DISCOVER / IP_DONTFRAG)
 * и (при наличии бинарника cep-cli) точный in-band PLPMTUD протокола Argon4.
 */
final readonly class MtuProbeService
{
    public const MIN_PATH_MTU = 576;
    public const DEFAULT_PATH_MTU = 1500;
    public const MAX_PATH_MTU = 9000;
    private const ARGON_OVERHEAD = 64;

    private const SOL_IP_LEVEL = 0;
    private const SOL_IPV6_LEVEL = 41;
    private const SOL_TCP_LEVEL = 6;
    private const DARWIN_IP_DONTFRAG = 28;
    private const DARWIN_IPV6_DONTFRAG = 62;
    private const LINUX_IP_MTU_DISCOVER = 10;
    private const LINUX_IP_PMTUDISC_DO = 2;
    private const LINUX_IP_MTU = 14;
    private const LINUX_IPV6_MTU_DISCOVER = 23;
    private const TCP_MAXSEG_OPT = 2;

    /**
     * @param string      $projectDir   Корень проекта server/ для поиска локального cep-cli при наличии
     * @param string|null $cepCliBinary Явный путь к cep-cli ('' отключает внешний бинарник)
     */
    public function __construct(
        private VpnChainRepository $chainRepository,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir = '',
        private ?string $cepCliBinary = null,
    ) {
    }

    /**
     * Подбирает оптимальный внешний MTU для сохранённого подключения (с учётом цепочек, куда оно входит).
     *
     * @return array<string, mixed>
     */
    public function probeConnection(VpnConnection $conn, ?VpnChain $chain = null): array
    {
        $endpoint = $this->resolveConnectionEndpoint($conn);
        if (!$endpoint['ok']) {
            return [
                'ok' => false,
                'proto' => $conn->getProto(),
                'server' => $conn->getServer() ?? ($conn->getUri() ?? ''),
                'optimal_path_mtu' => 0,
                'inner_tun_mtu' => 0,
                'interface_mtu' => 0,
                'tcp_mss' => 0,
                'rtt_ms' => 0.0,
                'method' => 'none',
                'preceding_budget' => 0,
                'chain_name' => null,
                'supports_in_band' => $endpoint['usesArgon'] ?? false,
                'message' => $endpoint['error'],
                'error' => $endpoint['error'],
            ];
        }

        $budgetInfo = $this->computePrecedingChainBudget($conn, $chain);
        $precedingBudget = $budgetInfo['precedingBudget'];
        $nextTarget = $conn->getProto() === VpnProtocol::TRANSIT
            ? $this->resolveNextHopTarget($conn, $chain, null, $budgetInfo['chainId'])
            : null;

        // Если установлен cep-cli и узел использует Argon4-хендшейк, получаем in-band PLPMTUD.
        if ($endpoint['usesArgon']) {
            $cliResult = $this->probeViaCepCli($conn, $endpoint, $budgetInfo['precedingOuterMtu'], $nextTarget);
            if ($cliResult !== null) {
                $cliResult['chain_name'] = $budgetInfo['chainName'];
                if ($precedingBudget > 0 && ($cliResult['preceding_budget'] ?? 0) <= 0) {
                    $cliResult['preceding_budget'] = $precedingBudget;
                }

                return $cliResult;
            }
        }

        $result = $this->probeEndpoint(
            host: $endpoint['host'],
            port: $endpoint['port'],
            proto: $conn->getProto(),
            transport: $endpoint['transport'],
            precedingBudget: $precedingBudget,
            usesArgon: $endpoint['usesArgon'],
        );
        $result['chain_name'] = $budgetInfo['chainName'];
        if (($result['ok'] ?? false) && $budgetInfo['chainName'] !== null && $precedingBudget > 0) {
            $result['message'] .= sprintf(' · бюджет после «%s»: %d Б', $budgetInfo['chainName'], $precedingBudget);
        }

        return $result;
    }

    /**
     * Подбирает оптимальный MTU для ещё не сохранённых данных формы (preview перед сохранением).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function probeRawInput(array $payload, ?int $connectionId = null): array
    {
        $draft = new VpnConnection();
        $proto = (string) ($payload['proto'] ?? VpnProtocol::ARGON4);
        if (!VpnProtocol::isSupported($proto)) {
            return $this->errorResult($proto, '', 'Неподдерживаемый тип протокола.');
        }

        $draft->setName((string) ($payload['name'] ?? 'Предпросмотр'));
        $draft->setProto($proto);
        $draft->setServer(isset($payload['server']) ? (string) $payload['server'] : null);
        $draft->setUri(isset($payload['uri']) ? (string) $payload['uri'] : null);
        $draft->setConfig(isset($payload['config']) ? (string) $payload['config'] : null);
        $draft->setPassword(isset($payload['password']) ? (string) $payload['password'] : null);
        $draft->setClientId(isset($payload['clientId']) ? (string) $payload['clientId'] : null);
        $draft->setClientSecret(isset($payload['clientSecret']) ? (string) $payload['clientSecret'] : null);
        $draft->setUseArgon((bool) ($payload['useArgon'] ?? false));
        $draft->setArgonPassword(isset($payload['argonPassword']) ? (string) $payload['argonPassword'] : null);
        if (isset($payload['argonPort']) && is_numeric($payload['argonPort'])) {
            $draft->setArgonPort((int) $payload['argonPort']);
        }
        if (isset($payload['argonProtocolVersion']) && is_numeric($payload['argonProtocolVersion'])) {
            $draft->setArgonProtocolVersion((int) $payload['argonProtocolVersion']);
        }
        if (isset($payload['argonPaddingProfile'])) {
            $draft->setArgonPaddingProfile((string) $payload['argonPaddingProfile']);
        }
        if (isset($payload['argonFirstPacketProfile'])) {
            $draft->setArgonFirstPacketProfile((string) $payload['argonFirstPacketProfile']);
        }

        $chain = isset($payload['chainId']) && is_numeric($payload['chainId'])
            ? $this->chainRepository->find((int) $payload['chainId'])
            : null;
        $explicitTarget = isset($payload['target']) ? trim((string) $payload['target']) : null;

        return $this->probeEndpointFromConnection(
            $draft,
            $connectionId,
            $chain,
            ($explicitTarget !== null && $explicitTarget !== '') ? $explicitTarget : null,
        );
    }

    /**
     * Выполняет живое зондирование конечной точки host:port реальными сокетами без заглушек.
     *
     * @return array<string, mixed>
     */
    public function probeEndpoint(
        string $host,
        int $port,
        string $proto,
        string $transport = 'udp',
        int $precedingBudget = 0,
        bool $usesArgon = false,
        int $timeoutMs = 2500,
    ): array {
        $resolved = $this->resolveHostIp($host);
        if ($resolved === null) {
            return $this->errorResult(
                $proto,
                sprintf('%s:%d', $host, $port),
                sprintf('Не удалось разрешить адрес узла «%s».', $host),
                $precedingBudget,
                $usesArgon,
            );
        }

        $ip = $resolved['ip'];
        $isV6 = $resolved['ipv6'];
        $serverLabel = $isV6 ? sprintf('[%s]:%d', $host, $port) : sprintf('%s:%d', $host, $port);
        $ceiling = $precedingBudget >= self::MIN_PATH_MTU
            ? min(self::MAX_PATH_MTU, $precedingBudget)
            : self::MAX_PATH_MTU;

        $rttMs = 0.0;
        $tcpMss = 0;
        $tcpPathMtu = 0;
        $ifMtu = 0;
        $dfMtu = 0;
        $methods = [];
        $notes = [];

        if ($transport === 'tcp') {
            $tcpProbe = $this->probeTcpEndpoint($ip, $port, $isV6, $timeoutMs);
            if (!$tcpProbe['ok']) {
                return $this->errorResult($proto, $serverLabel, $tcpProbe['error'], $precedingBudget, $usesArgon);
            }
            $rttMs = $tcpProbe['rtt_ms'];
            $tcpMss = $tcpProbe['tcp_mss'];
            $tcpPathMtu = $tcpProbe['tcp_path_mtu'];
            $ifMtu = $tcpProbe['interface_mtu'];
            $methods[] = 'tcp-mss';

            $udpProbe = $this->probeUdpDf($ip, $port, $isV6, $ceiling, $ifMtu, $proto, min(400, $timeoutMs));
            if (($udpProbe['ok'] ?? false) && ($udpProbe['df_mtu'] ?? 0) > 0) {
                $dfMtu = $udpProbe['df_mtu'];
                $methods[] = 'udp-df';
            }
        } else {
            $udpProbe = $this->probeUdpDf($ip, $port, $isV6, $ceiling, 0, $proto, $timeoutMs);
            if (!$udpProbe['ok']) {
                return $this->errorResult($proto, $serverLabel, $udpProbe['error'], $precedingBudget, $usesArgon);
            }
            $rttMs = $udpProbe['rtt_ms'];
            $dfMtu = $udpProbe['df_mtu'];
            $ifMtu = $udpProbe['interface_mtu'];
            $methods[] = 'udp-df';
        }

        // Выбираем оптимальный Path MTU: минимальный реальный лимит из DF, TCP MSS, интерфейса и бюджета цепочки.
        $candidates = [];
        if ($ifMtu >= self::MIN_PATH_MTU && $ifMtu <= self::MAX_PATH_MTU) {
            $candidates[] = $ifMtu;
        }
        if ($dfMtu >= self::MIN_PATH_MTU && $dfMtu <= self::MAX_PATH_MTU) {
            $candidates[] = $dfMtu;
        }
        if ($tcpPathMtu >= self::MIN_PATH_MTU && $tcpPathMtu <= self::MAX_PATH_MTU) {
            $candidates[] = $tcpPathMtu;
        }

        $optimal = $candidates !== [] ? min($candidates) : self::DEFAULT_PATH_MTU;
        if ($precedingBudget >= self::MIN_PATH_MTU) {
            $optimal = min($optimal, $precedingBudget);
        }
        $optimal = max(self::MIN_PATH_MTU, min(self::MAX_PATH_MTU, $optimal));
        $inner = $this->computeInnerTunMtu($optimal, $proto, $usesArgon);
        $methodStr = implode('+', $methods);

        $msg = sprintf(
            'Подобран внешний MTU %d Б (внутренний TUN: %d Б, %s, RTT %.1f мс)',
            $optimal,
            $inner,
            $methodStr,
            $rttMs,
        );
        if ($notes !== []) {
            $msg .= ' — ' . implode('; ', $notes);
        }

        return [
            'ok' => true,
            'proto' => $proto,
            'server' => $serverLabel,
            'optimal_path_mtu' => $optimal,
            'inner_tun_mtu' => $inner,
            'interface_mtu' => $ifMtu,
            'tcp_mss' => $tcpMss,
            'rtt_ms' => round($rttMs, 2),
            'method' => $methodStr,
            'preceding_budget' => $precedingBudget,
            'chain_name' => null,
            'supports_in_band' => $usesArgon,
            'message' => $msg,
            'error' => null,
        ];
    }

    /**
     * Рассчитывает бюджет MTU для заданного подключения по цепочке (или самой узкой активной цепочке).
     *
     * @return array{precedingOuterMtu: int, precedingBudget: int, chainName: string|null, chainId: int|null}
     */
    public function computePrecedingChainBudget(VpnConnection $target, ?VpnChain $explicitChain = null, ?int $targetIdOverride = null): array
    {
        $chains = $explicitChain !== null ? [$explicitChain] : $this->chainRepository->findEnabledOrdered();
        $targetId = $targetIdOverride ?? $target->getId();

        $bestOuter = 0;
        $bestBudget = 0;
        $bestChainName = null;
        $bestChainId = null;

        foreach ($chains as $chain) {
            $hops = $chain->getHops()->toArray();
            usort($hops, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());
            $hopCount = count($hops);

            $prev = 0;
            foreach ($hops as $idx => $hop) {
                $conn = $hop->getConnection();
                if ($conn === null) {
                    continue;
                }
                if ($this->matchesConnection($conn, $target, $targetId)) {
                    if ($prev > 0) {
                        $budget = max(self::MIN_PATH_MTU, $prev - self::ARGON_OVERHEAD);
                        if ($bestBudget === 0 || $budget < $bestBudget) {
                            $bestOuter = $prev;
                            $bestBudget = $budget;
                            $bestChainName = $chain->getName();
                            $bestChainId = $chain->getId();
                        }
                    }
                    break;
                }

                if ($conn->getProto() === VpnProtocol::TRANSIT || $idx < $hopCount - 1) {
                    $outer = $conn->getArgonPathMtu() ?? self::DEFAULT_PATH_MTU;
                    $prev = $prev > 0 ? max(self::MIN_PATH_MTU, min($outer, $prev) - self::ARGON_OVERHEAD) : $outer;
                } else {
                    $prev = 0;
                }
            }
        }

        return [
            'precedingOuterMtu' => $bestOuter,
            'precedingBudget' => $bestBudget,
            'chainName' => $bestChainName,
            'chainId' => $bestChainId,
        ];
    }

    /**
     * Находит адрес следующего узла (host:port) в цепочке после указанного транзитного узла.
     */
    private function resolveNextHopTarget(
        VpnConnection $target,
        ?VpnChain $explicitChain = null,
        ?int $targetIdOverride = null,
        ?int $preferredChainId = null,
    ): ?string {
        $chains = $explicitChain !== null ? [$explicitChain] : $this->chainRepository->findEnabledOrdered();
        if ($preferredChainId !== null && $explicitChain === null) {
            usort(
                $chains,
                static fn (VpnChain $a, VpnChain $b) => ($a->getId() === $preferredChainId ? -1 : 0) <=> ($b->getId() === $preferredChainId ? -1 : 0),
            );
        }
        $targetId = $targetIdOverride ?? $target->getId();

        foreach ($chains as $chain) {
            $hops = $chain->getHops()->toArray();
            usort($hops, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());
            $hopCount = count($hops);

            foreach ($hops as $idx => $hop) {
                $conn = $hop->getConnection();
                if ($conn === null || !$this->matchesConnection($conn, $target, $targetId)) {
                    continue;
                }
                if ($idx + 1 >= $hopCount) {
                    break;
                }
                $nextConn = $hops[$idx + 1]->getConnection();
                if ($nextConn === null) {
                    break;
                }
                $nextEndpoint = $this->resolveConnectionEndpoint($nextConn);
                if (!($nextEndpoint['ok'] ?? false)) {
                    break;
                }

                return $this->formatHostPort($nextEndpoint['host'], $nextEndpoint['port']);
            }
        }

        return null;
    }

    private function formatHostPort(string $host, int $port): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            return sprintf('[%s]:%d', $host, $port);
        }

        return sprintf('%s:%d', $host, $port);
    }

    /**
     * Рассчитывает сквозной бюджет MTU по всем шагам цепочки (для отображения на странице цепочек).
     *
     * @return array{summary: string, hasExplicitMtu: bool, bottleneckOuterMtu: int, recommendedInnerMtu: int, hops: list<array<string, mixed>>}
     */
    public function analyzeChainMtu(VpnChain $chain): array
    {
        $hops = $chain->getHops()->toArray();
        usort($hops, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());

        $hasExplicit = false;
        $prevBudget = 0;
        $bottleneck = self::DEFAULT_PATH_MTU;
        $hopDetails = [];

        foreach ($hops as $hop) {
            $conn = $hop->getConnection();
            if ($conn === null) {
                continue;
            }
            $explicit = $conn->getArgonPathMtu();
            if ($explicit !== null) {
                $hasExplicit = true;
            }
            $effectiveOuter = $explicit ?? self::DEFAULT_PATH_MTU;
            if ($prevBudget > 0) {
                $effectiveOuter = min($effectiveOuter, $prevBudget);
            }
            $effectiveOuter = max(self::MIN_PATH_MTU, $effectiveOuter);
            $bottleneck = min($bottleneck, $effectiveOuter);

            $usesArgon = $conn->getProto() === VpnProtocol::ARGON4
                || $conn->getProto() === VpnProtocol::TRANSIT
                || $conn->isUseArgon();
            $inner = $this->computeInnerTunMtu($effectiveOuter, $conn->getProto(), $usesArgon);

            $hopDetails[] = [
                'name' => $conn->getName(),
                'proto' => $conn->getProto(),
                'explicitMtu' => $explicit,
                'effectiveOuterMtu' => $effectiveOuter,
                'innerBudgetMtu' => $inner,
            ];

            $prevBudget = $inner;
        }

        $recommendedInner = $hopDetails !== [] ? $hopDetails[array_key_last($hopDetails)]['innerBudgetMtu'] : 1436;
        if ($hopDetails === []) {
            $summary = 'Нет узлов';
        } elseif (!$hasExplicit) {
            $summary = sprintf('Авто (PLPMTUD + DF при старте · расч. TUN ≤ %d Б)', $recommendedInner);
        } else {
            $summary = sprintf('Лимит %d Б → выходной TUN %d Б', $bottleneck, $recommendedInner);
        }

        return [
            'summary' => $summary,
            'hasExplicitMtu' => $hasExplicit,
            'bottleneckOuterMtu' => $bottleneck,
            'recommendedInnerMtu' => $recommendedInner,
            'hops' => $hopDetails,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeEndpointFromConnection(
        VpnConnection $draft,
        ?int $connectionId,
        ?VpnChain $chain = null,
        ?string $explicitTarget = null,
    ): array {
        $endpoint = $this->resolveConnectionEndpoint($draft);
        if (!$endpoint['ok']) {
            return $this->errorResult(
                $draft->getProto(),
                $draft->getServer() ?? ($draft->getUri() ?? ''),
                $endpoint['error'],
                0,
                $endpoint['usesArgon'] ?? false,
            );
        }

        $budgetInfo = $this->computePrecedingChainBudget($draft, $chain, $connectionId);
        $precedingBudget = $budgetInfo['precedingBudget'];
        $nextTarget = $explicitTarget;
        if ($nextTarget === null && $draft->getProto() === VpnProtocol::TRANSIT) {
            $nextTarget = $this->resolveNextHopTarget($draft, $chain, $connectionId, $budgetInfo['chainId']);
        }

        if ($endpoint['usesArgon']) {
            $cliResult = $this->probeViaCepCli($draft, $endpoint, $budgetInfo['precedingOuterMtu'], $nextTarget);
            if ($cliResult !== null) {
                $cliResult['chain_name'] = $budgetInfo['chainName'];
                if ($precedingBudget > 0 && ($cliResult['preceding_budget'] ?? 0) <= 0) {
                    $cliResult['preceding_budget'] = $precedingBudget;
                }

                return $cliResult;
            }
        }

        $result = $this->probeEndpoint(
            host: $endpoint['host'],
            port: $endpoint['port'],
            proto: $draft->getProto(),
            transport: $endpoint['transport'],
            precedingBudget: $precedingBudget,
            usesArgon: $endpoint['usesArgon'],
        );
        $result['chain_name'] = $budgetInfo['chainName'];

        return $result;
    }

    /**
     * @return array{ok: true, host: string, port: int, transport: string, usesArgon: bool}|array{ok: false, error: string, usesArgon?: bool}
     */
    public function resolveConnectionEndpoint(VpnConnection $conn): array
    {
        $proto = $conn->getProto();
        $usesArgon = $proto === VpnProtocol::ARGON4
            || $proto === VpnProtocol::TRANSIT
            || ($conn->isUseArgon() && VpnProtocol::supportsArgonUnderlay($proto));

        $rawHost = null;
        $rawPort = null;
        $transport = VpnProtocol::defaultTransportProto($proto, $conn->getConfig());

        if ($conn->isUseArgon() && $proto !== VpnProtocol::ARGON4 && $proto !== VpnProtocol::TRANSIT) {
            // При Argon4-надстройке внешний транспорт всегда UDP к шлюзу Argon4 (сервер или адрес из URI/конфига).
            $parsed = $this->extractHostPort($conn->getServer())
                ?? $this->extractHostPortFromUri($conn->getUri())
                ?? $this->extractHostPortFromConfig($proto, $conn->getConfig());
            if ($parsed === null) {
                return ['ok' => false, 'error' => 'Не указан адрес или URI узла для подбора MTU.', 'usesArgon' => true];
            }

            return [
                'ok' => true,
                'host' => $parsed['host'],
                // Порт обёртки Argon4 на узле, а не порт внутренней службы из URI/конфига.
                'port' => $conn->getEffectiveArgonPort(),
                'transport' => 'udp',
                'usesArgon' => true,
            ];
        }

        $parsed = match ($proto) {
            VpnProtocol::VLESS, VpnProtocol::HYSTERIA2, VpnProtocol::HYSTERIA => $this->extractHostPortFromUri($conn->getUri()),
            VpnProtocol::OPENVPN, VpnProtocol::AMNEZIAWG => $this->extractHostPortFromConfig($proto, $conn->getConfig()),
            default => $this->extractHostPort($conn->getServer()),
        };

        if ($parsed === null) {
            return [
                'ok' => false,
                'error' => sprintf('Не удалось извлечь адрес и порт узла для протокола %s.', VpnProtocol::label($proto)),
                'usesArgon' => $usesArgon,
            ];
        }

        $rawHost = $parsed['host'];
        $rawPort = $parsed['port'] ?? VpnProtocol::defaultPort($proto);

        return [
            'ok' => true,
            'host' => $rawHost,
            'port' => $rawPort,
            'transport' => $transport,
            'usesArgon' => $usesArgon,
        ];
    }

    /**
     * @param array{host: string, port: int, transport: string, usesArgon: bool} $endpoint
     * @return array<string, mixed>|null
     */
    private function probeViaCepCli(
        VpnConnection $conn,
        array $endpoint,
        int $precedingOuterMtu,
        ?string $nextTarget = null,
    ): ?array {
        $bin = $this->locateCepCliBinary();
        if ($bin === null) {
            return null;
        }

        $proto = $conn->getProto();
        $isTransit = $proto === VpnProtocol::TRANSIT;
        $effectivePassword = ($conn->getPassword() !== null && $conn->getPassword() !== '')
            ? $conn->getPassword()
            : (($proto === VpnProtocol::ARGON4 || $isTransit) ? $conn->getArgonPassword() : null);
        $hasPassword = $effectivePassword !== null && $effectivePassword !== '';
        $serverAddr = $this->formatHostPort($endpoint['host'], $endpoint['port']);
        $wireguardConfig = $proto === VpnProtocol::AMNEZIAWG ? $conn->getConfig() : null;
        $openvpnConfig = $proto === VpnProtocol::OPENVPN ? $conn->getConfig() : null;

        $req = array_filter([
            'proto' => $proto,
            'server' => $serverAddr,
            'link' => $conn->getUri(),
            'wireguard_config' => $wireguardConfig,
            'openvpn_config' => $openvpnConfig,
            'password' => $effectivePassword,
            'argon_password' => $conn->getArgonPassword(),
            'use_argon' => $conn->isUseArgon(),
            'argon_port' => $conn->isUseArgon() && $proto !== VpnProtocol::ARGON4 && $proto !== VpnProtocol::TRANSIT
                ? $endpoint['port']
                : null,
            'client_id' => $conn->getClientId(),
            'client_secret' => $conn->getClientSecret(),
            'protocol_version' => $conn->getArgonProtocolVersion(),
            'padding_profile' => $conn->getArgonPaddingProfile(),
            'first_packet_profile' => $conn->getArgonFirstPacketProfile(),
            'argon_protocol_version' => $conn->getArgonProtocolVersion(),
            'argon_padding_profile' => $conn->getArgonPaddingProfile(),
            'argon_first_packet_profile' => $conn->getArgonFirstPacketProfile(),
            'mode' => ($isTransit && $hasPassword) ? 'transit' : null,
            'target' => $isTransit ? $nextTarget : null,
            'transit_proto' => $isTransit ? 'udp' : null,
            'preceding_mtu' => $precedingOuterMtu,
            'timeout_ms' => 3500,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== false && $v !== 0);

        $process = new Process([$bin, 'probe-mtu', '-req-json', json_encode($req, JSON_THROW_ON_ERROR), '-json']);
        $process->setTimeout(6.0);
        $process->run();

        $out = trim($process->getOutput());
        if ($out === '') {
            return null;
        }

        $decoded = json_decode($out, true);
        if (!is_array($decoded) || (!isset($decoded['optimal_path_mtu']) && !array_key_exists('ok', $decoded))) {
            return null;
        }

        $ok = (bool) ($decoded['ok'] ?? false);
        $error = $decoded['error'] ?? null;
        $message = (string) ($decoded['message'] ?? '');
        if (!$ok && $message === '' && is_string($error) && $error !== '') {
            $message = $error;
        }

        return [
            'ok' => $ok,
            'proto' => (string) ($decoded['proto'] ?? $proto),
            'server' => (string) ($decoded['server'] ?? $serverAddr),
            'optimal_path_mtu' => (int) ($decoded['optimal_path_mtu'] ?? 0),
            'inner_tun_mtu' => (int) ($decoded['inner_tun_mtu'] ?? 0),
            'interface_mtu' => (int) ($decoded['interface_mtu'] ?? 0),
            'tcp_mss' => 0,
            'rtt_ms' => (float) ($decoded['rtt_ms'] ?? 0.0),
            'method' => (string) ($decoded['method'] ?? 'cep-cli'),
            'preceding_budget' => (int) ($decoded['preceding_budget'] ?? 0),
            'supports_in_band' => (bool) ($decoded['supports_in_band'] ?? true),
            'message' => $message,
            'error' => $error,
        ];
    }

    private function locateCepCliBinary(): ?string
    {
        if ($this->cepCliBinary !== null) {
            if ($this->cepCliBinary === '') {
                return null;
            }

            return (is_file($this->cepCliBinary) && is_executable($this->cepCliBinary)) ? $this->cepCliBinary : null;
        }

        $candidates = array_filter([
            '/usr/local/bin/cep-cli',
            '/opt/homebrew/bin/cep-cli',
            '/usr/bin/cep-cli',
            $this->projectDir !== '' ? $this->projectDir . '/../cep/argon4/cep-cli' : null,
        ]);

        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array{ok: true, rtt_ms: float, tcp_mss: int, tcp_path_mtu: int, interface_mtu: int}|array{ok: false, error: string}
     */
    private function probeTcpEndpoint(string $ip, int $port, bool $isV6, int $timeoutMs): array
    {
        $domain = $isV6 ? AF_INET6 : AF_INET;
        $sock = @socket_create($domain, SOCK_STREAM, SOL_TCP);
        if ($sock === false) {
            return ['ok' => false, 'error' => 'Не удалось создать TCP-сокет для подбора MTU.'];
        }

        try {
            socket_set_nonblock($sock);
            $startNs = hrtime(true);
            $connected = @socket_connect($sock, $ip, $port);
            if (!$connected) {
                $err = socket_last_error($sock);
                $inProgress = defined('SOCKET_EINPROGRESS') ? SOCKET_EINPROGRESS : 36;
                $already = defined('SOCKET_EALREADY') ? SOCKET_EALREADY : 37;
                if ($err !== $inProgress && $err !== $already && $err !== 115) {
                    return ['ok' => false, 'error' => sprintf('TCP-узел %s:%d недоступен (%s).', $ip, $port, socket_strerror($err))];
                }
                $write = [$sock];
                $read = null;
                $except = [$sock];
                $sec = intdiv($timeoutMs, 1000);
                $usec = ($timeoutMs % 1000) * 1000;
                $ready = @socket_select($read, $write, $except, $sec, $usec);
                if ($ready === false || $ready === 0) {
                    return ['ok' => false, 'error' => sprintf('Таймаут TCP-подключения к %s:%d (%d мс).', $ip, $port, $timeoutMs)];
                }
                $soErr = @socket_get_option($sock, SOL_SOCKET, SO_ERROR);
                if (is_int($soErr) && $soErr !== 0) {
                    return ['ok' => false, 'error' => sprintf('TCP-узел %s:%d отклонил соединение (%s).', $ip, $port, socket_strerror($soErr))];
                }
            }
            $rttMs = (hrtime(true) - $startNs) / 1_000_000.0;

            $localIp = null;
            @socket_getsockname($sock, $localIp);
            $ifMtu = is_string($localIp) ? $this->detectInterfaceMtu($localIp) : 0;

            $rawMss = @socket_get_option($sock, self::SOL_TCP_LEVEL, self::TCP_MAXSEG_OPT);
            $mss = is_int($rawMss) && $rawMss > 0 ? $rawMss : 0;
            $tcpPathMtu = 0;
            if ($mss > 0) {
                $hdr = $isV6 ? 60 : 40;
                $candidate = $mss + $hdr;
                // Виртуальный loopback (например 16384 на macOS / 65536 на Linux) не должен завышать реальный потолок выше 9000.
                $tcpPathMtu = $candidate <= self::MAX_PATH_MTU
                    ? max(self::MIN_PATH_MTU, $candidate)
                    : self::DEFAULT_PATH_MTU;
            }

            return [
                'ok' => true,
                'rtt_ms' => max(0.1, $rttMs),
                'tcp_mss' => $mss,
                'tcp_path_mtu' => $tcpPathMtu,
                'interface_mtu' => $ifMtu,
            ];
        } finally {
            @socket_close($sock);
        }
    }

    /**
     * @return array{ok: true, rtt_ms: float, df_mtu: int, interface_mtu: int, icmp_refused: bool}|array{ok: false, error: string}
     */
    private function probeUdpDf(
        string $ip,
        int $port,
        bool $isV6,
        int $ceiling,
        int $knownIfMtu,
        string $proto = '',
        int $timeoutMs = 2500,
    ): array {
        $domain = $isV6 ? AF_INET6 : AF_INET;
        $sock = @socket_create($domain, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return ['ok' => false, 'error' => 'Не удалось создать UDP-сокет для подбора MTU.'];
        }

        try {
            $this->enableDontFragment($sock, $isV6);

            if (!@socket_connect($sock, $ip, $port)) {
                $err = socket_last_error($sock);

                return ['ok' => false, 'error' => sprintf('UDP-маршрут к %s:%d недоступен (%s).', $ip, $port, socket_strerror($err))];
            }

            $localIp = null;
            @socket_getsockname($sock, $localIp);
            $ifMtu = $knownIfMtu > 0 ? $knownIfMtu : (is_string($localIp) ? $this->detectInterfaceMtu($localIp) : 0);

            $maxTest = $ifMtu >= self::MIN_PATH_MTU && $ifMtu <= self::MAX_PATH_MTU
                ? min($ceiling, $ifMtu)
                : min($ceiling, self::DEFAULT_PATH_MTU);
            $maxTest = max(self::MIN_PATH_MTU, $maxTest);

            $hdrOverhead = $isV6 ? 48 : 28;
            $emsgsize = PHP_OS_FAMILY === 'Darwin' ? 40 : 90;
            $econnrefused = PHP_OS_FAMILY === 'Darwin' ? 61 : 111;
            $isQuic = $proto === VpnProtocol::HYSTERIA2 || $proto === VpnProtocol::HYSTERIA;

            $seq = 0;
            $kernelMtuLimit = null;
            $initialWaitMs = max(140, min(350, intdiv($timeoutMs, 6)));

            // 1. Сначала пробуем верхнюю границу и требуем реального ответного подтверждения от удалённого узла.
            $upperProbe = $this->probeUdpSingleSize(
                $sock,
                $maxTest,
                $hdrOverhead,
                $proto,
                $seq,
                $initialWaitMs,
                $emsgsize,
                $econnrefused,
                $isV6,
                $kernelMtuLimit,
            );

            if ($upperProbe['status'] === 'refused') {
                return [
                    'ok' => false,
                    'error' => sprintf('UDP-порт %s:%d недоступен (ICMP Port Unreachable).', $ip, $port),
                ];
            }
            if ($upperProbe['status'] === 'error') {
                return ['ok' => false, 'error' => $upperProbe['error'] ?? 'Ошибка UDP-зондирования MTU.'];
            }
            if ($upperProbe['status'] === 'ok') {
                $bestMtu = $maxTest;
                if ($kernelMtuLimit !== null && $kernelMtuLimit >= self::MIN_PATH_MTU) {
                    $bestMtu = min($bestMtu, $kernelMtuLimit);
                }

                return [
                    'ok' => true,
                    'rtt_ms' => max(0.1, $upperProbe['rtt_ms']),
                    'df_mtu' => $bestMtu,
                    'interface_mtu' => $ifMtu,
                    'icmp_refused' => false,
                ];
            }

            // 2. Верхний размер не подтверждён — обязательно проверяем базовый рабочий порог.
            $floorCandidates = $isQuic
                ? array_values(array_unique([min($maxTest, max(self::MIN_PATH_MTU, 1200 + $hdrOverhead)), self::MIN_PATH_MTU]))
                : [self::MIN_PATH_MTU];

            $confirmedFloor = null;
            $rttMs = 0.0;
            $floorWaitMs = max(180, min(450, intdiv($timeoutMs, 4)));

            foreach ($floorCandidates as $candidateFloor) {
                for ($attempt = 0; $attempt < 2; ++$attempt) {
                    $floorProbe = $this->probeUdpSingleSize(
                        $sock,
                        $candidateFloor,
                        $hdrOverhead,
                        $proto,
                        $seq,
                        $floorWaitMs,
                        $emsgsize,
                        $econnrefused,
                        $isV6,
                        $kernelMtuLimit,
                    );
                    if ($floorProbe['status'] === 'refused') {
                        return [
                            'ok' => false,
                            'error' => sprintf('UDP-порт %s:%d недоступен (ICMP Port Unreachable).', $ip, $port),
                        ];
                    }
                    if ($floorProbe['status'] === 'error') {
                        return ['ok' => false, 'error' => $floorProbe['error'] ?? 'Ошибка UDP-зондирования MTU.'];
                    }
                    if ($floorProbe['status'] === 'ok') {
                        $confirmedFloor = $candidateFloor;
                        $rttMs = $floorProbe['rtt_ms'];
                        break 2;
                    }
                    if ($floorProbe['status'] === 'too_big') {
                        break;
                    }
                }
            }

            if ($confirmedFloor === null) {
                return [
                    'ok' => false,
                    'error' => sprintf(
                        'UDP-узел %s:%d не подтвердил доставку ни при %d Б, ни при минимальном MTU %d Б (нет ответа).',
                        $ip,
                        $port,
                        $maxTest,
                        self::MIN_PATH_MTU,
                    ),
                ];
            }

            $bestMtu = $confirmedFloor;
            $low = $confirmedFloor;
            $high = $maxTest - 1;
            if ($kernelMtuLimit !== null && $kernelMtuLimit >= $low && $kernelMtuLimit < $high) {
                $high = $kernelMtuLimit;
            }

            $stepWaitMs = max(140, min(400, (int) ceil($rttMs * 4.0 + 120.0), max(140, intdiv($timeoutMs, 3))));
            $searchStartNs = hrtime(true);
            $deadlineNs = max(3500, $timeoutMs * 2) * 1_000_000;

            while ($low < $high && (hrtime(true) - $searchStartNs) < $deadlineNs) {
                if (($high - $low) > 16) {
                    $mid = intdiv($low + $high + 1, 2) & ~3;
                    if ($mid <= $low) {
                        $mid = $low + 1;
                    }
                } else {
                    $mid = intdiv($low + $high + 1, 2);
                }

                $step = $this->probeUdpSingleSize(
                    $sock,
                    $mid,
                    $hdrOverhead,
                    $proto,
                    $seq,
                    $stepWaitMs,
                    $emsgsize,
                    $econnrefused,
                    $isV6,
                    $kernelMtuLimit,
                );
                if ($step['status'] === 'timeout' && ($high - $low) <= 32) {
                    // На финальной доводке перепроверяем таймаут один раз на случай единичной потери UDP.
                    $step = $this->probeUdpSingleSize(
                        $sock,
                        $mid,
                        $hdrOverhead,
                        $proto,
                        $seq,
                        $stepWaitMs,
                        $emsgsize,
                        $econnrefused,
                        $isV6,
                        $kernelMtuLimit,
                    );
                }

                if ($step['status'] === 'ok') {
                    $bestMtu = $mid;
                    $low = $mid;
                    $rttMs = $step['rtt_ms'];
                } elseif ($step['status'] === 'refused') {
                    return [
                        'ok' => false,
                        'error' => sprintf('UDP-порт %s:%d отклонил трафик при подборе MTU.', $ip, $port),
                    ];
                } elseif ($step['status'] === 'error') {
                    break;
                } else {
                    if ($kernelMtuLimit !== null && $kernelMtuLimit >= $low && $kernelMtuLimit < $mid) {
                        $high = $kernelMtuLimit;
                    } else {
                        $high = $mid - 1;
                    }
                }
            }

            return [
                'ok' => true,
                'rtt_ms' => max(0.1, $rttMs),
                'df_mtu' => $bestMtu,
                'interface_mtu' => $ifMtu,
                'icmp_refused' => false,
            ];
        } finally {
            @socket_close($sock);
        }
    }

    /**
     * Выполняет отправку одного UDP DF-зонда указанного IP MTU и ждёт подтверждённый ответ узла.
     *
     * @return array{status: 'ok'|'too_big'|'timeout'|'refused'|'error', rtt_ms: float, error?: string}
     */
    private function probeUdpSingleSize(
        \Socket $sock,
        int $mtu,
        int $hdrOverhead,
        string $proto,
        int &$seq,
        int $waitMs,
        int $emsgsize,
        int $econnrefused,
        bool $isV6,
        ?int &$kernelMtuLimit,
    ): array {
        // Очищаем просроченные ответы от прошлых шагов, чтобы запоздавший мелкий пакет не подтвердил большой размер.
        for ($i = 0; $i < 32; ++$i) {
            $stale = '';
            $got = @socket_recv($sock, $stale, 16384, MSG_DONTWAIT);
            if ($got === false || $got === 0) {
                break;
            }
        }

        ++$seq;
        $seq16 = $seq & 0xFFFF;
        $payloadLen = max(8, $mtu - $hdrOverhead);
        $packet = $this->buildUdpProbePacket($proto, $payloadLen, $seq16, $mtu);

        @socket_clear_error($sock);
        $sendStartNs = hrtime(true);
        $sent = @socket_send($sock, $packet, $payloadLen, 0);
        if ($sent === false) {
            $err = socket_last_error($sock);
            if ($err === $emsgsize) {
                $this->refreshKernelMtuLimit($sock, $isV6, $kernelMtuLimit);

                return ['status' => 'too_big', 'rtt_ms' => 0.0];
            }
            if ($err === $econnrefused) {
                return ['status' => 'refused', 'rtt_ms' => 0.0];
            }

            return [
                'status' => 'error',
                'rtt_ms' => 0.0,
                'error' => sprintf('Ошибка отправки UDP DF при MTU %d (%s).', $mtu, socket_strerror($err)),
            ];
        }
        if ($sent < $payloadLen) {
            return ['status' => 'too_big', 'rtt_ms' => 0.0];
        }

        $deadlineNs = $sendStartNs + max(20, $waitMs) * 1_000_000;
        $eagain = defined('SOCKET_EAGAIN') ? SOCKET_EAGAIN : (PHP_OS_FAMILY === 'Darwin' ? 35 : 11);
        $ewouldblock = defined('SOCKET_EWOULDBLOCK') ? SOCKET_EWOULDBLOCK : $eagain;
        $eintr = defined('SOCKET_EINTR') ? SOCKET_EINTR : 4;

        while (true) {
            $remainingNs = $deadlineNs - hrtime(true);
            if ($remainingNs <= 0) {
                return ['status' => 'timeout', 'rtt_ms' => 0.0];
            }

            $remainingUs = max(1000, intdiv($remainingNs, 1000));
            $read = [$sock];
            $write = null;
            $except = null;
            $ready = @socket_select($read, $write, $except, intdiv($remainingUs, 1_000_000), $remainingUs % 1_000_000);
            if ($ready === false) {
                $err = socket_last_error($sock);
                if ($err === $eintr) {
                    continue;
                }

                return ['status' => 'error', 'rtt_ms' => 0.0, 'error' => sprintf('Ошибка ожидания ответа UDP (%s).', socket_strerror($err))];
            }
            if ($ready === 0) {
                return ['status' => 'timeout', 'rtt_ms' => 0.0];
            }

            $reply = '';
            @socket_clear_error($sock);
            $recvd = @socket_recv($sock, $reply, 16384, MSG_DONTWAIT);
            if ($recvd === false) {
                $err = socket_last_error($sock);
                if ($err === $eagain || $err === $ewouldblock || $err === $eintr) {
                    continue;
                }
                if ($err === $emsgsize) {
                    $this->refreshKernelMtuLimit($sock, $isV6, $kernelMtuLimit);

                    return ['status' => 'too_big', 'rtt_ms' => 0.0];
                }
                if ($err === $econnrefused) {
                    return ['status' => 'refused', 'rtt_ms' => 0.0];
                }

                return ['status' => 'error', 'rtt_ms' => 0.0, 'error' => sprintf('Ошибка чтения ответа UDP (%s).', socket_strerror($err))];
            }

            if ($recvd <= 0 || $reply === '') {
                continue;
            }
            if (!$this->matchesUdpProbeReply($reply, $mtu, $seq16, $proto)) {
                continue;
            }

            $rttMs = (hrtime(true) - $sendStartNs) / 1_000_000.0;

            return ['status' => 'ok', 'rtt_ms' => max(0.1, $rttMs)];
        }
    }

    private function buildUdpProbePacket(string $proto, int $payloadLen, int $seq16, int $mtu): string
    {
        if (($proto === VpnProtocol::HYSTERIA2 || $proto === VpnProtocol::HYSTERIA) && $payloadLen >= 1200) {
            $tag = 'PMTU' . pack('nn', $mtu & 0xFFFF, $seq16);
            $scid = 'CEPM' . pack('nn', $mtu & 0xFFFF, $seq16);
            $header = "\xC0\x0A\x1A\x2A\x3A\x08" . $tag . "\x08" . $scid;

            return $header . str_repeat("\x50", max(0, $payloadLen - strlen($header)));
        }

        $header = 'PMTU' . pack('nn', $mtu & 0xFFFF, $seq16);
        if ($payloadLen <= strlen($header)) {
            return substr($header, 0, $payloadLen);
        }

        return $header . str_repeat("\x50", $payloadLen - strlen($header));
    }

    private function matchesUdpProbeReply(string $reply, int $mtu, int $seq16, string $proto): bool
    {
        if (str_starts_with($reply, 'PMTU') && strlen($reply) >= 8) {
            $unpacked = unpack('nm/ns', substr($reply, 4, 4));
            if (is_array($unpacked) && (int) ($unpacked['s'] ?? -1) !== $seq16) {
                return false;
            }

            return true;
        }

        if (($proto === VpnProtocol::HYSTERIA2 || $proto === VpnProtocol::HYSTERIA) && strlen($reply) >= 7) {
            $expectedPmtu = 'PMTU' . pack('nn', $mtu & 0xFFFF, $seq16);
            $expectedCepm = 'CEPM' . pack('nn', $mtu & 0xFFFF, $seq16);
            if (str_contains($reply, $expectedPmtu) || str_contains($reply, $expectedCepm)) {
                return true;
            }
            // Если в QUIC-ответе вернулся тег другого seq от предыдущего шага — игнорируем его как устаревший.
            if (preg_match('/(?:PMTU|CEPM)(.{4})/s', $reply, $m) === 1) {
                $tagBytes = unpack('nm/ns', $m[1]);
                if (is_array($tagBytes) && (int) ($tagBytes['s'] ?? -1) !== $seq16) {
                    return false;
                }
            }
        }

        return true;
    }

    private function refreshKernelMtuLimit(\Socket $sock, bool $isV6, ?int &$kernelMtuLimit): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || $isV6) {
            return;
        }
        $kernelMtu = @socket_get_option($sock, self::SOL_IP_LEVEL, self::LINUX_IP_MTU);
        if (is_int($kernelMtu) && $kernelMtu >= self::MIN_PATH_MTU && $kernelMtu <= self::MAX_PATH_MTU) {
            $kernelMtuLimit = $kernelMtuLimit !== null ? min($kernelMtuLimit, $kernelMtu) : $kernelMtu;
        }
    }

    private function enableDontFragment(\Socket $sock, bool $isV6): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            if ($isV6) {
                @socket_set_option($sock, self::SOL_IPV6_LEVEL, self::DARWIN_IPV6_DONTFRAG, 1);
            } else {
                @socket_set_option($sock, self::SOL_IP_LEVEL, self::DARWIN_IP_DONTFRAG, 1);
            }

            return;
        }

        if ($isV6) {
            @socket_set_option($sock, self::SOL_IPV6_LEVEL, self::LINUX_IPV6_MTU_DISCOVER, self::LINUX_IP_PMTUDISC_DO);
        } else {
            @socket_set_option($sock, self::SOL_IP_LEVEL, self::LINUX_IP_MTU_DISCOVER, self::LINUX_IP_PMTUDISC_DO);
        }
    }

    private function detectInterfaceMtu(string $localIp): int
    {
        if (!function_exists('net_get_interfaces')) {
            return 0;
        }
        $interfaces = @net_get_interfaces();
        if (!is_array($interfaces)) {
            return 0;
        }

        foreach ($interfaces as $name => $info) {
            if (!is_array($info)) {
                continue;
            }
            $unicast = $info['unicast'] ?? [];
            $matched = false;
            if (is_array($unicast)) {
                foreach ($unicast as $entry) {
                    if (is_array($entry) && ($entry['address'] ?? null) === $localIp) {
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched) {
                continue;
            }

            $mtu = (int) ($info['mtu'] ?? 0);
            if ($mtu <= 0 && PHP_OS_FAMILY === 'Linux' && is_string($name) && preg_match('/^[a-zA-Z0-9._-]+$/', $name) === 1) {
                $sysMtu = @file_get_contents('/sys/class/net/' . $name . '/mtu');
                if (is_string($sysMtu)) {
                    $mtu = (int) trim($sysMtu);
                }
            }
            if ($mtu > self::MAX_PATH_MTU) {
                // Loopback с MTU 16384/65536 нормализуем к стандартному 1500.
                return self::DEFAULT_PATH_MTU;
            }

            return $mtu >= self::MIN_PATH_MTU ? $mtu : 0;
        }

        return 0;
    }

    /**
     * @return array{ip: string, ipv6: bool}|null
     */
    private function resolveHostIp(string $host): ?array
    {
        $clean = trim($host, "[] \t\n\r\0\x0B");
        if ($clean === '') {
            return null;
        }

        $packed = @inet_pton($clean);
        if ($packed !== false) {
            return ['ip' => $clean, 'ipv6' => strlen($packed) === 16];
        }

        if (function_exists('socket_addrinfo_lookup')) {
            $infos = @socket_addrinfo_lookup($clean, null, ['ai_socktype' => SOCK_STREAM]);
            if (is_array($infos)) {
                foreach ($infos as $info) {
                    $explained = @socket_addrinfo_explain($info);
                    $addr = $explained['ai_addr']['sin_addr'] ?? ($explained['ai_addr']['sin6_addr'] ?? null);
                    if (is_string($addr) && $addr !== '') {
                        return ['ip' => $addr, 'ipv6' => ($explained['ai_family'] ?? AF_INET) === AF_INET6];
                    }
                }
            }
        }

        $ipv4 = @gethostbyname($clean);
        if ($ipv4 !== $clean && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ip' => $ipv4, 'ipv6' => false];
        }

        return null;
    }

    /**
     * @return array{host: string, port: int|null}|null
     */
    private function extractHostPort(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (str_contains($trimmed, '://')) {
            return $this->extractHostPortFromUri($trimmed);
        }

        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $trimmed, $m) === 1) {
            return ['host' => $m[1], 'port' => isset($m[2]) ? (int) $m[2] : null];
        }

        if (substr_count($trimmed, ':') === 1) {
            [$h, $p] = explode(':', $trimmed, 2);
            if (trim($h) !== '' && ctype_digit($p)) {
                return ['host' => trim($h), 'port' => (int) $p];
            }
        }

        return ['host' => $trimmed, 'port' => null];
    }

    /**
     * @return array{host: string, port: int|null}|null
     */
    private function extractHostPortFromUri(?string $uri): ?array
    {
        if ($uri === null || trim($uri) === '') {
            return null;
        }
        $parts = @parse_url(trim($uri));
        if (!is_array($parts) || !isset($parts['host']) || trim((string) $parts['host']) === '') {
            return null;
        }

        return [
            'host' => trim((string) $parts['host'], '[]'),
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
        ];
    }

    /**
     * @return array{host: string, port: int|null}|null
     */
    private function extractHostPortFromConfig(string $proto, ?string $rawConfig): ?array
    {
        if ($rawConfig === null || trim($rawConfig) === '') {
            return null;
        }

        if ($proto === VpnProtocol::OPENVPN) {
            if (preg_match('/^\s*remote\s+([^\s#;]+)(?:\s+(\d+))?/im', $rawConfig, $m) === 1) {
                return ['host' => trim($m[1]), 'port' => isset($m[2]) ? (int) $m[2] : null];
            }
        }

        if ($proto === VpnProtocol::AMNEZIAWG) {
            if (preg_match('/^\s*Endpoint\s*=\s*(.+)$/im', $rawConfig, $m) === 1) {
                return $this->extractHostPort(trim($m[1]));
            }
        }

        return null;
    }

    private function computeInnerTunMtu(int $pathMtu, string $proto, bool $usesArgon): int
    {
        $overhead = match (true) {
            $usesArgon || $proto === VpnProtocol::ARGON4 || $proto === VpnProtocol::TRANSIT => self::ARGON_OVERHEAD,
            $proto === VpnProtocol::AMNEZIAWG => 80,
            $proto === VpnProtocol::OPENVPN => 60,
            $proto === VpnProtocol::HYSTERIA2, $proto === VpnProtocol::HYSTERIA => 48,
            default => 40,
        };

        return max(500, $pathMtu - $overhead);
    }

    private function matchesConnection(VpnConnection $candidate, VpnConnection $target, ?int $targetId): bool
    {
        if ($targetId !== null && $candidate->getId() === $targetId) {
            return true;
        }

        return $candidate->getExternalId() === $target->getExternalId();
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResult(
        string $proto,
        string $server,
        string $error,
        int $precedingBudget = 0,
        bool $supportsInBand = false,
    ): array {
        return [
            'ok' => false,
            'proto' => $proto,
            'server' => $server,
            'optimal_path_mtu' => 0,
            'inner_tun_mtu' => 0,
            'interface_mtu' => 0,
            'tcp_mss' => 0,
            'rtt_ms' => 0.0,
            'method' => 'none',
            'preceding_budget' => $precedingBudget,
            'chain_name' => null,
            'supports_in_band' => $supportsInBand,
            'message' => $error,
            'error' => $error,
        ];
    }
}
