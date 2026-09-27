<?php

namespace App\Controller\Admin;

use App\Entity\Family;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

/**
 * @extends AbstractCrudController<Family>
 */
final class FamilyCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Family::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Семья')
            ->setEntityLabelInPlural('Семьи')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $contacts = Action::new('familyContacts', 'Контакты', 'fa fa-address-book')
            ->linkToUrl(fn (Family $family) => FamilyContactCrudController::indexUrlForFamily(
                $this->container->get(AdminUrlGeneratorInterface::class),
                $family,
            ));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $contacts)
            ->add(Crud::PAGE_DETAIL, $contacts);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Название');
        // Владеющая сторона — User::$family; by_reference=false заставляет форму вызывать addMember/removeMember.
        yield AssociationField::new('members', 'Участники')
            ->setFormTypeOption('by_reference', false)
            ->setHelp('Пользователь может состоять только в одной семье: при добавлении он уйдёт из прежней.');
        yield AssociationField::new('contacts', 'Контактов')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Создана')->hideOnForm();
    }
}
