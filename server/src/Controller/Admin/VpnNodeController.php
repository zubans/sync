<?php

namespace App\Controller\Admin;

use App\Entity\NodeSettings;
use App\Entity\User;
use App\Repository\PortForwardRuleRepository;
use App\Repository\RelayRouteRepository;
use App\Repository\VpnChainRepository;
use App\Repository\VpnConnectionRepository;
use App\Repository\VpnServerServiceRepository;
use App\Repository\VpnUserRepository;
use App\Security\RequiresFeature;
use App\Service\CepExportService;
use App\Service\FeatureManager;
use App\Service\NodePortPlan;
use App\Service\NodeRuntimeExporter;
use App\Service\VpnChainRouteDescriber;
use App\Service\VpnNodeSettingsService;
use App\Service\VpnServerProvisioner;
use App\Vpn\VpnProtocol;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Управление ролью текущего узла («Клиент» / «Узел на цепочке» / «VPN-сервер»), планом портов узла,
 * мастером превращения в VPN-сервер и экспортом/синхронизацией локальных конфигов
 * (desired-state.json, profiles.json, port-forward.sh).
 */
#[IsGranted(User::ROLE_ADMIN)]
#[RequiresFeature(FeatureManager::VPN)]
#[AdminRoute('/vpn-node', name: 'vpn_node')]
final class VpnNodeController extends AbstractController
{
    private const CSRF_SETTINGS = 'vpn-node-settings';
    private const CSRF_PROVISION = 'vpn-node-provision';
    private const CSRF_SYNC = 'vpn-node-sync';

    #[AdminRoute('/', name: 'index')]
    public function index(
        VpnNodeSettingsService $settingsService,
        NodeRuntimeExporter $runtimeExporter,
        VpnConnectionRepository $connectionRepository,
        VpnChainRepository $chainRepository,
        VpnServerServiceRepository $serviceRepository,
        PortForwardRuleRepository $forwardRepository,
        VpnUserRepository $userRepository,
        RelayRouteRepository $relayRepository,
        NodePortPlan $portPlan,
        VpnChainRouteDescriber $routeDescriber,
    ): Response {
        $settings = $settingsService->getSettings();
        $runtimeDir = $runtimeExporter->getRuntimeDir();
        $activeChain = $settings->getActiveClientChainId() !== null
            ? $chainRepository->find($settings->getActiveClientChainId())
            : null;

        return $this->render('admin/vpn_node.html.twig', [
            'settings' => $settings,
            'modes' => NodeSettings::MODES,
            'modeLabels' => NodeSettings::MODE_LABELS,
            'protocols' => VpnProtocol::CHOICES,
            'chains' => $chainRepository->findEnabledOrdered(),
            'activeChain' => $activeChain,
            'activeChainRoute' => $activeChain !== null ? $routeDescriber->describe($activeChain) : [],
            'ports' => $portPlan->build($settings),
            'portConflicts' => $portPlan->findConflicts($settings),
            'counts' => [
                'connections' => $connectionRepository->count([]),
                'chains' => $chainRepository->count([]),
                'services' => $serviceRepository->count([]),
                'forwards' => $forwardRepository->count([]),
                'users' => $userRepository->count([]),
                'relays' => $relayRepository->count([]),
            ],
            'runtimeDir' => $runtimeDir,
            'runtimeFiles' => $this->listRuntimeFiles($runtimeDir),
            'runtimeStatus' => $runtimeExporter->readRuntimeStatus(),
            'csrfSettings' => self::CSRF_SETTINGS,
            'csrfProvision' => self::CSRF_PROVISION,
            'csrfSync' => self::CSRF_SYNC,
        ]);
    }

    #[AdminRoute('/settings', name: 'settings', options: ['methods' => ['POST']])]
    public function saveSettings(
        Request $request,
        VpnNodeSettingsService $settingsService,
        NodeRuntimeExporter $runtimeExporter,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_SETTINGS, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }

        $mode = (string) $request->request->get('mode', NodeSettings::MODE_CLIENT);
        $nodeName = (string) $request->request->get('node_name', 'Локальный узел');
        $publicHost = (string) $request->request->get('public_host', '');
        $wanInterface = (string) $request->request->get('wan_interface', 'eth0');
        $vpnSubnet = (string) $request->request->get('vpn_subnet_cidr', '10.44.0.0/24');
        $dnsRaw = (string) $request->request->get('dns_servers', '1.1.1.1,8.8.8.8');
        $blockIpv6 = $request->request->getBoolean('block_ipv6');
        $autoApply = $request->request->getBoolean('auto_apply_runtime');
        $chainRaw = trim((string) $request->request->get('active_client_chain_id', ''));
        $activeChainId = $chainRaw !== '' ? (int) $chainRaw : null;

