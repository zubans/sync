<?php

namespace App\Service;

use App\Entity\NodeSettings;
use App\Entity\VpnChain;
use App\Entity\VpnChainHop;
use App\Entity\VpnServerService;
use App\Entity\VpnUser;
use App\Repository\VpnServerServiceRepository;
use App\Repository\VpnUserRepository;
use App\Vpn\VpnProtocol;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Пошаговое превращение текущего устройства в полноценный VPN-сервер:
 * включает серверный режим, поднимает набор выбранных протоколов со свежими ключами,
 * при необходимости создаёт пользователя и готовые клиентские подключения CEP.
 */
final class VpnServerProvisioner
{
    public function __construct(
        private readonly VpnNodeSettingsService $settingsService,
        private readonly VpnServerServiceRepository $serviceRepository,
        private readonly VpnUserRepository $userRepository,
        private readonly VpnUserManager $userManager,
        private readonly CepExportService $cepExport,
        private readonly NodeRuntimeExporter $runtimeExporter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Переводит узел в режим «Сервер», создаёт выбранные сервисы (если их ещё нет) и клиентские профили.
     *
     * @param list<string> $protocols Список протоколов из VpnProtocol::ALL
     * @return array{servicesCreated: int, connectionsCreated: int, userCreated: ?VpnUser, runtime: array<string, mixed>}
     */
    public function makeThisNodeVpnServer(
        string $publicHost,
        string $wanInterface,
        string $vpnSubnetCidr,
        array $protocols,
        ?string $defaultUsername = 'admin-vpn',
        bool $grantSystemAccess = false,
        bool $applyRuntimeNow = false,
    ): array {
        $selectedProtos = [];
        foreach ($protocols as $p) {
            if (VpnProtocol::isSupported($p) && !in_array($p, $selectedProtos, true)) {
                $selectedProtos[] = $p;
            }
        }
        if ($selectedProtos === []) {
            $selectedProtos = [VpnProtocol::ARGON4];
        }

        $settings = $this->settingsService->updateSettings(
            mode: NodeSettings::MODE_SERVER,
            nodeName: $this->settingsService->getSettings()->getNodeName(),
            publicHost: $publicHost !== '' ? $publicHost : null,
            wanInterface: $wanInterface,
            vpnSubnetCidr: $vpnSubnetCidr,
            dnsServers: $this->settingsService->getSettings()->getDnsServers(),
            blockIpv6: $this->settingsService->getSettings()->isBlockIpv6(),
            activeClientChainId: $this->settingsService->getSettings()->getActiveClientChainId(),
            autoApplyRuntime: true,
        );

        $existing = $this->serviceRepository->findAll();
        $existingProtos = [];
        foreach ($existing as $svc) {
            $existingProtos[$svc->getProto()] = $svc;
        }

        $servicesCreated = 0;
        $activeServices = [];
        foreach ($selectedProtos as $proto) {
            if (isset($existingProtos[$proto])) {
                $svc = $existingProtos[$proto];
                $svc->setEnabled(true);
                $activeServices[] = $svc;
                continue;
            }
            $svc = $this->createDefaultServiceForProtocol($proto, $settings, $existing);
            $this->entityManager->persist($svc);
            $existing[] = $svc;
            $activeServices[] = $svc;
            ++$servicesCreated;
        }
        $this->entityManager->flush();

        $createdUser = null;
        if ($defaultUsername !== null && trim($defaultUsername) !== '') {
            $uname = trim($defaultUsername);
            $createdUser = $this->userRepository->findOneBy(['username' => $uname]);
            if ($createdUser === null) {
                $createdUser = new VpnUser();
                $createdUser->setUsername($uname);
                $createdUser->setDisplayName('Основной VPN-пользователь');
                $createdUser->setGrantSystemAccess($grantSystemAccess);
                foreach ($activeServices as $svc) {
                    $createdUser->addAllowedService($svc);
                }
                if ($grantSystemAccess) {
                    $this->userManager->prepareAndSyncSystemAccount($createdUser);
                }
                $this->entityManager->persist($createdUser);
                $this->entityManager->flush();
            }
        }

        $connectionsCreated = 0;
        $primaryChain = null;
        foreach ($activeServices as $svc) {
            $conn = $this->cepExport->createConnectionFromServerService($svc, $settings, $createdUser);
            $this->entityManager->persist($conn);
            ++$connectionsCreated;

            if ($primaryChain === null && $svc->getProto() !== VpnProtocol::TRANSIT) {
                $primaryChain = new VpnChain();
                $primaryChain->setName('Доступ через ' . $settings->getNodeName() . ' (' . VpnProtocol::label($svc->getProto()) . ')');
                $hop = new VpnChainHop();
                $hop->setConnection($conn);
                $hop->setPosition(1);
                $primaryChain->addHop($hop);
                $this->entityManager->persist($primaryChain);
            }
        }
        $this->entityManager->flush();

        $runtime = $this->runtimeExporter->sync($applyRuntimeNow);

        return [
            'servicesCreated' => $servicesCreated,
            'connectionsCreated' => $connectionsCreated,
            'userCreated' => $createdUser,
            'runtime' => $runtime,
        ];
    }

    /** @param list<VpnServerService> $existing */
    private function createDefaultServiceForProtocol(string $proto, NodeSettings $settings, array $existing): VpnServerService
    {
        $svc = new VpnServerService();
        $svc->setName('Сервис ' . VpnProtocol::label($proto));
        $svc->setProto($proto);
        $svc->setTransportProto(VpnProtocol::defaultTransportProto($proto));
        $svc->setListenPort($this->pickFreePort(VpnProtocol::defaultPort($proto), $svc->getTransportProto(), $existing));
        $svc->setPublicHostOverride($settings->getPublicHost());
        $svc->setSubnetCidr('10.44.0.1/24');

        switch ($proto) {
            case VpnProtocol::ARGON4:
                $svc->setMasterPassword(VpnProtocol::generateSecret(16));
                $svc->setArgonProtocolVersion(5);
                $svc->setArgonBehaviorProfile('baseline');
                $svc->setArgonPaddingProfile('balanced');
                $svc->setArgonAllowVideoCall(true);
                break;

            case VpnProtocol::TRANSIT:
                $svc->setMasterPassword(VpnProtocol::generateSecret(16));
                $svc->setTransitBlind(false);
                $svc->setTransitAllow('0.0.0.0/0');
                break;

            case VpnProtocol::VLESS:
                $realityKeys = VpnProtocol::generateRealityKeypair();
                $svc->setTlsDomain('www.microsoft.com');
                $svc->setRealityPrivateKey($realityKeys['private']);
                $svc->setRealityPublicKey($realityKeys['public']);
                $svc->setRealityShortId(substr(VpnProtocol::generateSecret(4), 0, 8));
                break;

            case VpnProtocol::HYSTERIA2:
            case VpnProtocol::HYSTERIA:
                $svc->setMasterPassword(VpnProtocol::generateSecret(12));
                $svc->setTlsDomain($settings->getPublicHost() ?: 'vpn.local');
                $svc->setObfsPassword(VpnProtocol::generateSecret(12));
                break;

            case VpnProtocol::OPENCONNECT:
            case VpnProtocol::SSTP:
                $svc->setTlsDomain($settings->getPublicHost() ?: 'vpn.local');
                break;

            case VpnProtocol::AMNEZIAWG:
                $serverKeys = VpnProtocol::generateX25519Keypair();
                $clientKeys = VpnProtocol::generateX25519Keypair();
                $svc->setExtraOptions([
                    'serverPrivateKey' => $serverKeys['private'],
                    'serverPublicKey' => $serverKeys['public'],
                    'clientPrivateKey' => $clientKeys['private'],
                    'clientPublicKey' => $clientKeys['public'],
                    'jc' => 4,
                    'jmin' => 40,
                    'jmax' => 70,
                ]);
                break;
        }

        return $svc;
    }

    /** @param list<VpnServerService> $existing */
    private function pickFreePort(int $preferred, string $transport, array $existing): int
    {
        $port = $preferred;
        while (true) {
            $busy = false;
            foreach ($existing as $svc) {
                if ($svc->getListenPort() === $port && $svc->getTransportProto() === $transport) {
                    $busy = true;
                    break;
                }
            }
            if (!$busy) {
                return $port;
            }
            ++$port;
        }
    }
}
