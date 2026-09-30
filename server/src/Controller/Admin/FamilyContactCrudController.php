<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\Family;
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
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Общие контакты семей: контакты членов семьи с отметкой «Семья». Контакт принадлежит владельцу;
 * «Убрать из семьи» снимает отметку: контакт остаётся у владельца и перестаёт приходить на новые устройства,
 * с телефонов ничего не удаляется.
 *
 * @extends AbstractCrudController<Contact>
 */
final class FamilyContactCrudController extends AbstractCrudController
{
    use ManagesContacts;

    public function __construct(private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Contact::class;
    }

    public static function indexUrlForFamily(AdminUrlGeneratorInterface $urls, Family $family): string
    {
        return $urls->unsetAll()
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set('filters', ['family' => ['comparison' => '=', 'value' => $family->getId()]])
            ->generateUrl();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Семейный контакт')
            ->setEntityLabelInPlural('Семейные контакты')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'family.name', 'user.email'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, 'Общие контакты семьи: ставятся на новые устройства членов семьи. Каждый принадлежит владельцу — члену семьи. «Убрать из семьи» — контакт перестанет приходить на новые устройства, с телефонов ничего не удаляется.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $unshare = ContactCrudController::sharingAction('unshare', 'Убрать из семьи', 'fa fa-user', $this->csrf);

        return self::trashLabels($actions)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $unshare)
            ->add(Crud::PAGE_DETAIL, $unshare)
            ->add(Crud::PAGE_EDIT, $unshare);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('family')->add('user');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.family IS NOT NULL')
            ->andWhere('entity.deletedAt IS NULL');
    }

    public function configureFields(string $pageName): iterable
    {
        yield ContactFields::photo(fn (string $sha) => $this->generateUrl('admin_contact_photo', ['sha256' => $sha]), $pageName);
        yield TextField::new('name', 'Имя');
        yield ArrayField::new('phones', 'Телефоны')->setRequired(false);
        yield ArrayField::new('emails', 'Email')->setRequired(false);
        yield from ContactFields::birthday();
        yield AssociationField::new('user', 'Владелец')
            ->setRequired(true)
            ->setHelp('Член семьи, у которого контакт лежит как личный.');
        // Без пустого варианта: здесь только общие контакты; убрать из семьи — действием «Убрать из семьи».
        yield AssociationField::new('family', 'Семья')
            ->setRequired(true)
            ->setFormTypeOption('placeholder', false)
            ->setHelp('Чтобы убрать контакт из семьи, используйте «Убрать из семьи» — контакт останется у владельца, с телефонов ничего не удалится.');
        yield DateTimeField::new('updatedAt', 'Изменён')->hideOnForm();
    }
}
