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
            ->overrideTemplates([
                'crud/edit' => 'admin/vpn_server_service_edit.html.twig',
                'crud/new' => 'admin/vpn_server_service_new.html.twig',
            ])
            ->setHelp(Crud::PAGE_INDEX, 'Службы, которые узел поднимает и расшифровывает. Любую службу можно сделать доступной и через обфускацию Argon4 — блок «Приём обфусцированного трафика». Кнопка «Создать клиентский профиль» генерирует готовое подключение для CEP.');
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
        $argon = 'js-proto-section js-proto-argon4';
        $transit = 'js-proto-section js-proto-transit';
        $wrapped = 'js-proto-section ' . implode(' ', array_map(
            static fn (string $p): string => 'js-proto-' . $p,
            array_values(array_filter(VpnProtocol::ALL, VpnProtocol::supportsArgonUnderlay(...))),
        ));

        yield IdField::new('id')->hideOnForm();

        yield FormField::addFieldset('Служба');
        yield TextField::new('name', 'Название');
        yield ChoiceField::new('proto', 'Протокол')
            ->setChoices(array_flip(VpnProtocol::LABELS))
            ->onlyOnIndex();
        yield ChoiceField::new('proto', 'Протокол службы')
            ->setChoices(VpnProtocol::CHOICES)
            ->hideOnIndex();
        yield BooleanField::new('enabled', 'Включена');

        yield FormField::addFieldset('Где служба принимает подключения')
            ->setHelp('Этот порт нужно открыть снаружи: в firewall хоста и, если узел за роутером, пробросом на роутере. Сводка — «Порты узла» на странице узла.');
        yield TextField::new('listenHost', 'Listen IP')
            ->setHelp('0.0.0.0 — на всех интерфейсах.')
            ->hideOnIndex();
        yield IntegerField::new('listenPort', 'Порт');
        yield ChoiceField::new('transportProto', 'Транспорт')
            ->setChoices(['UDP' => 'udp', 'TCP' => 'tcp', 'TCP и UDP' => 'both']);
        yield TextField::new('publicHostOverride', 'Внешний хост/IP для клиентов (если не как у узла)')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('Приём обфусцированного трафика (обёртка Argon4)')
            ->setHelp('Служба остаётся доступной как обычно, а дополнительно узел слушает отдельный порт Argon4: принимает зашифрованный и обфусцированный трафик, расшифровывает его и передаёт в эту службу как обычное подключение. Клиенту нужно включить «Обернуть в Argon4» с тем же портом и паролем — профиль, созданный кнопкой «Создать клиентский профиль», настроится сам.')
            ->addCssClass($wrapped);
        yield BooleanField::new('argonWrapEnabled', 'Принимать трафик, обёрнутый в Argon4')
            ->hideOnIndex();
        yield IntegerField::new('argonWrapPort', 'Порт обёртки Argon4 (UDP и TCP)')
            ->setRequired(false)
            ->setHelp('Пусто — 51820. Должен отличаться от порта службы и от других служб узла.')
            ->hideOnIndex();
        yield TextField::new('argonWrapPassword', 'Мастер-пароль обёртки')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('argonWrapSummary', 'Обёртка Argon4')
            ->setVirtual(true)
            ->formatValue(static fn ($value, VpnServerService $s): string => $s->hasArgonWrap()
                ? sprintf('порт %d', $s->getArgonWrapPort() ?? VpnProtocol::ARGON_DEFAULT_PORT)
                : '—')
            ->onlyOnIndex();

        yield FormField::addFieldset('Доступ')
            ->addCssClass('js-proto-section js-proto-argon4 js-proto-transit js-proto-hysteria2 js-proto-hysteria');
        yield TextField::new('masterPassword', 'Мастер-пароль (Argon4, L4-транзит, Hysteria)')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('Туннель Argon4 (расшифровка и выход в интернет)')
            ->addCssClass($argon);
        yield TextField::new('subnetCidr', 'Внутренняя подсеть клиентов (CIDR)')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('Транзит Argon4')
            ->setHelp('Служба пропускает трафик к другому узлу. Чтобы узел просто пропускал трафик дальше по цепочке без расшифровки, удобнее раздел «Пропуск трафика».')
            ->addCssClass($transit);
        yield BooleanField::new('transitBlind', 'Слепой транзит (без расшифровки и без пароля)')
            ->hideOnIndex();
        yield TextField::new('transitTarget', 'Фиксированная цель (host:port)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('transitAllow', 'Разрешённые цели L4-транзита (CIDR / host:port через запятую)')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('Параметры Argon4 v4/v5')
            ->setHelp('Действуют на службу Argon4, транзит и обёртку Argon4.')
            ->addCssClass('js-proto-section js-proto-argon4 js-proto-transit js-proto-wrap')
            ->collapsible();
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

        yield FormField::addFieldset('VLESS Reality / TLS')
            ->addCssClass('js-proto-section js-proto-vless js-proto-hysteria2');
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

        yield FormField::addFieldset('Hysteria 2')
            ->addCssClass('js-proto-section js-proto-hysteria2');
        yield TextField::new('obfsPassword', 'Пароль Salamander')
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
