<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\User;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NullFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

/**
 * Личные контакты пользователей.
 *
 * @extends AbstractCrudController<Contact>
 */
final class ContactCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Contact::class;
    }

    public static function indexUrlForUser(AdminUrlGeneratorInterface $urls, User $user): string
    {
        return $urls->unsetAll()
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set('filters', ['user' => ['comparison' => '=', 'value' => $user->getId()]])
            ->generateUrl();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Личный контакт')
            ->setEntityLabelInPlural('Личные контакты')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'user.email'])
            ->setPaginatorPageSize(50)
            ->setHelp(
                Crud::PAGE_INDEX,
                'Контакт, удалённый на телефоне, остаётся здесь с отметкой «Удалён на устройстве». '
                .'Удаление здесь — окончательное: контакт удалится и с телефонов пользователя при их следующей синхронизации.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('user')
            ->add(NullFilter::new('deletedAt', 'Удалён на устройстве')->setChoiceLabels('Нет', 'Да'))
            ->add('updatedAt');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.user IS NOT NULL');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Имя');
        yield ArrayField::new('phones', 'Телефоны')->setRequired(false);
        yield ArrayField::new('emails', 'Email')->setRequired(false);
        yield AssociationField::new('user', 'Владелец')->setRequired(true);
        yield DateTimeField::new('updatedAt', 'Изменён')->hideOnForm();
        // Строковое свойство + formatValue: так вместо бейджа «Null» у живых контактов выводится «—».
        yield TextField::new('uuid', 'Удалён на устройстве')
            ->formatValue(static fn ($value, Contact $contact) => $contact->getDeletedAt()?->format('d.m.Y H:i') ?? '—')
            ->setSortable(false)
            ->hideOnForm();
        yield TextField::new('uuid', 'UUID')->onlyOnDetail();
    }
}
