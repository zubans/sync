<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\VpnUser;
use App\Security\RequiresFeature;
use App\Security\RequiresServerMode;
use App\Service\FeatureManager;
use App\Service\NodeRuntimeExporter;
use App\Service\VpnUserManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Создание пользователей VPN-сервера и системных учётных записей веб-панели.
 * Раздел доступен исключительно в режиме «Сервер».
 *
 * @extends AbstractCrudController<VpnUser>
 */
#[RequiresFeature(FeatureManager::VPN)]
#[RequiresServerMode('Создание и изменение пользователей VPN и системы доступны только в режиме «Сервер»')]
#[AdminRoute(path: '/vpn-user', name: 'vpn_user')]
final class VpnUserCrudController extends AbstractCrudController
{
    use VpnCrudFormValidationTrait;

    public function __construct(
        private readonly VpnUserManager $userManager,
        private readonly NodeRuntimeExporter $runtimeExporter,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return VpnUser::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Пользователь VPN / системы')
            ->setEntityLabelInPlural('Пользователи VPN / системы')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['username', 'displayName', 'clientId', 'systemEmail'])
            ->setHelp(Crud::PAGE_INDEX, 'Раздел работает только когда узел переключён в режим «Сервер». Можно скачать персональный profiles.json для каждого пользователя.')
            ->setHelp(Crud::PAGE_NEW, 'Ключи, Client ID и VLESS UUID генерируются автоматически; при включении «Доступ к веб-панели» будет создана/обновлена системная учётная запись входа.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $personalConfig = Action::new('personalProfiles', 'Личный profiles.json', 'fa fa-download')
            ->linkToRoute('admin_vpn_user_personal_profiles', static fn (VpnUser $u): array => ['id' => $u->getId()]);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $personalConfig)
            ->add(Crud::PAGE_DETAIL, $personalConfig);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield FormField::addFieldset('Основное');
        yield TextField::new('username', 'Логин');
        yield TextField::new('displayName', 'Имя / комментарий')->setRequired(false);
        yield BooleanField::new('enabled', 'Активен');
        yield TextField::new('staticVpnIp', 'Статический VPN IPv4 (необязательно)')
            ->setRequired(false);
        yield AssociationField::new('allowedServices', 'Доступные сервисы (пусто — все активные)')
            ->setFormTypeOption('by_reference', false)
            ->hideOnIndex();

        yield FormField::addFieldset('Учётные данные протоколов (автогенерация)');
        yield TextField::new('clientId', 'Client ID (Argon4 / AWG)');
        yield TextField::new('clientSecret', 'Client Secret')->hideOnIndex();
        yield TextField::new('vlessUuid', 'VLESS UUID')->hideOnIndex();
        yield TextField::new('tunnelPassword', 'Пароль туннеля (OpenConnect/Hysteria/OpenVPN/SSTP и системный вход)')
            ->hideOnIndex();

        yield FormField::addFieldset('Доступ к системе (веб-панель sync)');
        yield BooleanField::new('grantSystemAccess', 'Разрешить вход в веб-панель');
        yield TextField::new('systemEmail', 'Email для входа (если пусто — <логин>@vpn.local)')
            ->setRequired(false)
            ->hideOnIndex();
        yield ChoiceField::new('systemRole', 'Роль в системе')
            ->setChoices(VpnUser::SYSTEM_ROLES)
            ->hideOnIndex();
        yield AssociationField::new('systemUser', 'Связанный системный аккаунт')
            ->onlyOnDetail();
        yield TextareaField::new('notes', 'Заметки')
            ->setRequired(false)
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Создан')->hideOnForm();
    }

    #[AdminRoute('/{id}/profiles.json', name: 'personal_profiles')]
    public function personalProfiles(#[MapEntity(id: 'id')] VpnUser $vpnUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        $payload = $this->userManager->buildPersonalCepStore($vpnUser);
        $response = new JsonResponse($payload, Response::HTTP_OK, [
            'Content-Disposition' => sprintf('attachment; filename="profiles-%s.json"', preg_replace('/[^a-zA-Z0-9._-]/', '_', $vpnUser->getUsername()) ?: 'user'),
        ]);
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $response;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof VpnUser);
        $this->userManager->prepareAndSyncSystemAccount($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof VpnUser);
        $this->userManager->prepareAndSyncSystemAccount($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof VpnUser);
        $currentAdmin = $this->getUser();
        $this->userManager->cleanupSystemAccountOnDelete(
            $entityInstance,
            $currentAdmin instanceof User ? $currentAdmin : null,
        );
        parent::deleteEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    protected function validateCrudEntity(object $entity): array
    {
        \assert($entity instanceof VpnUser);

        return $this->userManager->validate($entity);
    }
}
