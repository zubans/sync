<?php

namespace App\Controller\Admin;

use App\Entity\PortForwardRule;
use App\Security\RequiresFeature;
use App\Security\RequiresServerMode;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Правила проброса портов (DNAT/MASQUERADE) на узле в режиме «VPN-сервер».
 *
 * @extends AbstractCrudController<PortForwardRule>
 */
#[RequiresFeature(FeatureManager::VPN)]
#[RequiresServerMode('Правила проброса портов доступны только в режиме «Сервер»')]
#[AdminRoute(path: '/port-forward', name: 'port_forward')]
final class PortForwardRuleCrudController extends AbstractCrudController
{
    use VpnCrudFormValidationTrait;

    public function __construct(
        private readonly PortForwardValidator $validator,
        private readonly NodeRuntimeExporter $runtimeExporter,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return PortForwardRule::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Правило проброса портов')
            ->setEntityLabelInPlural('Проброс портов')
            ->setDefaultSort(['listenPort' => 'ASC'])
            ->setSearchFields(['name', 'targetIp', 'comment'])
            ->setHelp(Crud::PAGE_INDEX, 'При каждом сохранении формируется port-forward.sh (iptables SYNC_DNAT / SYNC_POST / SYNC_FWD). Конфликты с портами VPN-служб блокируются.')
            ->setHelp(Crud::PAGE_NEW, 'Укажите внешний порт (или диапазон) и целевой IP/порт подключённого клиента или локальной службы.')
            ->setHelp(Crud::PAGE_EDIT, 'При использовании диапазона портов начальный целевой порт должен совпадать с начальным входным (1:1 DNAT).');
    }

    public function configureActions(Actions $actions): Actions
    {
        $downloadScript = Action::new('downloadScript', 'Скрипт port-forward.sh', 'fa fa-terminal')
            ->linkToRoute('admin_vpn_node_export_port_forward')
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $downloadScript);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Название правила');
        yield BooleanField::new('enabled', 'Включено');
        yield ChoiceField::new('protocol', 'Протокол')
            ->setChoices(PortForwardRule::PROTOCOLS)
            ->renderAsBadges([
                PortForwardRule::PROTO_BOTH => 'primary',
                PortForwardRule::PROTO_TCP => 'info',
                PortForwardRule::PROTO_UDP => 'warning',
            ]);
        yield TextField::new('listenInterface', 'Входящий интерфейс (пусто — WAN узла)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('portRangeDisplay', 'Входные порты')->hideOnForm();
        yield IntegerField::new('listenPort', 'Входной порт (или начало диапазона)')
            ->onlyOnForms();
        yield IntegerField::new('listenPortEnd', 'Конец диапазона портов (необязательно)')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('targetIp', 'Целевой IPv4 (например 10.44.0.2)');
        yield IntegerField::new('targetPort', 'Целевой порт');
        yield TextField::new('sourceCidr', 'Разрешить только с IP/CIDR (необязательно)')
            ->setRequired(false)
            ->hideOnIndex();
        yield BooleanField::new('enableMasquerade', 'Включить MASQUERADE к цели');
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
        \assert($entity instanceof PortForwardRule);

        return $this->validator->validate($entity);
    }
}
