<?php

namespace App\Service;

use App\Entity\NodeSettings;
use App\Entity\PortForwardRule;
use App\Entity\VpnServerService;
use App\Entity\VpnUser;
use App\Repository\PortForwardRuleRepository;
use App\Repository\VpnChainRepository;
use App\Repository\VpnConnectionRepository;
use App\Repository\VpnServerServiceRepository;
use App\Repository\VpnUserRepository;
use App\Vpn\VpnProtocol;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Генератор детерминированного состояния узла (desired-state.json, конфиги демонов,
 * скрипт проброса портов iptables, клиентский profiles.json) и опциональный запуск host reconciler.
 */
final class NodeRuntimeExporter
{
    public function __construct(
        #[Autowire('%vpn_runtime_dir%')]
        private readonly string $runtimeDir,
        #[Autowire('%vpn_apply_command%')]
        private readonly ?string $applyCommand,
        private readonly VpnNodeSettingsService $settingsService,
        private readonly VpnConnectionRepository $connectionRepository,
        private readonly VpnChainRepository $chainRepository,
        private readonly VpnServerServiceRepository $serviceRepository,
        private readonly PortForwardRuleRepository $forwardRepository,
        private readonly VpnUserRepository $userRepository,
        private readonly CepExportService $cepExport,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?FeatureManager $featureManager = null,
    ) {
    }

    public function getRuntimeDir(): string
    {
        return $this->runtimeDir;
    }

    /**
     * Полностью пересобирает файлы состояния в VPN_RUNTIME_DIR и при наличии VPN_RUNTIME_APPLY_CMD
     * вызывает системный скрипт применения.
     *
     * @return array{status: string, message: string, files: list<string>}
     */
    public function sync(bool $applyNow = false): array
    {
        $this->ensureDirectories();
        $settings = $this->settingsService->getSettings();

        $vpnEnabled = true;
        if ($this->featureManager !== null) {
            try {
                $vpnEnabled = $this->featureManager->isEnabled(FeatureManager::VPN);
            } catch (\Throwable) {
                $vpnEnabled = true;
            }
        }

        if (!$vpnEnabled) {
            $emptyProfiles = $this->cepExport->buildStorePayload($settings, [], [], null);
            $this->writeJson('profiles.json', $emptyProfiles);
            $this->clearServerServiceEnvFiles();
            $this->writeFile('port-forward.sh', $this->renderPortForwardScript($settings, []), 0o750);
            $desiredState = $this->buildDesiredState($settings, null, [], [], []);
            $desiredState['vpnModuleEnabled'] = false;
            $desiredState['server']['enabled'] = false;
            $this->writeJson('desired-state.json', $desiredState);

            $status = 'disabled';
            $message = 'Блок VPN отключён в разделе «Модули и функции ИИ»: клиентская цепочка и серверные службы остановлены.';
            $applyResult = $this->executeApplyCommand($settings, $applyNow);
            if ($applyResult !== null) {
                if ($applyResult['ok']) {
                    if ($applyResult['message'] !== '') {
                        $message .= ' ' . $applyResult['message'];
                    }
                } else {
                    $status = 'error';
                    $message = 'Блок VPN отключён в настройках, но остановить службы на хосте не удалось: ' . $applyResult['message'];
                }
            }

            $settings->setLastRuntimeStatus($status);
            $settings->setLastRuntimeAppliedAt(new \DateTimeImmutable());
            $settings->setLastRuntimeMessage($message);
            $this->entityManager->flush();

            return [
                'status' => $status,
                'message' => $message,
                'files' => ['profiles.json', 'port-forward.sh', 'desired-state.json'],
            ];
        }

        $connections = $this->connectionRepository->findEnabledOrdered();
        $chains = $this->chainRepository->findEnabledOrdered();
        $activeChain = $settings->getActiveClientChainId() !== null
            ? $this->chainRepository->find($settings->getActiveClientChainId())
            : null;

        $writtenFiles = [];

        $profilesPayload = $this->cepExport->buildStorePayload($settings, $connections, $chains, $activeChain);
        $this->writeJson('profiles.json', $profilesPayload);
        $writtenFiles[] = 'profiles.json';

        $isServer = $settings->isServerMode();
        $services = $isServer ? $this->serviceRepository->findEnabledOrdered() : [];
        $forwards = $isServer ? $this->forwardRepository->findEnabledOrdered() : [];
        $users = $isServer ? $this->userRepository->findEnabledOrdered() : [];

        if ($isServer) {
            foreach ($this->writeServerArtifacts($settings, $services, $users) as $file) {
                $writtenFiles[] = $file;
            }
        } else {
            $this->clearServerServiceEnvFiles();
        }

        $portForwardScript = $this->renderPortForwardScript($settings, $isServer ? $forwards : []);
        $this->writeFile('port-forward.sh', $portForwardScript, 0o750);
        $writtenFiles[] = 'port-forward.sh';

        $desiredState = $this->buildDesiredState($settings, $activeChain?->getExternalId(), $services, $forwards, $users);
        $this->writeJson('desired-state.json', $desiredState);
        $writtenFiles[] = 'desired-state.json';

        $status = 'synced';
        $message = sprintf(
            'Конфигурация узла (%s) записана в %s (%d файлов).',
            $isServer ? 'Сервер' : 'Клиент',
            $this->runtimeDir,
            count($writtenFiles),
        );

        $applyResult = $this->executeApplyCommand($settings, $applyNow);
        if ($applyResult !== null) {
            if ($applyResult['ok']) {
                $status = 'applied';
                $message = $applyResult['message'] !== '' ? $applyResult['message'] : 'Конфигурация узла успешно применена на устройстве.';
            } else {
                $status = 'error';
                $message = $applyResult['message'];
            }
        }

        $settings->setLastRuntimeStatus($status);
        $settings->setLastRuntimeAppliedAt(new \DateTimeImmutable());
        $settings->setLastRuntimeMessage($message);
        $this->entityManager->flush();

        return [
            'status' => $status,
            'message' => $message,
            'files' => $writtenFiles,
        ];
    }

