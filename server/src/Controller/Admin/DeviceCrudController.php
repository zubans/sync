<?php

namespace App\Controller\Admin;

use App\Entity\Device;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Устройства создаются только клиентом при синхронизации; в админке — просмотр и удаление.
 *
 * @extends AbstractCrudController<Device>
 */
final class DeviceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Device::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Устройство')
            ->setEntityLabelInPlural('Устройства')
            ->setDefaultSort(['lastSyncAt' => 'DESC'])
            ->setSearchFields(['model', 'installId', 'user.email']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT)->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('user');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user', 'Пользователь');
        yield TextField::new('model', 'Модель');
        yield TextField::new('installId', 'ID установки');
        yield DateTimeField::new('lastSyncAt', 'Последняя синхронизация');
        yield DateTimeField::new('createdAt', 'Первая синхронизация');
    }
}
