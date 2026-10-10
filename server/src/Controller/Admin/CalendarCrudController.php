<?php

namespace App\Controller\Admin;

use App\Entity\Calendar;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Календари создают пользователи (приложение, API); в админке — просмотр, переименование,
 * цвет и отметка «семья». Удаление календаря удаляет и его события.
 *
 * @extends AbstractCrudController<Calendar>
 */
final class CalendarCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Calendar::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Календарь')
            ->setEntityLabelInPlural('Календари')
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(['name', 'owner.email']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW)->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('owner')->add('familyShared');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Название');
        yield ColorField::new('color', 'Цвет');
        yield AssociationField::new('owner', 'Владелец')->setFormTypeOption('disabled', true);
        yield BooleanField::new('familyShared', 'Семья')->renderAsSwitch(false);
        yield IntegerField::new('revision', 'Изменений')->hideOnForm();
        yield TextField::new('uuid', 'ID в API')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Создан')->hideOnForm();
    }
}
