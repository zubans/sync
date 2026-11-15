<?php

namespace App\Controller\Admin;

use App\Entity\VpnChain;
use App\Security\RequiresFeature;
use App\Service\FeatureManager;
use App\Service\MtuProbeService;
use App\Service\NodeRuntimeExporter;
use App\Service\VpnChainValidator;
use App\Service\VpnNodeSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Многохоповые цепочки CEP (например: Слепой Transit → L4 Transit → Argon4 v5 video-call)
 * с валидацией совместимости промежуточных узлов и выбором активной цепочки устройства.
 *
 * @extends AbstractCrudController<VpnChain>
 */
#[RequiresFeature(FeatureManager::VPN)]
#[AdminRoute(path: '/vpn-chain', name: 'vpn_chain')]
final class VpnChainCrudController extends AbstractCrudController
{
    use VpnCrudFormValidationTrait;

    public function __construct(
        private readonly VpnChainValidator $validator,
        private readonly VpnNodeSettingsService $settingsService,
        private readonly NodeRuntimeExporter $runtimeExporter,
        private readonly MtuProbeService $mtuProbe,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return VpnChain::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Цепочка узлов')
            ->setEntityLabelInPlural('Цепочки узлов')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['name', 'description'])
            ->setHelp(Crud::PAGE_INDEX, 'Собранные цепочки экспортируются в profiles.json; слепые транзитные узлы прозрачно передают Argon4 v5 без доступа к содержимому.')
            ->setHelp(Crud::PAGE_NEW, 'Добавьте шаги цепочки по порядку (Шаг №1 → Шаг №2 → ...). Последним узлом должен быть выходной узел, а не Transit.')
            ->setHelp(Crud::PAGE_EDIT, 'Валидатор проверяет совместимость промежуточных транзитов, вложенного MTU и video-call поверх UDP.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $activate = Action::new('activate', 'Сделать активной', 'fa fa-bolt')
            ->linkToRoute('admin_vpn_chain_activate', fn (VpnChain $chain): array => [
                'id' => $chain->getId(),
                'token' => $this->csrf->getToken('activate_chain_' . $chain->getId())->getValue(),
            ])
            ->renderAsForm()
            ->displayIf(fn (VpnChain $chain): bool => $chain->isEnabled() && $this->settingsService->getSettings()->getActiveClientChainId() !== $chain->getId());

        $exportJson = Action::new('exportProfiles', 'Скачать profiles.json', 'fa fa-file-code')
            ->linkToRoute('admin_vpn_node_export_profiles')
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $activate)
            ->add(Crud::PAGE_DETAIL, $activate)
            ->add(Crud::PAGE_INDEX, $exportJson);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Название цепочки');
        yield BooleanField::new('enabled', 'Включена');
        yield TextField::new('hopsSummary', 'Маршрут узлов')->hideOnForm();
        yield TextField::new('chainMtuSummary', 'MTU и бюджет цепочки')
            ->setVirtual(true)
            ->formatValue(fn ($value, VpnChain $entity): string => $this->mtuProbe->analyzeChainMtu($entity)['summary'])
            ->hideOnForm();
        yield BooleanField::new('active', 'Активна на узле')
            ->setVirtual(true)
            ->renderAsSwitch(false)
            ->formatValue(fn ($value, VpnChain $entity): bool => $this->settingsService->getSettings()->getActiveClientChainId() === $entity->getId())
            ->hideOnForm();
        yield CollectionField::new('hops', 'Шаги цепочки (по порядку)')
            ->setEntryType(VpnChainHopType::class)
            ->allowAdd()
            ->allowDelete()
            ->renderExpanded()
            ->setFormTypeOption('by_reference', false)
            ->onlyOnForms();
        yield TextareaField::new('description', 'Описание')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('externalId', 'UUID цепочки')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Обновлено')->hideOnForm();
    }

    #[AdminRoute('/{id}/activate', name: 'activate', options: ['methods' => ['POST']])]
    public function activate(#[MapEntity(id: 'id')] VpnChain $chain, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(\App\Entity\User::ROLE_ADMIN);
        if (!$this->isCsrfTokenValid('activate_chain_' . $chain->getId(), (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }

        $errors = $this->validator->validateChain($chain);
        if ($errors !== []) {
            $this->addFlash('danger', 'Цепочка не активирована: ' . implode(' ', $errors));

            return $this->redirectToRoute('admin_vpn_chain_index');
        }

        $settings = $this->settingsService->getSettings();
        $settings->setActiveClientChainId($chain->getId());
        $em->flush();
        $this->runtimeExporter->sync();
        $this->addFlash('success', sprintf('Активной цепочкой узла выбрана «%s».', $chain->getName()));

        return $this->redirectToRoute('admin_vpn_chain_index');
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
        \assert($entityInstance instanceof VpnChain);
        $settings = $this->settingsService->getSettings();
        if ($settings->getActiveClientChainId() === $entityInstance->getId()) {
            $settings->setActiveClientChainId(null);
        }
        parent::deleteEntity($entityManager, $entityInstance);
        $this->runtimeExporter->sync();
    }

    protected function validateCrudEntity(object $entity): array
    {
        \assert($entity instanceof VpnChain);

        return $this->validator->validateChain($entity);
    }
}
