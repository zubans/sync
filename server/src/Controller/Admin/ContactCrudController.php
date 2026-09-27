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
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('user')->add('updatedAt');
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
        yield TextField::new('uuid', 'UUID')->onlyOnDetail();
    }
}
