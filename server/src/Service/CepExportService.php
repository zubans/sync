<?php

namespace App\Service;

use App\Entity\NodeSettings;
use App\Entity\VpnChain;
use App\Entity\VpnConnection;
use App\Entity\VpnServerService;
use App\Entity\VpnUser;
use App\Vpn\VpnProtocol;

/**
 * Экспорт подключений и цепочек в формат profiles.json (совместимый с macOS/Android/cep-cli CEP)
 * и генерация клиентских URI/подключений на базе поднятых серверных сервисов.
 */
final class CepExportService
{
    /**
     * @param list<VpnConnection> $connections
     * @param list<VpnChain>      $chains
     * @return array<string, mixed>
     */
    public function buildStorePayload(
        NodeSettings $settings,
        array $connections,
        array $chains,
        ?VpnChain $activeChain = null,
    ): array {
        $profilesJson = [];
        foreach ($connections as $conn) {
            if ($conn->isEnabled()) {
                $profilesJson[] = $this->serializeConnection($conn);
            }
        }

        $chainsJson = [];
        foreach ($chains as $chain) {
            if (!$chain->isEnabled()) {
                continue;
            }
            $hopIds = [];
            $hops = $chain->getHops()->toArray();
            usort($hops, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());
            foreach ($hops as $hop) {
                $c = $hop->getConnection();
                if ($c !== null && $c->isEnabled()) {
                    $hopIds[] = $c->getExternalId();
                }
            }
            if ($hopIds !== []) {
                $chainsJson[] = [
                    'id' => $chain->getExternalId(),
                    'name' => $chain->getName(),
                    'hops' => $hopIds,
                ];
            }
        }

        return [
            'profiles' => $profilesJson,
            'chains' => $chainsJson,
            'activeChain' => $activeChain?->getExternalId(),
            'blockIPv6' => $settings->isBlockIpv6(),
            'dns' => $settings->getDnsServers(),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeConnection(VpnConnection $conn): array
    {
        $proto = $conn->getProto();
        $data = [
            'id' => $conn->getExternalId(),
            'name' => $conn->getName(),
            'proto' => $proto,
        ];

        $usesUnderlay = VpnProtocol::supportsArgonUnderlay($proto) && $conn->isUseArgon();
        $usesArgon = $proto === VpnProtocol::ARGON4
            || $usesUnderlay
            || ($proto === VpnProtocol::TRANSIT && $conn->getPassword() !== null);
        $isV4 = $conn->getArgonProtocolVersion() === 4;

        $optionalStrings = [
            'uri' => $conn->getUri(),
            'config' => $conn->getConfig(),
            'server' => $conn->getServer(),
            'username' => $conn->getUsername(),
            'password' => $conn->getPassword(),
            'clientID' => $conn->getClientId(),
            'clientSecret' => $conn->getClientSecret(),
            'openconnectFlavor' => $conn->getOpenconnectFlavor(),
            'serverCert' => $conn->getServerCert(),
            'authGroup' => $conn->getAuthGroup(),
            'routes' => $conn->getRoutes(),
        ];
        if ($usesUnderlay) {
            $optionalStrings['argonPassword'] = $conn->getArgonPassword();
        }
        if ($usesArgon && !$isV4) {
            $optionalStrings['argonPaddingProfile'] = $conn->getArgonPaddingProfile();
            $optionalStrings['argonFirstPacketProfile'] = $conn->getArgonFirstPacketProfile();
            $optionalStrings['argonBehaviorProfile'] = $conn->getArgonBehaviorProfile();
        }
        foreach ($optionalStrings as $key => $val) {
            if ($val !== null && $val !== '') {
                $data[$key] = $val;
            }
        }

        if ($conn->isIgnoreCertErrors()) {
            $data['ignoreCertErrors'] = true;
        }
        if ($usesUnderlay) {
            $data['useArgon'] = true;
        }

        $pathMtu = $conn->getArgonPathMtu();
        if ($pathMtu !== null) {
            // Значение null не экспортируется: клиент при запуске сам подберёт оптимальный MTU.
            $data['argonPathMTU'] = $pathMtu;
        }

        if ($usesArgon) {
            if ($conn->isArgonLowPowerMode()) {
                $data['argonLowPowerMode'] = true;
            }
            $optionalInts = [
                'argonProtocolVersion' => $conn->getArgonProtocolVersion(),
                'argonVideoCallMaxDurationSec' => $conn->getArgonVideoCallMaxDurationSec(),
                'argonHandshakeMaxAttempts' => $conn->getArgonHandshakeMaxAttempts(),
                'argonHandshakeInitialTimeoutMs' => $conn->getArgonHandshakeInitialTimeoutMs(),
                'argonHandshakeMaxTimeoutMs' => $conn->getArgonHandshakeMaxTimeoutMs(),
                'argonKeepaliveMinIdleSec' => $conn->getArgonKeepaliveMinIdleSec(),
                'argonKeepaliveMaxIdleSec' => $conn->getArgonKeepaliveMaxIdleSec(),
            ];
            foreach ($optionalInts as $key => $val) {
                if ($val !== null) {
                    $data[$key] = $val;
                }
            }
        }

        return $data;
    }

    /**
     * Генерирует готовое клиентское подключение на основании серверного сервиса и (опционально) пользователя.
     */
    public function createConnectionFromServerService(
        VpnServerService $service,
        NodeSettings $settings,
        ?VpnUser $user = null,
    ): VpnConnection {
        $host = $service->getPublicHostOverride() ?: ($settings->getPublicHost() ?: '127.0.0.1');
        $port = $service->getListenPort();
        $proto = $service->getProto();
        $suffix = $user !== null ? ' — ' . $user->getUsername() : '';

        $conn = new VpnConnection();
        $conn->setName(sprintf('%s (%s)%s', $service->getName(), $settings->getNodeName(), $suffix));
        $conn->setProto($proto);

        if ($user !== null) {
            $conn->setClientId($user->getClientId());
            $conn->setClientSecret($user->getClientSecret());
            $conn->setUsername($user->getUsername());
        }

        $inheritedMtu = $service->getArgonPathMtu();
        $clientMtu = ($inheritedMtu >= 576 && $inheritedMtu <= 9000 && $inheritedMtu !== 1500) ? $inheritedMtu : null;

        switch ($proto) {
            case VpnProtocol::ARGON4:
                $conn->setServer(sprintf('%s:%d', $host, $port));
                $conn->setPassword($service->getMasterPassword() ?? VpnProtocol::generateSecret(16));
                $conn->setArgonProtocolVersion($service->getArgonProtocolVersion());
                $conn->setArgonPaddingProfile($service->getArgonPaddingProfile());
                $conn->setArgonPathMtu($clientMtu);
                $conn->setArgonBehaviorProfile($service->getArgonBehaviorProfile() ?? 'baseline');
                break;

            case VpnProtocol::TRANSIT:
                $conn->setServer(sprintf('%s:%d', $host, $port));
                $conn->setArgonPathMtu($clientMtu);
                if ($service->isTransitBlind()) {
                    $conn->setPassword(null);
                    $conn->setArgonBehaviorProfile(null);
                    $conn->setArgonPaddingProfile(null);
                    $conn->setArgonProtocolVersion(null);
                } else {
                    $conn->setPassword($service->getMasterPassword() ?? VpnProtocol::generateSecret(16));
                    $conn->setArgonProtocolVersion($service->getArgonProtocolVersion());
                    $conn->setArgonPaddingProfile($service->getArgonPaddingProfile());
                    $conn->setArgonBehaviorProfile($service->getArgonBehaviorProfile() ?? 'baseline');
                }
                break;

            case VpnProtocol::VLESS:
                $uuid = $user !== null ? $user->getVlessUuid() : VpnProtocol::generateUuidV4();
                $sni = $service->getTlsDomain() ?: 'www.microsoft.com';
                $params = [
                    'encryption' => 'none',
                    'type' => 'tcp',
                    'sni' => $sni,
                ];
                // Всегда пересчитываем Reality pbk из realityPrivateKey (если задан), чтобы ключи совпадали с сервером.
                $realityPublicKey = VpnProtocol::deriveX25519PublicKey($service->getRealityPrivateKey(), true)
                    ?? $service->getRealityPublicKey();
                if ($realityPublicKey !== null && $realityPublicKey !== '') {
                    $params['security'] = 'reality';
                    $params['pbk'] = $realityPublicKey;
                    $params['sid'] = $service->getRealityShortId() ?? '';
                    $params['fp'] = 'chrome';
                    $params['flow'] = 'xtls-rprx-vision';
                } else {
                    $params['security'] = 'tls';
                }
                $conn->setUri(sprintf(
                    'vless://%s@%s:%d?%s#%s',
                    rawurlencode($uuid),
                    $host,
                    $port,
                    http_build_query($params),
                    rawurlencode($conn->getName()),
                ));
                break;

            case VpnProtocol::HYSTERIA2:
                // Сервер использует auth.type=userpass (username:password); формируем пару user:pass в URI.
                $authUser = $user !== null ? $user->getUsername() : 'default';
                $pass = $user !== null ? $user->getTunnelPassword() : ($service->getMasterPassword() ?? VpnProtocol::generateSecret(12));
                $query = ['insecure' => '0'];
                if ($service->getTlsDomain() !== null) {
                    $query['sni'] = $service->getTlsDomain();
                }
                if ($service->getObfsPassword() !== null) {
                    $query['obfs'] = 'salamander';
                    $query['obfs-password'] = $service->getObfsPassword();
                }
                $conn->setUsername($authUser);
                $conn->setPassword($pass);
                $conn->setUri(sprintf(
                    'hysteria2://%s:%s@%s:%d?%s#%s',
                    rawurlencode($authUser),
                    rawurlencode($pass),
                    $host,
                    $port,
                    http_build_query($query),
                    rawurlencode($conn->getName()),
                ));
                break;

            case VpnProtocol::HYSTERIA:
                $pass = $user !== null ? $user->getTunnelPassword() : ($service->getMasterPassword() ?? VpnProtocol::generateSecret(12));
                $conn->setUri(sprintf(
                    'hysteria://%s:%d?protocol=udp&auth=%s#%s',
                    $host,
                    $port,
                    rawurlencode($pass),
                    rawurlencode($conn->getName()),
                ));
                break;

            case VpnProtocol::OPENCONNECT:
                $conn->setServer(sprintf('https://%s:%d/', $host, $port));
                $conn->setOpenconnectFlavor('anyconnect');
                if ($user !== null) {
                    $conn->setUsername($user->getUsername());
                    $conn->setPassword($user->getTunnelPassword());
                }
                break;

            case VpnProtocol::SSTP:
                $conn->setServer(sprintf('%s:%d', $host, $port));
                if ($user !== null) {
                    $conn->setUsername($user->getUsername());
                    $conn->setPassword($user->getTunnelPassword());
                }
                break;

            case VpnProtocol::OPENVPN:
                $transport = $service->getTransportProto() === 'tcp' ? 'tcp-client' : 'udp';
                $conn->setConfig(sprintf(
                    "client\ndev tun\nproto %s\nremote %s %d\nresolv-retry infinite\nnobind\npersist-key\npersist-tun\nauth-user-pass\n",
                    $transport,
                    $host,
                    $port,
                ));
                if ($user !== null) {
                    $conn->setUsername($user->getUsername());
                    $conn->setPassword($user->getTunnelPassword());
                }
                break;

            case VpnProtocol::AMNEZIAWG:
                $extra = $service->getExtraOptions() ?? [];
                $clientKeys = VpnProtocol::deriveAmneziaWgClientKeypair(
                    $user?->getClientId(),
                    $user?->getClientSecret(),
                    isset($extra['clientPrivateKey']) ? (string) $extra['clientPrivateKey'] : null,
                    (string) ($service->getId() ?? $service->getListenPort()),
                );
                $privKey = $clientKeys['private'];
                $pubKey = VpnProtocol::deriveX25519PublicKey(isset($extra['serverPrivateKey']) ? (string) $extra['serverPrivateKey'] : null, false)
                    ?? (string) ($extra['serverPublicKey'] ?? VpnProtocol::generateX25519Keypair()['public']);
                $jc = (int) ($extra['jc'] ?? 4);
                $jmin = (int) ($extra['jmin'] ?? 40);
                $jmax = (int) ($extra['jmax'] ?? 70);
                $addr = $user?->getStaticVpnIp() ? ($user->getStaticVpnIp() . '/32') : '10.66.66.2/32';
                $conn->setConfig(sprintf(
                    "[Interface]\nPrivateKey = %s\nAddress = %s\nDNS = %s\nJc = %d\nJmin = %d\nJmax = %d\n\n[Peer]\nPublicKey = %s\nEndpoint = %s:%d\nAllowedIPs = 0.0.0.0/0\nPersistentKeepalive = 25\n",
                    $privKey,
                    $addr,
                    implode(', ', $settings->getDnsServers()),
                    $jc,
                    $jmin,
                    $jmax,
                    $pubKey,
                    $host,
                    $port,
                ));
                break;
        }

        return $conn;
    }

    public function buildShareLink(VpnConnection $conn): ?string
    {
        if ($conn->getUri() !== null) {
            return $conn->getUri();
        }
        if ($conn->getProto() === VpnProtocol::ARGON4 || $conn->getProto() === VpnProtocol::TRANSIT) {
            $query = array_filter([
                'v' => $conn->getArgonProtocolVersion(),
                'behavior' => $conn->getArgonBehaviorProfile(),
                'padding' => $conn->getArgonPaddingProfile(),
                'mtu' => $conn->getArgonPathMtu(),
            ], static fn ($v) => $v !== null && $v !== '');
            $qStr = $query !== [] ? '?' . http_build_query($query) : '';

            return sprintf(
                '%s://%s@%s%s#%s',
                $conn->getProto(),
                rawurlencode($conn->getClientId() ?? 'client'),
                $conn->getServer() ?? '',
                $qStr,
                rawurlencode($conn->getName()),
            );
        }

        return $conn->getServer();
    }
}
