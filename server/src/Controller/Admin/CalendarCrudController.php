<?php

namespace App\Controller\Admin;

use App\Entity\Calendar;
use App\Entity\User;
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
 * Календари: создание (например, семейный «Зюбан»), переименование, цвет, отметка «семья» и «Поделиться с» —
 * открыть календарь выбранным членам семьи владельца. Удаление календаря удаляет и его события.
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
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    /** Владельца выбирают в форме; до этого — администратор, иначе календарь не создать. */
    public function createEntity(string $entityFqcn): Calendar
    {
        $admin = $this->getUser();
        \assert($admin instanceof User);

        return new Calendar($admin, '');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('owner')->add('familyShared');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Название');
        yield ColorField::new('color', 'Цвет');
        // Владельца выбирают при создании; у существующего календаря не меняется — события остались бы чужими.
        yield AssociationField::new('owner', 'Владелец')->setFormTypeOption('disabled', $pageName === Crud::PAGE_EDIT);
        yield BooleanField::new('familyShared', 'Семья')->renderAsSwitch(false)
            ->setHelp('Видят и правят все члены семьи владельца');
        yield AssociationField::new('sharedWith', 'Поделиться с')
            ->setFormTypeOption('by_reference', false)
            ->setHelp('Только члены семьи владельца: видят календарь на своих устройствах и правят события. Не нужно, если отмечено «Семья».');
        yield IntegerField::new('revision', 'Изменений')->hideOnForm();
        yield TextField::new('uuid', 'ID в API')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Создан')->hideOnForm();
    }
}