    /** @return array<string, mixed>|null */
    public function readRuntimeStatus(): ?array
    {
        $path = $this->runtimeDir . '/runtime-status.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<VpnServerService> $services
     * @param list<PortForwardRule>  $forwards
     * @param list<VpnUser>          $users
     * @return array<string, mixed>
     */
    private function buildDesiredState(
        NodeSettings $settings,
        ?string $activeChainExternalId,
        array $services,
        array $forwards,
        array $users,
    ): array {
        return [
            'schemaVersion' => 1,
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'node' => [
                'mode' => $settings->getMode(),
                'name' => $settings->getNodeName(),
                'publicHost' => $settings->getPublicHost(),
                'wanInterface' => $settings->getWanInterface(),
                'vpnSubnetCidr' => $settings->getVpnSubnetCidr(),
                'dns' => $settings->getDnsServers(),
                'blockIPv6' => $settings->isBlockIpv6(),
            ],
            'client' => [
                'activeChain' => $activeChainExternalId,
                'profilesFile' => 'profiles.json',
            ],
            'server' => [
                'enabled' => $settings->isServerMode(),
                'services' => array_map(static fn (VpnServerService $s): array => [
                    'id' => $s->getId(),
                    'name' => $s->getName(),
                    'proto' => $s->getProto(),
                    'listenHost' => $s->getListenHost(),
                    'listenPort' => $s->getListenPort(),
                    'transportProto' => $s->getTransportProto(),
                    'transitBlind' => $s->isTransitBlind(),
                    'transitTarget' => $s->getTransitTarget(),
                    'argonProtocolVersion' => $s->getArgonProtocolVersion(),
                    'argonBehaviorProfile' => $s->getArgonBehaviorProfile(),
                    'argonPaddingProfile' => $s->getArgonPaddingProfile(),
                    'argonPathMtu' => $s->getArgonPathMtu(),
                ], $services),
                'portForwards' => array_map(static fn (PortForwardRule $r): array => [
                    'id' => $r->getId(),
                    'name' => $r->getName(),
                    'protocol' => $r->getProtocol(),
                    'listenInterface' => $r->getListenInterface() ?: $settings->getWanInterface(),
                    'listenPort' => $r->getListenPort(),
                    'listenPortEnd' => $r->getEffectiveEndPort(),
                    'targetIp' => $r->getTargetIp(),
                    'targetPort' => $r->getTargetPort(),
                    'sourceCidr' => $r->getSourceCidr(),
                    'masquerade' => $r->isEnableMasquerade(),
                ], $forwards),
                'users' => array_map(static fn (VpnUser $u): array => [
                    'id' => $u->getId(),
                    'username' => $u->getUsername(),
                    'clientId' => $u->getClientId(),
                    'staticVpnIp' => $u->getStaticVpnIp(),
                    'grantSystemAccess' => $u->isGrantSystemAccess(),
                    'systemRole' => $u->getSystemRole(),
                ], $users),
            ],
        ];
    }

    /**
     * @param list<VpnServerService> $services
     * @param list<VpnUser>          $users
     * @return list<string>
     */
    private function writeServerArtifacts(NodeSettings $settings, array $services, array $users): array
    {
        $this->clearServerServiceEnvFiles();
        $files = [];
        $primaryArgonWritten = false;
        $vlessInbounds = [];

        foreach ($services as $svc) {
            switch ($svc->getProto()) {
                case VpnProtocol::ARGON4:
                    $envName = $primaryArgonWritten
                        ? sprintf('services/argon-%d.env', $svc->getId() ?? 0)
                        : 'argon-server.env';
                    $primaryArgonWritten = true;
                    $this->writeFile($envName, $this->renderArgonEnv($settings, $svc, $users));
                    $files[] = $envName;
                    break;

                case VpnProtocol::TRANSIT:
                    $envName = sprintf('services/argon-transit-%d.env', $svc->getId() ?? 0);
                    $this->writeFile($envName, $this->renderArgonEnv($settings, $svc, $users));
                    $files[] = $envName;
                    break;

                case VpnProtocol::VLESS:
                    $vlessInbounds[] = $this->renderVlessInbound($svc, $users);
                    break;

                case VpnProtocol::HYSTERIA2:
                    $this->writeFile('hysteria2.yaml', $this->renderHysteria2Yaml($svc, $users));
                    $files[] = 'hysteria2.yaml';
                    break;

                case VpnProtocol::OPENCONNECT:
                    $this->writeFile('ocserv.env', $this->renderOcservEnv($settings, $svc, $users));
                    $this->writeFile('ocpasswd', $this->renderOcpasswd($users), 0o600);
                    $files[] = 'ocserv.env';
                    $files[] = 'ocpasswd';
                    break;

                case VpnProtocol::OPENVPN:
                    $this->writeFile('openvpn-server.conf', $this->renderOpenVpnConf($settings, $svc));
                    $files[] = 'openvpn-server.conf';
                    break;

                case VpnProtocol::AMNEZIAWG:
                    $this->writeFile('amneziawg-server.conf', $this->renderAmneziaWgConf($settings, $svc, $users), 0o600);
                    $files[] = 'amneziawg-server.conf';
                    break;

                case VpnProtocol::SSTP:
                    $this->writeFile('sstp-server.env', $this->renderSstpEnv($settings, $svc));
                    $files[] = 'sstp-server.env';
                    break;

                case VpnProtocol::HYSTERIA:
                    $this->writeJson('hysteria1.json', [
                        'listen' => sprintf('%s:%d', $svc->getListenHost(), $svc->getListenPort()),
                        'protocol' => 'udp',
                        'auth' => ['mode' => 'passwords', 'config' => array_map(static fn (VpnUser $u) => $u->getTunnelPassword(), $users)],
                    ]);
                    $files[] = 'hysteria1.json';
                    break;
            }
        }

        if ($vlessInbounds !== []) {
            $this->writeJson('xray-config.json', [
                'log' => ['loglevel' => 'warning'],
                'inbounds' => $vlessInbounds,
                'outbounds' => [['protocol' => 'freedom', 'tag' => 'direct']],
            ]);
            $files[] = 'xray-config.json';
        }

        return $files;
    }

    /** @param list<VpnUser> $users */
    private function renderArgonEnv(NodeSettings $settings, VpnServerService $svc, array $users): string
    {
        $isTransit = $svc->getProto() === VpnProtocol::TRANSIT;
        $lines = [
            '# Сгенерировано панелью управления sync (совместимо с argon-tunnel-server)',
            'ARGON_LISTEN_ADDR=' . self::envValue(sprintf('%s:%d', $svc->getListenHost(), $svc->getListenPort())),
            'ARGON_SUBNET=' . self::envValue($svc->getSubnetCidr() ?: '10.44.0.1/24'),
            'ARGON_OUT_INTERFACE=' . self::envValue($settings->getWanInterface()),
            'ARGON_ENABLE_NAT=' . ($isTransit ? '0' : '1'),
            'ARGON_MODE=' . ($isTransit ? 'transit' : 'tunnel'),
            'ARGON_MASTER_PASSWORD=' . self::envValue($svc->getMasterPassword() ?? ''),
            'ARGON_TRANSIT_BLIND=' . ($svc->isTransitBlind() ? '1' : '0'),
            'ARGON_TRANSIT_TARGET=' . self::envValue($svc->getTransitTarget() ?? ''),
            'ARGON_TRANSIT_ALLOW=' . self::envValue($svc->getTransitAllow() ?? ''),
            'ARGON_PROTOCOL_VERSION=' . $svc->getArgonProtocolVersion(),
            'ARGON_ALLOW_V4=' . ($svc->isArgonDisallowV4() ? '0' : '1'),
            'ARGON_PADDING_PROFILE=' . self::envValue($svc->getArgonPaddingProfile()),
            'ARGON_PATH_MTU=' . $svc->getArgonPathMtu(),
            'ARGON_BEHAVIOR_PROFILE=' . self::envValue($svc->getArgonBehaviorProfile() ?? ''),
            'ARGON_ALLOW_VIDEO_CALL=' . ($svc->isArgonAllowVideoCall() ? '1' : '0'),
            'ARGON_DNS=' . self::envValue(implode(',', $settings->getDnsServers())),
        ];

        $clients = [];
        foreach ($users as $user) {
            if (!$user->getAllowedServices()->isEmpty() && !$user->getAllowedServices()->contains($svc)) {
                continue;
            }
            $clients[] = [
                'clientId' => $user->getClientId(),
                'clientSecret' => $user->getClientSecret(),
                'staticIp' => $user->getStaticVpnIp(),
            ];
        }
        $lines[] = 'ARGON_CLIENTS_JSON=' . self::envValue(json_encode($clients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<VpnUser> $users
     * @return array<string, mixed>
     */
    private function renderVlessInbound(VpnServerService $svc, array $users): array
    {
        $clients = [];
        foreach ($users as $user) {
            if (!$user->getAllowedServices()->isEmpty() && !$user->getAllowedServices()->contains($svc)) {
                continue;
            }
            $clients[] = [
                'id' => $user->getVlessUuid(),
                'email' => $user->getEffectiveSystemEmail(),
                'flow' => 'xtls-rprx-vision',
            ];
        }

        $streamSettings = ['network' => 'tcp'];
        $sni = $svc->getTlsDomain() ?: 'www.microsoft.com';
        if ($svc->getRealityPrivateKey() !== null) {
            $streamSettings['security'] = 'reality';
            $streamSettings['realitySettings'] = [
                'show' => false,
                'dest' => $sni . ':443',
                'xver' => 0,
                'serverNames' => [$sni],
                'privateKey' => $svc->getRealityPrivateKey(),
                'shortIds' => [$svc->getRealityShortId() ?? '01ab'],
            ];
        } else {
            $streamSettings['security'] = 'tls';
            $streamSettings['tlsSettings'] = ['serverName' => $sni];
        }

        return [
            'tag' => 'vless-in-' . ($svc->getId() ?? 0),
            'listen' => $svc->getListenHost(),
            'port' => $svc->getListenPort(),
            'protocol' => 'vless',
            'settings' => [
                'clients' => $clients,
                'decryption' => 'none',
            ],
            'streamSettings' => $streamSettings,
        ];
    }

    /** @param list<VpnUser> $users */
    private function renderHysteria2Yaml(VpnServerService $svc, array $users): string
    {
        $lines = [
            sprintf('listen: %s:%d', $svc->getListenHost(), $svc->getListenPort()),
            'auth:',
            '  type: userpass',
            '  userpass:',
        ];
        $hasDefaultUser = false;
        foreach ($users as $user) {
            if (!$user->getAllowedServices()->isEmpty() && !$user->getAllowedServices()->contains($svc)) {
                continue;
            }
            if ($user->getUsername() === 'default') {
                $hasDefaultUser = true;
            }
            $lines[] = sprintf('    %s: %s', self::yamlScalar($user->getUsername()), self::yamlScalar($user->getTunnelPassword()));
        }
        $masterPass = $svc->getMasterPassword();
        if (!$hasDefaultUser && $masterPass !== null && $masterPass !== '') {
            $lines[] = sprintf('    default: %s', self::yamlScalar($masterPass));
        }
        if ($svc->getObfsPassword() !== null) {
            $lines[] = 'obfs:';
            $lines[] = '  type: salamander';
            $lines[] = '  salamander:';
            $lines[] = '    password: ' . self::yamlScalar($svc->getObfsPassword());
        }
        if ($svc->getTlsDomain() !== null) {
            $lines[] = 'acme:';
            $lines[] = '  domains:';
            $lines[] = '    - ' . self::yamlScalar($svc->getTlsDomain());
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<VpnUser> $users */
    private function renderOcservEnv(NodeSettings $settings, VpnServerService $svc, array $users): string
    {
        [$netIp, $prefixRaw] = array_pad(explode('/', $svc->getSubnetCidr() ?: $settings->getVpnSubnetCidr(), 2), 2, '24');
        $prefix = max(8, min(30, (int) $prefixRaw));
        $netmask = long2ip((-1 << (32 - $prefix)) & 0xffffffff) ?: '255.255.255.0';

        $ocservUsers = [];
        foreach ($users as $user) {
            if (!$user->getAllowedServices()->isEmpty() && !$user->getAllowedServices()->contains($svc)) {
                continue;
            }
            $ocservUsers[] = $user->getUsername() . ':' . $user->getTunnelPassword();
        }

        $lines = [
            'OCSERV_TCP_PORT=' . $svc->getListenPort(),
            'OCSERV_UDP_PORT=' . $svc->getListenPort(),
            'OCSERV_CN=' . self::envValue($svc->getTlsDomain() ?: ($settings->getPublicHost() ?: 'vpn.local')),
            'OCSERV_SERVER_IP=' . self::envValue($settings->getPublicHost() ?? ''),
            'OCSERV_SUBNET=' . self::envValue($netIp),
            'OCSERV_NETMASK=' . self::envValue($netmask),
            'OCSERV_OUT_INTERFACE=' . self::envValue($settings->getWanInterface()),
            'OCSERV_DNS=' . self::envValue(implode(',', $settings->getDnsServers())),
            'OCSERV_USERS=' . self::envValue(implode(',', $ocservUsers)),
        ];

        return implode("\n", $lines) . "\n";
    }

    /** @param list<VpnUser> $users */
    private function renderOcpasswd(array $users): string
    {
        $lines = [];
        foreach ($users as $user) {
            $salt = substr(strtr(base64_encode(random_bytes(12)), '+/', './'), 0, 16);
            $hash = crypt($user->getTunnelPassword(), '$6$' . $salt . '$');
            $lines[] = sprintf('%s:*:%s', $user->getUsername(), $hash);
        }

        return $lines !== [] ? implode("\n", $lines) . "\n" : '';
    }

    private function renderOpenVpnConf(NodeSettings $settings, VpnServerService $svc): string
    {
        $proto = $svc->getTransportProto() === 'tcp' ? 'tcp-server' : 'udp';
        $dnsLines = [];
        foreach ($settings->getDnsServers() as $dns) {
            $dnsLines[] = sprintf('push "dhcp-option DNS %s"', $dns);
        }

        return implode("\n", array_merge([
            sprintf('port %d', $svc->getListenPort()),
            sprintf('proto %s', $proto),
            'dev tun',
            'topology subnet',
            'server 10.48.0.0 255.255.255.0',
            'push "redirect-gateway def1 bypass-dhcp"',
        ], $dnsLines, [
            'keepalive 10 60',
            'persist-key',
            'persist-tun',
        ])) . "\n";
    }

    /** @param list<VpnUser> $users */
    private function renderAmneziaWgConf(NodeSettings $settings, VpnServerService $svc, array $users): string
    {
        $extra = $svc->getExtraOptions() ?? [];
        $rawServerPriv = isset($extra['serverPrivateKey']) ? (string) $extra['serverPrivateKey'] : null;
        $serverPriv = VpnProtocol::decodeX25519Key($rawServerPriv) !== null
            ? trim((string) $rawServerPriv)
            : VpnProtocol::generateX25519Keypair()['private'];
        $jc = (int) ($extra['jc'] ?? 4);
        $jmin = (int) ($extra['jmin'] ?? 40);
        $jmax = (int) ($extra['jmax'] ?? 70);

        $blocks = [
            "[Interface]",
            "PrivateKey = " . $serverPriv,
            "Address = 10.66.66.1/24",
            "ListenPort = " . $svc->getListenPort(),
            "Jc = " . $jc,
            "Jmin = " . $jmin,
            "Jmax = " . $jmax,
        ];

        $hostIp = 2;
        if ($users === []) {
            $defaultKp = VpnProtocol::deriveAmneziaWgClientKeypair(
                null,
                null,
                isset($extra['clientPrivateKey']) ? (string) $extra['clientPrivateKey'] : null,
                (string) ($svc->getId() ?? $svc->getListenPort()),
            );
            $blocks[] = '';
            $blocks[] = '# default';
            $blocks[] = '[Peer]';
            $blocks[] = 'PublicKey = ' . $defaultKp['public'];
            $blocks[] = 'AllowedIPs = 10.66.66.2/32';
        }
        foreach ($users as $user) {
            $peerKp = VpnProtocol::deriveAmneziaWgClientKeypair($user->getClientId(), $user->getClientSecret());
            $allowed = ($user->getStaticVpnIp() ?: ('10.66.66.' . $hostIp++)) . '/32';
            $blocks[] = '';
            $blocks[] = '# ' . $user->getUsername();
            $blocks[] = '[Peer]';
            $blocks[] = 'PublicKey = ' . $peerKp['public'];
            $blocks[] = 'AllowedIPs = ' . $allowed;
        }

        return implode("\n", $blocks) . "\n";
    }

    private function renderSstpEnv(NodeSettings $settings, VpnServerService $svc): string
    {
        return implode("\n", [
            'SSTP_LISTEN_PORT=' . $svc->getListenPort(),
            'SSTP_HOST=' . self::envValue($svc->getTlsDomain() ?: ($settings->getPublicHost() ?: 'vpn.local')),
            'SSTP_INTERFACE=' . self::envValue($settings->getWanInterface()),
        ]) . "\n";
    }

    /** @param list<PortForwardRule> $forwards */
    private function renderPortForwardScript(NodeSettings $settings, array $forwards): string
    {
        $lines = [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            '',
            'if ! command -v iptables >/dev/null 2>&1; then',
            '  echo "iptables не установлен, проброс портов пропущен" >&2',
            '  exit 0',
            'fi',
            '',
            '# Полностью сбрасываем предыдущие цепочки SYNC_* перед применением (или при переключении в Клиент).',
            'iptables -t nat -N SYNC_DNAT 2>/dev/null || true',
            'iptables -t nat -F SYNC_DNAT',
            'iptables -t nat -C PREROUTING -j SYNC_DNAT 2>/dev/null || iptables -t nat -I PREROUTING 1 -j SYNC_DNAT',
            '',
            'iptables -t nat -N SYNC_POST 2>/dev/null || true',
            'iptables -t nat -F SYNC_POST',
            'iptables -t nat -C POSTROUTING -j SYNC_POST 2>/dev/null || iptables -t nat -I POSTROUTING 1 -j SYNC_POST',
            '',
            'iptables -N SYNC_FWD 2>/dev/null || true',
            'iptables -F SYNC_FWD',
            'iptables -C FORWARD -j SYNC_FWD 2>/dev/null || iptables -I FORWARD 1 -j SYNC_FWD',
        ];

        if ($forwards === []) {
            $lines[] = '';
            $lines[] = 'echo "SYNC порт-форвардинг очищен (режим клиент или нет активных правил)"';

            return implode("\n", $lines) . "\n";
        }

        $lines[] = 'sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true';
        $lines[] = '';

        foreach ($forwards as $rule) {
            $iface = $rule->getListenInterface() ?: $settings->getWanInterface();
            $protos = $rule->getProtocol() === PortForwardRule::PROTO_BOTH
                ? ['tcp', 'udp']
                : [$rule->getProtocol()];
            $end = $rule->getEffectiveEndPort();
            $isRange = $end > $rule->getListenPort();
            $dport = $isRange ? sprintf('%d:%d', $rule->getListenPort(), $end) : (string) $rule->getListenPort();
            $toDest = $isRange
                ? sprintf('%s:%d-%d', $rule->getTargetIp(), $rule->getTargetPort(), $rule->getTargetPort() + ($end - $rule->getListenPort()))
                : sprintf('%s:%d', $rule->getTargetIp(), $rule->getTargetPort());
            $srcFlag = $rule->getSourceCidr() !== null ? ' -s ' . escapeshellarg($rule->getSourceCidr()) : '';

            $lines[] = sprintf('# Правило: %s', str_replace(["\r", "\n"], ' ', $rule->getName()));
            foreach ($protos as $proto) {
                $lines[] = sprintf(
                    'iptables -t nat -A SYNC_DNAT -i %s -p %s%s --dport %s -j DNAT --to-destination %s',
                    escapeshellarg($iface),
                    $proto,
                    $srcFlag,
                    $dport,
                    escapeshellarg($toDest),
                );
                $lines[] = sprintf(
                    'iptables -A SYNC_FWD -p %s -d %s --dport %s -j ACCEPT',
                    $proto,
                    escapeshellarg($rule->getTargetIp()),
                    $dport,
                );
                if ($rule->isEnableMasquerade()) {
                    $lines[] = sprintf(
                        'iptables -t nat -A SYNC_POST -p %s -d %s --dport %s -j MASQUERADE',
                        $proto,
                        escapeshellarg($rule->getTargetIp()),
                        $dport,
                    );
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Выполняет внешний apply-хук (VPN_RUNTIME_APPLY_CMD), если разрешено автоприменение или ручная синхронизация.
     *
     * @return array{ok: bool, message: string}|null
     */
    private function executeApplyCommand(NodeSettings $settings, bool $applyNow): ?array
    {
        if ((!$applyNow && !$settings->isAutoApplyRuntime()) || $this->applyCommand === null || trim($this->applyCommand) === '') {
            return null;
        }

        $process = Process::fromShellCommandline($this->applyCommand, $this->runtimeDir, [
            'VPN_RUNTIME_DIR' => $this->runtimeDir,
        ]);
        $process->setTimeout(25);
        $process->run();

        if ($process->isSuccessful()) {
            return ['ok' => true, 'message' => trim($process->getOutput())];
        }

        return [
            'ok' => false,
            'message' => trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Ошибка применения конфигурации узла.',
        ];
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->runtimeDir, $this->runtimeDir . '/services'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Не удалось создать каталог конфигурации узла: %s', $dir));
            }
        }
    }

    private function clearServerServiceEnvFiles(): void
    {
        $legacy = [
            'argon-server.env',
            'ocserv.env',
            'ocpasswd',
            'xray-config.json',
            'hysteria2.yaml',
            'hysteria1.json',
            'openvpn-server.conf',
            'amneziawg-server.conf',
            'sstp-server.env',
        ];
        foreach ($legacy as $name) {
            $path = $this->runtimeDir . '/' . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $extraEnvs = glob($this->runtimeDir . '/services/*');
        if (is_array($extraEnvs)) {
            foreach ($extraEnvs as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $relativePath, array $payload): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException(sprintf('Ошибка кодирования JSON для %s', $relativePath));
        }
        $this->writeFile($relativePath, $encoded . "\n");
    }

    private function writeFile(string $relativePath, string $content, int $mode = 0o640): void
    {
        $path = $this->runtimeDir . '/' . ltrim($relativePath, '/');
        file_put_contents($path, $content);
        @chmod($path, $mode);
    }

    private static function envValue(string $value): string
    {
        $safe = str_replace(["\r", "\n"], ' ', $value);

        return "'" . str_replace("'", "'\\''", $safe) . "'";
    }

    private static function yamlScalar(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""';
    }
}
