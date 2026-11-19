<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\VpnConnection;
use App\Security\RequiresFeature;
use App\Service\CepExportService;
use App\Service\FeatureManager;
use App\Service\MtuProbeService;
use App\Service\NodeRuntimeExporter;
use App\Service\VpnChainValidator;
use App\Service\VpnSubscriptionService;
use App\Repository\VpnSubscriptionRepository;
use App\Vpn\SubscriptionParser;
use App\Vpn\VpnProtocol;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Создание и настройка клиентских подключений всех поддерживаемых типов (Argon4, Transit,
 * VLESS, Hysteria 2/1, OpenConnect, OpenVPN, AmneziaWG, SSTP).
 *
 * @extends AbstractCrudController<VpnConnection>
 */
#[RequiresFeature(FeatureManager::VPN)]
#[AdminRoute(path: '/vpn-connection', name: 'vpn_connection')]
final class VpnConnectionCrudController extends AbstractCrudController
{
    use VpnCrudFormValidationTrait;

    public function __construct(
        private readonly VpnChainValidator $validator,
        private readonly NodeRuntimeExporter $runtimeExporter,
        private readonly CepExportService $cepExport,
        private readonly MtuProbeService $mtuProbe,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly VpnSubscriptionRepository $subscriptionRepository,
        private readonly VpnSubscriptionService $subscriptions,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return VpnConnection::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Подключение VPN/CEP')
            ->setEntityLabelInPlural('Подключения VPN/CEP')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['name', 'proto', 'server', 'uri'])
            ->overrideTemplates([
                'crud/edit' => 'admin/vpn_connection_edit.html.twig',
                'crud/new' => 'admin/vpn_connection_new.html.twig',
            ])
            ->setHelp(Crud::PAGE_INDEX, 'Все поддерживаемые типы подключений CEP и прокси с настройками Argon4 v4/v5, автоподстройкой MTU и поведенческих профилей.')
            ->setHelp(Crud::PAGE_NEW, 'Выберите тип протокола и заполните относящиеся к нему поля. Оставьте «Внешний MTU» пустым для автоподбора или нажмите «Подобрать MTU».')
            ->setHelp(Crud::PAGE_EDIT, 'После сохранения profiles.json и desired-state.json узла пересобираются автоматически.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $probeMtu = Action::new('probeMtu', 'Подобрать MTU', 'fa fa-gauge-high')
            ->linkToRoute('admin_vpn_connection_probe_mtu', fn (VpnConnection $conn): array => [
                'id' => $conn->getId(),
                'apply' => 1,
                'token' => $this->csrf->getToken('probe_mtu_' . $conn->getId())->getValue(),
            ])
            ->renderAsForm();

        $exportJson = Action::new('exportProfiles', 'Скачать profiles.json', 'fa fa-file-code')
            ->linkToRoute('admin_vpn_node_export_profiles')
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $probeMtu)
            ->add(Crud::PAGE_DETAIL, $probeMtu)
            ->add(Crud::PAGE_INDEX, $exportJson);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('proto', 'Протокол')->setChoices(array_flip(VpnProtocol::LABELS)))
            ->add('enabled')
            ->add('useArgon');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield FormField::addFieldset('Основное');
        yield TextField::new('name', 'Название');
        yield ChoiceField::new('proto', 'Протокол')
            ->setChoices(array_flip(VpnProtocol::LABELS))
            ->renderAsBadges([
                VpnProtocol::ARGON4 => 'primary',
                VpnProtocol::TRANSIT => 'warning',
                VpnProtocol::VLESS => 'success',
                VpnProtocol::HYSTERIA2 => 'info',
                VpnProtocol::HYSTERIA => 'info',
                VpnProtocol::OPENCONNECT => 'secondary',
                VpnProtocol::OPENVPN => 'secondary',
                VpnProtocol::AMNEZIAWG => 'dark',
                VpnProtocol::SSTP => 'secondary',
            ])
            ->onlyOnIndex();
        yield ChoiceField::new('proto', 'Тип подключения (протокол)')
            ->setChoices(VpnProtocol::CHOICES)
            ->hideOnIndex();
        yield BooleanField::new('enabled', 'Включено');
        yield TextField::new('targetSummary', 'Адрес / источник')->onlyOnIndex();

        $subscriptions = $this->subscriptionRepository->findOrdered();
        if ($subscriptions !== [] || $pageName === Crud::PAGE_DETAIL) {
            $subscriptionChoices = [];
            $serverChoices = [];
            foreach ($subscriptions as $s) {
                $subscriptionChoices[(string) $s] = $s->getExternalId();
                foreach ($s->getServers() as $server) {
                    if (SubscriptionParser::protocolOf($server['uri']) !== null) {
                        $serverChoices[(string) $s][$server['name']] = $server['name'];
                    }
                }
            }
            yield FormField::addFieldset('Сервер из подписки')
                ->setHelp('Выберите подписку и сервер — ссылка и протокол подставятся из подписки и будут обновляться вместе с ней (ручные правки URI перезапишет следующее обновление). Пусто — обычное подключение.');
            yield ChoiceField::new('subscriptionId', 'Подписка')
                ->setChoices($subscriptionChoices)
                ->setRequired(false)
                ->hideOnIndex();
            yield ChoiceField::new('subscriptionServer', 'Сервер подписки')
                ->setChoices($serverChoices)
                ->setRequired(false)
                ->hideOnIndex();
        }
        yield TextField::new('externalId', 'UUID профиля')->onlyOnDetail();

        yield FormField::addFieldset('Параметры подключения')
            ->setHelp('Argon4 / Transit / OpenConnect / SSTP используют поле «Сервер». VLESS / Hysteria — поле «URI». OpenVPN / AmneziaWG — поле «Конфиг».');
        yield TextField::new('server', 'Сервер (host:port или URL)')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Для Argon4 и Transit: host:port. Для OpenConnect: https://host:port/. Для SSTP: host:port.');
        yield TextareaField::new('uri', 'URI (vless://, hysteria2://, hysteria://)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextareaField::new('config', 'Текст конфигурации (.ovpn / AmneziaWG)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('username', 'Логин (OpenConnect / OpenVPN / SSTP)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('password', 'Пароль / Мастер-пароль Argon4')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Обязателен для Argon4; для Argon4 Transit: если пусто — работает как слепой узел без расшифровки.');
        yield TextField::new('clientId', 'Client ID')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('clientSecret', 'Client Secret')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('OpenConnect / TLS и маршруты');
        yield ChoiceField::new('openconnectFlavor', 'Диалект OpenConnect')
            ->setChoices(VpnProtocol::OPENCONNECT_FLAVORS)
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('authGroup', 'Группа авторизации (authgroup)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('serverCert', 'Pin сертификата сервера (SHA-256)')
            ->setRequired(false)
            ->hideOnIndex();
        yield BooleanField::new('ignoreCertErrors', 'Игнорировать ошибки TLS-сертификата')
            ->hideOnIndex();
        yield TextareaField::new('routes', 'Маршруты (IPv4 или CIDR, через запятую/строки)')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('Обфускация Argon4')
            ->setHelp('Для Argon4 и Transit обфускация — сам протокол. Для остальных протоколов включите «Обернуть в Argon4»: клиент шифрует трафик Argon4 и шлёт его на порт обёртки узла, узел расшифровывает и передаёт в службу как обычный. На сервере у службы должна быть включена «Обёртка Argon4» с тем же портом и паролем.');
        yield BooleanField::new('useArgon', 'Обернуть в Argon4');
        yield IntegerField::new('argonPort', 'Порт обёртки Argon4 на узле')
            ->setRequired(false)
            ->setHelp('Пусто — 51820. Совпадает с портом обёртки у службы на сервере (или с портом пропуска, если перед узлом стоит слепой транзит).')
            ->hideOnIndex();
        yield TextField::new('argonPassword', 'Мастер-пароль обёртки Argon4')
            ->setRequired(false)
            ->hideOnIndex();
        yield FormField::addFieldset('Параметры Argon4 v4/v5')->collapsible();
        yield ChoiceField::new('argonProtocolVersion', 'Версия Argon4')
            ->setChoices([
                'v5 (переменный padding и профили трафика)' => 5,
                'v4 (legacy-совместимость)' => 4,
            ])
            ->setRequired(false)
            ->hideOnIndex();
        yield ChoiceField::new('argonBehaviorProfile', 'Поведенческий профиль')
            ->setChoices(VpnProtocol::BEHAVIOR_PROFILES)
            ->setRequired(false)
            ->hideOnIndex();
        yield ChoiceField::new('argonPaddingProfile', 'Профиль padding v5')
            ->setChoices(VpnProtocol::PADDING_PROFILES)
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('argonFirstPacketProfile', 'Профиль первого пакета')
            ->setRequired(false)
            ->hideOnIndex();
        yield IntegerField::new('argonPathMtu', 'Внешний MTU (пусто — автоподбор)')
            ->setRequired(false)
            ->setHelp('Оставьте пустым для автоматического подбора оптимального MTU при подключении (PLPMTUD / DF / MSS), или нажмите «Подобрать MTU».')
            ->formatValue(static fn (?int $value): string => $value !== null ? sprintf('%d Б', $value) : 'Авто');
        yield BooleanField::new('argonLowPowerMode', 'Энергосберегающий режим (low-power)')
            ->hideOnIndex();
        yield IntegerField::new('argonVideoCallMaxDurationSec', 'Макс. длительность video-call (сек)')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('Тайминги Argon4 и заметки')->collapsible();
        yield IntegerField::new('argonHandshakeMaxAttempts', 'Попыток рукопожатия')
            ->setRequired(false)
            ->hideOnIndex();
        yield IntegerField::new('argonHandshakeInitialTimeoutMs', 'Начальный таймаут рукопожатия (мс)')
            ->setRequired(false)
            ->hideOnIndex();
        yield IntegerField::new('argonHandshakeMaxTimeoutMs', 'Макс. таймаут рукопожатия (мс)')
            ->setRequired(false)
            ->hideOnIndex();
        yield IntegerField::new('argonKeepaliveMinIdleSec', 'Min idle keepalive (сек)')
            ->setRequired(false)
            ->hideOnIndex();
        yield IntegerField::new('argonKeepaliveMaxIdleSec', 'Max idle keepalive (сек)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextareaField::new('notes', 'Заметки')
            ->setRequired(false)
            ->hideOnIndex();

        if ($pageName === Crud::PAGE_DETAIL) {
            yield TextField::new('externalId', 'Ссылка / Share URI')
                ->formatValue(fn ($value, VpnConnection $entity): string => $this->cepExport->buildShareLink($entity) ?? '—');
        }

        yield DateTimeField::new('updatedAt', 'Обновлено')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::deleteEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    protected function validateCrudEntity(object $entity): array
    {
        \assert($entity instanceof VpnConnection);
        $subscriptionError = $this->applySubscriptionServer($entity);
        if ($subscriptionError !== null) {
            return [$subscriptionError];
        }

        return $this->validator->validateConnection($entity);
    }

    /** Подключение из подписки получает ссылку и протокол выбранного сервера до проверки и сохранения. */
    private function applySubscriptionServer(VpnConnection $conn): ?string
    {
        $subscription = $this->subscriptionRepository->findOneByExternalId($conn->getSubscriptionId());
        if ($conn->getSubscriptionId() === null) {
            return null;
        }
        if ($subscription === null) {
            return 'Подписка не найдена — выберите другую или очистите поле «Подписка».';
        }
        $server = $conn->getSubscriptionServer();
        if ($server === null) {
            return 'Выберите сервер подписки.';
        }
        if (!$this->subscriptions->applyServer($conn, $subscription, $server)) {
            return sprintf('Сервера «%s» нет в подписке «%s» — обновите подписку или выберите другой сервер.', $server, $subscription);
        }

        return null;
    }

    #[AdminRoute('/{id}/probe-mtu', name: 'probe_mtu', options: ['methods' => ['POST']])]
    public function probeMtu(
        #[MapEntity(id: 'id')] VpnConnection $conn,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        $token = (string) ($request->query->get('token')
            ?? $request->headers->get('X-CSRF-Token')
            ?? $request->request->get('_token', ''));
        if (!$this->isCsrfTokenValid('probe_mtu_' . $conn->getId(), $token)) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }

        $result = $this->mtuProbe->probeConnection($conn);
        $apply = $request->query->getBoolean('apply', true);
        if (($result['ok'] ?? false) && $apply && ($result['optimal_path_mtu'] ?? 0) >= MtuProbeService::MIN_PATH_MTU) {
            $conn->setArgonPathMtu((int) $result['optimal_path_mtu']);
            $em->flush();
            $this->runtimeExporter->sync();
        }

        $wantsJson = $request->isXmlHttpRequest()
            || $request->query->getBoolean('json')
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
        if ($wantsJson) {
            return new JsonResponse($result, ($result['ok'] ?? false) ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($result['ok'] ?? false) {
            $this->addFlash('success', sprintf('Узел «%s»: %s', $conn->getName(), (string) $result['message']));
        } else {
            $this->addFlash('danger', sprintf('Не удалось подобрать MTU для «%s»: %s', $conn->getName(), (string) ($result['error'] ?? 'неизвестная ошибка')));
        }

        return $this->redirectToRoute('admin_vpn_connection_index');
    }

    #[AdminRoute('/probe-mtu-preview', name: 'probe_mtu_preview', options: ['methods' => ['POST']])]
    public function probeMtuPreview(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        $raw = (string) $request->getContent();
        try {
            $payload = json_decode($raw !== '' ? $raw : '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['ok' => false, 'error' => 'Некорректный JSON-запрос.'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false, 'error' => 'Ожидался JSON-объект параметров узла.'], Response::HTTP_BAD_REQUEST);
        }

        $token = (string) ($request->headers->get('X-CSRF-Token') ?? ($payload['_token'] ?? ''));
        if (!$this->isCsrfTokenValid('probe_mtu_preview', $token)) {
            return new JsonResponse(['ok' => false, 'error' => 'Неверный CSRF-токен.'], Response::HTTP_FORBIDDEN);
        }

        $connId = isset($payload['connectionId']) && is_numeric($payload['connectionId'])
            ? (int) $payload['connectionId']
            : null;
        $result = $this->mtuProbe->probeRawInput($payload, $connId);

        return new JsonResponse($result);
    }
}
