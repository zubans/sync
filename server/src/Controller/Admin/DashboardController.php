<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\Device;
use App\Entity\Family;
use App\Entity\InstalledApp;
use App\Entity\PortForwardRule;
use App\Entity\RelayRoute;
use App\Entity\User;
use App\Entity\Vault;
use App\Entity\VpnChain;
use App\Entity\VpnConnection;
use App\Entity\VpnServerService;
use App\Entity\VpnUser;
use App\Repository\AiAgentAccountRepository;
use App\Repository\AiProviderRepository;
use App\Service\FeatureManager;
use App\Service\VpnNodeSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VpnNodeSettingsService $nodeSettings,
        private readonly FeatureManager $features,
        private readonly AiProviderRepository $aiProviders,
        private readonly AiAgentAccountRepository $aiAccounts,
    ) {
    }

    public function index(): Response
    {
        $count = fn (string $class, ?string $where = null): int => (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')->from($class, 'e')
            ->andWhere($where ?? '1 = 1')
            ->getQuery()->getSingleScalarResult();

        $effective = $this->features->getEffectiveMap();
        $settings = $this->nodeSettings->getSettings();
        $vpnStats = [];
        if ($effective[FeatureManager::VPN]) {
            $vpnStats = [
                ['Подключения VPN/CEP', $count(VpnConnection::class), 'admin_vpn_connection_index'],
                ['Цепочки узлов', $count(VpnChain::class), 'admin_vpn_chain_index'],
            ];
            if ($settings->canRelay()) {
                $vpnStats[] = ['Пропуск трафика', $count(RelayRoute::class), 'admin_relay_route_index'];
            }
            if ($settings->isServerMode()) {
                $vpnStats[] = ['Серверные службы VPN', $count(VpnServerService::class), 'admin_vpn_server_service_index'];
                $vpnStats[] = ['Правила проброса портов', $count(PortForwardRule::class), 'admin_port_forward_index'];
                $vpnStats[] = ['Пользователи VPN / системы', $count(VpnUser::class), 'admin_vpn_user_index'];
            }
        }

        $stats = [
            ['Пользователи', $count(User::class), 'admin_user_index'],
        ];
        if ($effective[FeatureManager::DEVICE_DATA]) {
            $stats[] = ['Семьи', $count(Family::class), 'admin_family_index'];
            $stats[] = ['Устройства', $count(Device::class), 'admin_device_index'];
        }
        if ($effective[FeatureManager::CONTACTS]) {
            $stats[] = ['Контакты', $count(Contact::class, 'e.deletedAt IS NULL'), 'admin_contact_index'];
            $stats[] = ['Общие с семьёй', $count(Contact::class, 'e.family IS NOT NULL AND e.deletedAt IS NULL'), 'admin_family_contact_index'];
            $stats[] = ['В корзине', $count(Contact::class, 'e.deletedAt IS NOT NULL'), 'admin_trash_index'];
        }
        if ($effective[FeatureManager::APPS]) {
            $stats[] = ['Приложения (установлено)', $count(InstalledApp::class, 'e.removedAt IS NULL'), 'admin_installed_app_index'];
        }
        if ($effective[FeatureManager::VAULT]) {
            $stats[] = ['Хранилища паролей', $count(Vault::class), 'admin_vault_index'];
        }
        $stats[] = ['Провайдеры ИИ', $this->aiProviders->countAll(), 'admin_ai_providers_index'];
        $stats[] = ['Слоты CLI-агентов', $this->aiAccounts->countAll(), 'admin_ai_providers_index'];

        return $this->render('admin/dashboard.html.twig', [
            'nodeSettings' => $settings,
            'vpnStats' => $vpnStats,
            'stats' => $stats,
            'featuresEffective' => $effective,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Contact Sync')
            ->setLocales(['ru']);
    }

    public function configureMenuItems(): iterable
    {
        $effective = $this->features->getEffectiveMap();

        yield MenuItem::linkToDashboard('Обзор', 'fa fa-home');

        yield MenuItem::section('Система и ИИ');
        yield MenuItem::linkToRoute('Модули и функции ИИ', 'fa fa-sliders', 'admin_features_index');
        yield MenuItem::linkToRoute('Провайдеры ИИ и агенты', 'fa fa-robot', 'admin_ai_providers_index');

        if ($effective[FeatureManager::VPN]) {
            $node = $this->nodeSettings->getSettings();
            yield MenuItem::section('VPN / CEP — ' . $node->getModeLabel());
            yield MenuItem::linkToRoute('Узел, роль и порты', 'fa fa-server', 'admin_vpn_node_index');

            // Клиентская часть есть в любой роли: узел может и сам выходить в интернет через цепочку.
            yield MenuItem::linkTo(VpnConnectionCrudController::class, 'Подключения (все типы)', 'fa fa-plug');
            yield MenuItem::linkTo(VpnChainCrudController::class, 'Цепочки узлов', 'fa fa-link');

            if ($node->canRelay()) {
                yield MenuItem::section('Узел на цепочке');
                yield MenuItem::linkTo(RelayRouteCrudController::class, 'Пропуск трафика', 'fa fa-route');
            }

            if ($node->isServerMode()) {
                yield MenuItem::section('VPN-сервер');
                yield MenuItem::linkTo(VpnServerServiceCrudController::class, 'Серверные службы', 'fa fa-network-wired');
                yield MenuItem::linkTo(PortForwardRuleCrudController::class, 'Проброс портов', 'fa fa-shuffle');
                yield MenuItem::linkTo(VpnUserCrudController::class, 'Пользователи VPN / системы', 'fa fa-user-shield');
            }
        }

        if ($effective[FeatureManager::CONTACTS]) {
            yield MenuItem::section('Справочник');
            yield MenuItem::linkTo(PhonebookController::class, 'Телефонные книги', 'fa fa-book')->setAction('index');
            yield MenuItem::linkTo(ContactCrudController::class, 'Контакты', 'fa fa-address-book');
            yield MenuItem::linkTo(FamilyContactCrudController::class, 'Семейные контакты', 'fa fa-people-roof');
            yield MenuItem::linkTo(ContactMoveController::class, 'Добавить в семью', 'fa fa-right-left')->setAction('index');
            yield MenuItem::linkTo(DuplicateController::class, 'Дубликаты', 'fa fa-clone')->setAction('index');
            yield MenuItem::linkTo(TrashCrudController::class, 'Корзина', 'fa fa-trash-can');
        }

        if ($effective[FeatureManager::CALENDAR]) {
            yield MenuItem::section('Календарь');
            yield MenuItem::linkTo(CalendarEventCrudController::class, 'События', 'fa fa-calendar-day');
            yield MenuItem::linkTo(CalendarCrudController::class, 'Календари', 'fa fa-calendar');
        }

        if ($effective[FeatureManager::NEWS]) {
            yield MenuItem::section('Новости');
            yield MenuItem::linkTo(NewsItemCrudController::class, 'Лента', 'fa fa-newspaper');
            yield MenuItem::linkTo(NewsSourceCrudController::class, 'Издания', 'fa fa-rss');
            yield MenuItem::linkTo(NewsStorylineCrudController::class, 'Сюжеты', 'fa fa-timeline');
        }

        yield MenuItem::section('Аккаунты');
        yield MenuItem::linkTo(UserCrudController::class, 'Пользователи', 'fa fa-user');
        if ($effective[FeatureManager::DEVICE_DATA]) {
            yield MenuItem::linkTo(FamilyCrudController::class, 'Семьи', 'fa fa-people-group');
            yield MenuItem::linkTo(DeviceCrudController::class, 'Устройства', 'fa fa-mobile-screen');
            yield MenuItem::linkTo(GoogleAccountCrudController::class, 'Google-аккаунты', 'fa-brands fa-google');
        }
        if ($effective[FeatureManager::BROWSING]) {
            yield MenuItem::linkTo(BrowsingVisitCrudController::class, 'История браузеров', 'fa fa-clock-rotate-left');
        }

        yield MenuItem::section('Клиент');
        yield MenuItem::linkTo(AppReleaseCrudController::class, 'Версии приложения', 'fa fa-mobile-arrow-down');

        if ($effective[FeatureManager::APPS] || $effective[FeatureManager::VAULT]) {
            yield MenuItem::section('Резервные копии');
            if ($effective[FeatureManager::APPS]) {
                yield MenuItem::linkTo(InstalledAppCrudController::class, 'Приложения', 'fa fa-box-archive');
            }
            if ($effective[FeatureManager::VAULT]) {
                yield MenuItem::linkTo(VaultCrudController::class, 'Хранилища паролей', 'fa fa-key');
            }
        }
    }
}