        $dnsList = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $dnsRaw) ?: []), static fn (string $s) => $s !== ''));

        try {
            $settings = $settingsService->updateSettings(
                mode: $mode,
                nodeName: $nodeName,
                publicHost: $publicHost !== '' ? $publicHost : null,
                wanInterface: $wanInterface,
                vpnSubnetCidr: $vpnSubnet,
                dnsServers: $dnsList !== [] ? $dnsList : ['1.1.1.1'],
                blockIpv6: $blockIpv6,
                activeClientChainId: $activeChainId,
                autoApplyRuntime: $autoApply,
            );
            $sync = $runtimeExporter->sync();
            $this->addFlash('success', sprintf(
                'Роль узла сохранена: %s. %s',
                $settings->getModeLabel(),
                $sync['message'],
            ));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_vpn_node_index');
    }

    #[AdminRoute('/provision', name: 'provision', options: ['methods' => ['POST']])]
    public function provisionServer(Request $request, VpnServerProvisioner $provisioner): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_PROVISION, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }

        $publicHost = trim((string) $request->request->get('public_host', ''));
        $wanInterface = trim((string) $request->request->get('wan_interface', 'eth0')) ?: 'eth0';
        $vpnSubnet = trim((string) $request->request->get('vpn_subnet_cidr', '10.44.0.0/24')) ?: '10.44.0.0/24';
        /** @var list<string> $protocols */
        $protocols = array_values(array_map('strval', $request->request->all('protocols')));
        $defaultUser = trim((string) $request->request->get('default_username', 'admin-vpn'));
        $grantSystemAccess = $request->request->getBoolean('grant_system_access');

        try {
            $result = $provisioner->makeThisNodeVpnServer(
                publicHost: $publicHost,
                wanInterface: $wanInterface,
                vpnSubnetCidr: $vpnSubnet,
                protocols: $protocols,
                defaultUsername: $defaultUser !== '' ? $defaultUser : null,
                grantSystemAccess: $grantSystemAccess,
                applyRuntimeNow: true,
            );
            $this->addFlash('success', sprintf(
                'Узел переведён в режим «VPN-сервер». Новых сервисов: %d, клиентских подключений: %d. %s',
                $result['servicesCreated'],
                $result['connectionsCreated'],
                $result['runtime']['message'] ?? '',
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Не удалось подготовить VPN-сервер: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_vpn_node_index');
    }

    #[AdminRoute('/sync', name: 'sync', options: ['methods' => ['POST']])]
    public function syncRuntime(Request $request, NodeRuntimeExporter $runtimeExporter): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_SYNC, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }

        $result = $runtimeExporter->sync(applyNow: true);
        $this->addFlash($result['status'] === 'error' ? 'danger' : 'success', $result['message']);

        return $this->redirectToRoute('admin_vpn_node_index');
    }

    #[AdminRoute('/export/profiles.json', name: 'export_profiles')]
    public function exportProfiles(
        VpnNodeSettingsService $settingsService,
        VpnConnectionRepository $connectionRepository,
        VpnChainRepository $chainRepository,
        CepExportService $cepExport,
    ): JsonResponse {
        $settings = $settingsService->getSettings();
        $activeChain = $settings->getActiveClientChainId() !== null
            ? $chainRepository->find($settings->getActiveClientChainId())
            : null;

        $payload = $cepExport->buildStorePayload(
            $settings,
            $connectionRepository->findEnabledOrdered(),
            $chainRepository->findEnabledOrdered(),
            $activeChain,
        );

        $response = new JsonResponse($payload, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="profiles.json"',
        ]);
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $response;
    }

    #[AdminRoute('/export/desired-state.json', name: 'export_desired_state')]
    public function exportDesiredState(NodeRuntimeExporter $runtimeExporter): Response
    {
        $path = $runtimeExporter->getRuntimeDir() . '/desired-state.json';
        if (!is_file($path)) {
            $runtimeExporter->sync();
        }
        $content = is_file($path) ? (string) file_get_contents($path) : '{}';

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'inline; filename="desired-state.json"',
        ]);
    }

    #[AdminRoute('/export/port-forward.sh', name: 'export_port_forward')]
    public function exportPortForward(NodeRuntimeExporter $runtimeExporter): Response
    {
        $path = $runtimeExporter->getRuntimeDir() . '/port-forward.sh';
        if (!is_file($path)) {
            $runtimeExporter->sync();
        }
        $content = is_file($path) ? (string) file_get_contents($path) : "#!/usr/bin/env bash\n";

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'inline; filename="port-forward.sh"',
        ]);
    }

    /** @return list<array{name: string, size: int}> */
    private function listRuntimeFiles(string $runtimeDir): array
    {
        if (!is_dir($runtimeDir)) {
            return [];
        }
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($runtimeDir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace($runtimeDir, '', $file->getPathname()), '/');
            $result[] = ['name' => $rel, 'size' => (int) $file->getSize()];
        }
        usort($result, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $result;
    }
}
