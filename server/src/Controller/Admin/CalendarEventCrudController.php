<?php

namespace App\Controller\Admin;

use App\Entity\CalendarEvent;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * События — просмотр и удаление. Время показано в UTC, как хранится. Удаление оставляет
 * «надгробие» с новой ревизией, чтобы событие пропало и с устройств.
 *
 * @extends AbstractCrudController<CalendarEvent>
 */
final class CalendarEventCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CalendarEvent::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Событие')
            ->setEntityLabelInPlural('События')
            ->setDefaultSort(['startAt' => 'DESC'])
            ->setSearchFields(['title', 'location', 'calendar.name']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT)->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('calendar')->add('startAt')->add('allDay');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.deleted = false');
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof CalendarEvent);
        $entityInstance->markDeleted(null);
        $entityManager->flush();
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Событие');
        yield AssociationField::new('calendar', 'Календарь');
        yield DateTimeField::new('startAt', 'Начало, UTC')->setFormat('dd.MM.yyyy HH:mm');
        yield DateTimeField::new('endAt', 'Конец, UTC')->setFormat('dd.MM.yyyy HH:mm');
        yield BooleanField::new('allDay', 'Весь день')->renderAsSwitch(false);
        yield TextField::new('location', 'Место');
        yield TextareaField::new('description', 'Описание')->onlyOnDetail();
        yield TextField::new('rrule', 'Повторение')->onlyOnDetail();
        yield AssociationField::new('updatedBy', 'Изменил')->hideOnIndex();
        yield DateTimeField::new('updatedAt', 'Изменено')->hideOnIndex();
        yield TextField::new('uuid', 'ID в API')->onlyOnDetail();
    }
}
