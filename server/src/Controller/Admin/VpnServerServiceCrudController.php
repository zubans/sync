<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\VpnServerService;
use App\Security\RequiresFeature;
use App\Security\RequiresServerMode;
use App\Service\FeatureManager;
use App\Service\CepExportService;
use App\Service\NodeRuntimeExporter;
use App\Service\PortForwardValidator;
use App\Service\VpnNodeSettingsService;
use App\Vpn\VpnProtocol;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Настройка серверных VPN/транзитных демонов, разворачиваемых на данном узле в режиме «VPN-сервер».
 *
 * @extends AbstractCrudController<VpnServerService>
 */
#[RequiresFeature(FeatureManager::VPN)]
#[RequiresServerMode('Управление серверными службами доступно только в режиме «Сервер»')]
#[AdminRoute(path: '/vpn-server-service', name: 'vpn_server_service')]
final class VpnServerServiceCrudController extends AbstractCrudController
{
    use VpnCrudFormValidationTrait;

    public function __construct(
        private readonly PortForwardValidator $validator,
        private readonly NodeRuntimeExporter $runtimeExporter,
        private readonly VpnNodeSettingsService $settingsService,
        private readonly CepExportService $cepExport,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return VpnServerService::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Серверный сервис VPN')
            ->setEntityLabelInPlural('Серверные службы VPN')
            ->setDefaultSort(['listenPort' => 'ASC'])
            ->setSearchFields(['name', 'proto', 'listenHost'])
            ->setHelp(Crud::PAGE_INDEX, 'Службы, поднимаемые на этом узле. Кнопка «Создать клиентский профиль» сразу генерирует готовое подключение для CEP.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $createClient = Action::new('createClientProfile', 'Создать клиентский профиль', 'fa fa-share-nodes')
            ->linkToRoute('admin_vpn_server_service_create_client', fn (VpnServerService $s): array => [
                'id' => $s->getId(),
                'token' => $this->csrf->getToken('svc_client_' . $s->getId())->getValue(),
            ])
            ->renderAsForm();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $createClient)
            ->add(Crud::PAGE_DETAIL, $createClient);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield FormField::addFieldset('Основное');
        yield TextField::new('name', 'Название');
        yield ChoiceField::new('proto', 'Протокол')
            ->setChoices(array_flip(VpnProtocol::LABELS))
            ->onlyOnIndex();
        yield ChoiceField::new('proto', 'Протокол сервиса')
            ->setChoices(VpnProtocol::CHOICES)
            ->hideOnIndex();
        yield BooleanField::new('enabled', 'Включён');
        yield TextField::new('listenHost', 'Listen IP')->hideOnIndex();
        yield IntegerField::new('listenPort', 'Порт');
        yield ChoiceField::new('transportProto', 'Транспорт')
            ->setChoices(['UDP' => 'udp', 'TCP' => 'tcp', 'TCP и UDP' => 'both']);
        yield TextField::new('publicHostOverride', 'Внешний хост/IP (переопределение)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('subnetCidr', 'Внутренняя подсеть (CIDR)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('masterPassword', 'Мастер-пароль (Argon4 / Hysteria)')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('Промежуточный транзит (если протокол Transit)');
        yield BooleanField::new('transitBlind', 'Слепой транзит (без расшифровки и без пароля)')
            ->hideOnIndex();
        yield TextField::new('transitTarget', 'Фиксированная цель транзита (host:port)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('transitAllow', 'Разрешённые цели транзита (CIDR / host:port через запятую)')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('Argon4 v4/v5');
        yield ChoiceField::new('argonProtocolVersion', 'Версия Argon4')
            ->setChoices(['v5' => 5, 'v4' => 4])
            ->hideOnIndex();
        yield BooleanField::new('argonDisallowV4', 'Запретить legacy v4')->hideOnIndex();
        yield BooleanField::new('argonAllowVideoCall', 'Разрешить профиль video-call')->hideOnIndex();
        yield ChoiceField::new('argonBehaviorProfile', 'Поведенческий профиль')
            ->setChoices(VpnProtocol::BEHAVIOR_PROFILES)
            ->setRequired(false)
            ->hideOnIndex();
        yield ChoiceField::new('argonPaddingProfile', 'Профиль padding')
            ->setChoices(VpnProtocol::PADDING_PROFILES)
            ->hideOnIndex();
        yield IntegerField::new('argonPathMtu', 'Внешний path_mtu (0 — автоподбор)')
            ->setHelp('0 — автоматический подбор оптимального MTU при запуске службы; 576..9000 — фиксированный лимит.')
            ->hideOnIndex();

        yield FormField::addFieldset('VLESS Reality / TLS / Salamander');
        yield TextField::new('tlsDomain', 'SNI / TLS домен')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('realityPrivateKey', 'VLESS Reality Private Key')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('realityPublicKey', 'VLESS Reality Public Key')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('realityShortId', 'VLESS Reality Short ID')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('obfsPassword', 'Hysteria2 Salamander пароль')
            ->setRequired(false)
            ->hideOnIndex();

        yield DateTimeField::new('updatedAt', 'Обновлено')->hideOnForm();
    }

    #[AdminRoute('/{id}/create-client-profile', name: 'create_client', options: ['methods' => ['POST']])]
    public function createClientProfile(
        #[MapEntity(id: 'id')] VpnServerService $service,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        if (!$this->isCsrfTokenValid('svc_client_' . $service->getId(), (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }

        $conn = $this->cepExport->createConnectionFromServerService($service, $this->settingsService->getSettings());
        $em->persist($conn);
        $em->flush();
        $this->runtimeExporter->sync();

        $this->addFlash('success', sprintf('Создан клиентский профиль «%s» в разделе «Подключения VPN/CEP».', $conn->getName()));

        return $this->redirectToRoute('admin_vpn_connection_index');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->syncRealityPublicKey($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->syncRealityPublicKey($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    private function syncRealityPublicKey(object $entity): void
    {
        if (!$entity instanceof VpnServerService || $entity->getProto() !== VpnProtocol::VLESS) {
            return;
        }
        $priv = $entity->getRealityPrivateKey();
        if ($priv === null) {
            return;
        }
        $derived = VpnProtocol::deriveX25519PublicKey($priv, true);
        if ($derived !== null && ($entity->getRealityPublicKey() === null || $entity->getRealityPublicKey() === '')) {
            $entity->setRealityPublicKey($derived);
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::deleteEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    protected function validateCrudEntity(object $entity): array
    {
        \assert($entity instanceof VpnServerService);

        return $this->validator->validateServerService($entity);
    }
}
