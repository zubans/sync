<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\VpnSubscription;
use App\Security\RequiresFeature;
use App\Service\FeatureManager;
use App\Service\NodeRuntimeExporter;
use App\Service\VpnSubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Подписки провайдеров: адрес подписки, загрузка списка серверов и подключения с выбранным сервером.
 *
 * @extends AbstractCrudController<VpnSubscription>
 */
#[RequiresFeature(FeatureManager::VPN)]
#[AdminRoute(path: '/vpn-subscription', name: 'vpn_subscription')]
final class VpnSubscriptionCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly VpnSubscriptionService $subscriptions,
        private readonly NodeRuntimeExporter $runtimeExporter,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AdminUrlGenerator $urls,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return VpnSubscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Подписка провайдера')
            ->setEntityLabelInPlural('Подписки провайдеров')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'url'])
            ->overrideTemplate('crud/detail', 'admin/vpn_subscription_detail.html.twig')
            ->setHelp(Crud::PAGE_INDEX, 'Подписка — адрес, по которому провайдер отдаёт список серверов. Откройте подписку, чтобы создать подключение с нужным сервером; при обновлении подписки подключения получают свежие ссылки.')
            ->setHelp(Crud::PAGE_NEW, 'После сохранения узел сразу скачает список серверов.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $refresh = Action::new('refresh', 'Обновить', 'fa fa-rotate')
            ->linkToRoute('admin_vpn_subscription_refresh', fn (VpnSubscription $s): array => [
                'id' => $s->getId(),
                'token' => $this->csrf->getToken('subscription_refresh_' . $s->getId())->getValue(),
            ])
            ->renderAsForm();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $refresh)
            ->add(Crud::PAGE_DETAIL, $refresh);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Название')
            ->setRequired(false)
            ->setHelp('Пусто — имя из ответа панели (заголовок profile-title).');
        yield UrlField::new('url', 'Адрес подписки')
            ->hideOnIndex();
        yield TextField::new('hwid', 'HWID')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp(sprintf(
                'Идентификатор устройства для панелей с лимитом устройств. Пусто — HWID этого узла (%s): он займёт новое место в лимите. Чтобы не занимать место, впишите HWID уже зарегистрированного устройства.',
                $this->subscriptions->deviceHwid(),
            ));
        yield IntegerField::new('serverCount', 'Серверов')->hideOnForm();
        yield TextField::new('info', 'Трафик и срок')->hideOnForm();
        yield DateTimeField::new('refreshedAt', 'Обновлена')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof VpnSubscription);
        if ($entityInstance->getName() === '') {
            $entityInstance->setName((string) parse_url($entityInstance->getUrl(), PHP_URL_HOST));
        }
        parent::persistEntity($entityManager, $entityInstance);
        $this->refreshWithFlash($entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof VpnSubscription);
        $this->subscriptions->detachConnections($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    #[AdminRoute('/{id}/refresh', name: 'refresh', options: ['methods' => ['POST']])]
    public function refresh(#[MapEntity(id: 'id')] VpnSubscription $subscription, Request $request): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        if (!$this->isCsrfTokenValid('subscription_refresh_' . $subscription->getId(), (string) ($request->query->get('token') ?? $request->request->get('_token', '')))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }
        $this->refreshWithFlash($subscription);

        return $this->redirect($this->detailUrl($subscription));
    }

    #[AdminRoute('/{id}/connection', name: 'create_connection', options: ['methods' => ['POST']])]
    public function createConnection(#[MapEntity(id: 'id')] VpnSubscription $subscription, Request $request): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        if (!$this->isCsrfTokenValid('subscription_connection_' . $subscription->getId(), (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }
        try {
            $conn = $this->subscriptions->createConnection($subscription, (string) $request->request->get('server', ''));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', 'Подключение не создано: ' . $e->getMessage());

            return $this->redirect($this->detailUrl($subscription));
        }
        $this->runtimeExporter->sync();
        $this->addFlash('success', sprintf('Создано подключение «%s». Его можно поставить в цепочку; сервер меняется в настройках подключения.', $conn->getName()));

        return $this->redirect($this->urls->unsetAll()
            ->setController(VpnConnectionCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($conn->getId())
            ->generateUrl());
    }

    private function refreshWithFlash(VpnSubscription $subscription): void
    {
        try {
            $updated = $this->subscriptions->refresh($subscription);
            $this->runtimeExporter->sync();
            $this->addFlash('success', sprintf(
                'Подписка «%s» обновлена: серверов %d, обновлено подключений %d.',
                $subscription->getName(), $subscription->getServerCount(), $updated,
            ));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', sprintf('Подписка «%s»: %s', $subscription->getName(), $e->getMessage()));
        }
    }

    private function detailUrl(VpnSubscription $subscription): string
    {
        return $this->urls->unsetAll()
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($subscription->getId())
            ->generateUrl();
    }
}
