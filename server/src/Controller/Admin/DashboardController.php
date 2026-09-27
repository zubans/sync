<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\Device;
use App\Entity\Family;
use App\Entity\InstalledApp;
use App\Entity\User;
use App\Entity\Vault;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function index(): Response
    {
        $count = fn (string $class, ?string $where = null): int => (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')->from($class, 'e')
            ->andWhere($where ?? '1 = 1')
            ->getQuery()->getSingleScalarResult();

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                ['Пользователи', $count(User::class), 'admin_user_index'],
                ['Семьи', $count(Family::class), 'admin_family_index'],
                ['Личные контакты', $count(Contact::class, 'e.user IS NOT NULL AND e.deletedAt IS NULL'), 'admin_contact_index'],
                ['Семейные контакты', $count(Contact::class, 'e.family IS NOT NULL'), 'admin_family_contact_index'],
                ['Устройства', $count(Device::class), 'admin_device_index'],
                ['Приложения (установлено)', $count(InstalledApp::class, 'e.removedAt IS NULL'), 'admin_installed_app_index'],
                ['Хранилища паролей', $count(Vault::class), 'admin_vault_index'],
            ],
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
        yield MenuItem::linkToDashboard('Обзор', 'fa fa-home');

        yield MenuItem::section('Справочник');
        yield MenuItem::linkTo(ContactCrudController::class, 'Личные контакты', 'fa fa-address-book');
        yield MenuItem::linkTo(FamilyContactCrudController::class, 'Семейные контакты', 'fa fa-people-roof');

        yield MenuItem::section('Аккаунты');
        yield MenuItem::linkTo(UserCrudController::class, 'Пользователи', 'fa fa-user');
        yield MenuItem::linkTo(FamilyCrudController::class, 'Семьи', 'fa fa-people-group');
        yield MenuItem::linkTo(DeviceCrudController::class, 'Устройства', 'fa fa-mobile-screen');
        yield MenuItem::linkTo(GoogleAccountCrudController::class, 'Google-аккаунты', 'fa-brands fa-google');

        yield MenuItem::section('Резервные копии');
        yield MenuItem::linkTo(InstalledAppCrudController::class, 'Приложения', 'fa fa-box-archive');
        yield MenuItem::linkTo(VaultCrudController::class, 'Хранилища паролей', 'fa fa-key');
    }
}
