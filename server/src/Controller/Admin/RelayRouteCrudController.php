<?php

namespace App\Controller\Admin;

use App\Entity\PortForwardRule;
use App\Entity\RelayRoute;
use App\Security\RequiresFeature;
use App\Security\RequiresRelayMode;
use App\Service\FeatureManager;
use App\Service\NodeRuntimeExporter;
use App\Service\PortForwardValidator;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Правила пропуска трафика через узел на цепочке: входной порт узла → следующий узел, без расшифровки.
 *
 * @extends AbstractCrudController<RelayRoute>
 */
#[RequiresFeature(FeatureManager::VPN)]
#[RequiresRelayMode('Пропуск трафика доступен только в ролях «Узел на цепочке» и «VPN-сервер»')]
#[AdminRoute(path: '/relay-route', name: 'relay_route')]
final class RelayRouteCrudController extends AbstractCrudController
{
    use VpnCrudFormValidationTrait;

    public function __construct(
        private readonly PortForwardValidator $validator,
        private readonly NodeRuntimeExporter $runtimeExporter,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return RelayRoute::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Пропуск трафика')
            ->setEntityLabelInPlural('Пропуск трафика через узел')
            ->setDefaultSort(['listenPort' => 'ASC'])
            ->setSearchFields(['name', 'targetHost', 'comment'])
            ->setHelp(Crud::PAGE_INDEX, 'Узел принимает трафик на своём порту и передаёт его следующему узлу цепочки, ничего не расшифровывая. В клиентской цепочке такой узел добавляется подключением типа «Argon4 Transit» без пароля (для слепого Argon4) или указывается адресом следующего узла (для DNAT).')
            ->setHelp(Crud::PAGE_NEW, 'Слепой Argon4 — когда следующий узел принимает Argon4 (служба Argon4 или обёртка Argon4 у службы); слушает UDP и TCP на одном порту. DNAT — для любого другого протокола.')
            ->setHelp(Crud::PAGE_EDIT, 'Порт следующего узла — это порт Argon4 (или обёртки Argon4) на нём, а не порт внутренней службы.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $ports = Action::new('nodePorts', 'Порты узла', 'fa fa-door-open')
            ->linkToRoute('admin_vpn_node_index')
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $ports);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield FormField::addFieldset('Что пропускаем');
        yield TextField::new('name', 'Название');
        yield BooleanField::new('enabled', 'Включено');
        yield ChoiceField::new('method', 'Способ')
            ->setChoices(RelayRoute::METHODS)
            ->renderAsBadges([
                RelayRoute::METHOD_ARGON_BLIND => 'primary',
                RelayRoute::METHOD_DNAT => 'secondary',
            ]);

        yield FormField::addFieldset('Вход на этом узле');
        yield IntegerField::new('listenPort', 'Входной порт');
        yield ChoiceField::new('transportProto', 'Транспорт (для DNAT)')
            ->setChoices(PortForwardRule::PROTOCOLS)
            ->setHelp('Слепой Argon4 всегда слушает UDP и TCP на одном порту — выбор здесь на него не влияет.')
            ->hideOnIndex();
        yield TextField::new('sourceCidr', 'Пускать только с IP/CIDR (только DNAT)')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addFieldset('Следующий узел');
        yield TextField::new('targetHost', 'Адрес следующего узла')
            ->setHelp('Для DNAT — IPv4. Для слепого Argon4 можно домен.')
            ->hideOnIndex();
        yield IntegerField::new('targetPort', 'Порт на следующем узле')
            ->setHelp('Порт Argon4 или обёртки Argon4 на следующем узле (по умолчанию 51820).')
            ->hideOnIndex();
        yield TextField::new('targetAddress', 'Куда')->onlyOnIndex();
        yield IntegerField::new('argonPathMtu', 'Внешний MTU (0 — автоподбор, только слепой Argon4)')
            ->setRequired(false)
            ->hideOnIndex();

        yield TextareaField::new('comment', 'Комментарий')
            ->setRequired(false)
            ->hideOnIndex();
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
        \assert($entity instanceof RelayRoute);

        return $this->validator->validateRelayRoute($entity);
    }
}
